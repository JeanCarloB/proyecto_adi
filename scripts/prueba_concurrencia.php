<?php
/**
 * Prueba de concurrencia del módulo de alquileres (Issue #15).
 *
 * Un "proceso" de esta prueba: se conecta a Atlas, espera hasta un instante
 * acordado (para salir a la vez que el otro proceso) e intenta apartar el
 * MISMO espacio, día y franja. Lo lanza dos veces en paralelo
 * scripts/prueba_concurrencia.ps1.
 *
 * Esperado: en cada ronda, un proceso inserta y el otro recibe el error
 * 11000 (clave duplicada) del índice único {espacio_id, fecha, franja}.
 * La base, no el código PHP, impide el doble alquiler aunque lleguen juntos.
 *
 * Usa una fecha de prueba (2099-12-31, franja noche) que nadie reserva de
 * verdad, y la limpia al terminar.
 *
 * Uso directo (normalmente lo llama el .ps1):
 *   php scripts/prueba_concurrencia.php <nombre> <instante_ms>
 *   php scripts/prueba_concurrencia.php limpiar
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/includes/conexion.php';   // $baseDatos, desde el .env

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;

const FECHA_PRUEBA  = '2099-12-31';
const FRANJA_PRUEBA = 'noche';

$reservas = $baseDatos->selectCollection('reservas');
$dia = new UTCDateTime(new DateTimeImmutable(FECHA_PRUEBA . ' 00:00:00', new DateTimeZone('UTC')));

// Un espacio activo y un contacto cualquiera (solo se necesitan sus _id)
$espacio  = $baseDatos->selectCollection('espacios')->findOne(['activo' => true], ['sort' => ['nombre' => 1]]);
$contacto = $baseDatos->selectCollection('contactos')->findOne([], ['projection' => ['_id' => 1]]);
if (!$espacio || !$contacto) {
    fwrite(STDERR, "Faltan espacios activos o contactos en la base.\n");
    exit(1);
}
$llave = ['espacio_id' => $espacio['_id'], 'fecha' => $dia, 'franja' => FRANJA_PRUEBA];

if (($argv[1] ?? '') === 'limpiar') {
    $n = $reservas->deleteMany($llave)->getDeletedCount();
    echo "Limpieza: {$n} reserva(s) de prueba eliminada(s).\n";
    exit(0);
}

$nombre    = $argv[1] ?? 'A';
$instante  = (int) ($argv[2] ?? 0);

// 1. Abrir la conexión ANTES de la hora acordada, para que no cuente en la carrera
$baseDatos->command(['ping' => 1]);

// 2. Esperar hasta el instante acordado
$espera = $instante - (int) (microtime(true) * 1000);
if ($espera > 0) {
    usleep($espera * 1000);
}

// 3. Intentar apartar el mismo horario
$inicio = microtime(true);
try {
    $reservas->insertOne($llave + [
        'contacto_id' => $contacto['_id'],
        'estado'      => 'apartado',
        'monto'       => new Decimal128('1.00'),
        'vence'       => new UTCDateTime((time() + 600) * 1000),
    ]);
    $resultado = 'INSERTADA';
} catch (MongoDB\Driver\Exception\BulkWriteException $e) {
    $codigo = $e->getWriteResult()->getWriteErrors()[0]->getCode() ?? 0;
    $resultado = $codigo === 11000 ? 'RECHAZADA (11000, clave duplicada)' : "ERROR {$codigo}";
}
$ms = round((microtime(true) - $inicio) * 1000);

// Hora real de salida (respecto al instante acordado) para ver que salieron juntos
$desfase = round($inicio * 1000 - $instante);
printf("Proceso %s · salió %+d ms del instante acordado · %s · %d ms\n", $nombre, $desfase, $resultado, $ms);
