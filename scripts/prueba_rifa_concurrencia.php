<?php
/**
 * Prueba de concurrencia del módulo de ventas (Issue #35).
 *
 * Un "proceso" de esta prueba: se conecta a Atlas, espera hasta un instante
 * acordado (para salir a la vez que el otro proceso) e intenta vender el
 * MISMO número de la MISMA rifa. Lo lanza dos veces en paralelo
 * scripts/prueba_rifa_concurrencia.ps1.
 *
 * Esperado: en cada ronda, un proceso inserta y el otro recibe el error
 * 11000 (clave duplicada) del índice único {rifa_id, numero}. La base, no el
 * código PHP, impide vender dos veces el mismo número aunque dos voluntarios
 * lo vendan en el mismo instante.
 *
 * Usa una rifa de prueba propia ("Rifa de prueba de concurrencia", fecha
 * 31/12/2099) y la borra con todos sus números al terminar. No toca las
 * rifas reales.
 *
 * Uso directo (normalmente lo llama el .ps1):
 *   php scripts/prueba_rifa_concurrencia.php preparar
 *   php scripts/prueba_rifa_concurrencia.php <nombre> <instante_ms>
 *   php scripts/prueba_rifa_concurrencia.php limpiar
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/includes/conexion.php';   // $baseDatos, desde el .env

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;

const NOMBRE_RIFA   = 'Rifa de prueba de concurrencia';
const NUMERO_PRUEBA = 99;

$actividades = $baseDatos->selectCollection('actividades');
$rifas       = $baseDatos->selectCollection('rifas');
$orden       = $argv[1] ?? '';

$rifa = $actividades->findOne(['nombre' => NOMBRE_RIFA, 'tipo' => 'rifa']);

if ($orden === 'limpiar') {
    if ($rifa) {
        $n = $rifas->deleteMany(['rifa_id' => $rifa['_id']])->getDeletedCount();
        $actividades->deleteOne(['_id' => $rifa['_id']]);
        echo "Limpieza: rifa de prueba y {$n} número(s) eliminados.\n";
    } else {
        echo "Limpieza: no había rifa de prueba.\n";
    }
    exit(0);
}

if ($orden === 'preparar') {
    if (!$rifa) {
        // Cumple el JSON Schema de actividades (crear_colecciones_ventas.js)
        $actividades->insertOne([
            'nombre'        => NOMBRE_RIFA,
            'tipo'          => 'rifa',
            'fecha'         => new UTCDateTime(new DateTimeImmutable('2099-12-31 00:00:00', new DateTimeZone('UTC'))),
            'estado'        => 'programada',
            'precio_numero' => new Decimal128('1.00'),
        ]);
    } else {
        // Cada ronda empieza con el número libre
        $rifas->deleteMany(['rifa_id' => $rifa['_id']]);
    }
    exit(0);
}

$contacto = $baseDatos->selectCollection('contactos')->findOne([], ['projection' => ['_id' => 1]]);
if (!$rifa || !$contacto) {
    fwrite(STDERR, "Falta la rifa de prueba (corra 'preparar') o no hay contactos en la base.\n");
    exit(1);
}

$nombre   = $orden !== '' ? $orden : 'A';
$instante = (int) ($argv[2] ?? 0);

// 1. Abrir la conexión ANTES de la hora acordada, para que no cuente en la carrera
$baseDatos->command(['ping' => 1]);

// 2. Esperar hasta el instante acordado
$espera = $instante - (int) (microtime(true) * 1000);
if ($espera > 0) {
    usleep($espera * 1000);
}

// 3. Intentar vender el mismo número, igual que src/rifas/vender.php: insertar directo y que decida el índice
$inicio = microtime(true);
try {
    $rifas->insertOne([
        'rifa_id'     => $rifa['_id'],
        'numero'      => NUMERO_PRUEBA,
        'contacto_id' => $contacto['_id'],
        'monto'       => $rifa['precio_numero'],
        'fecha'       => new UTCDateTime(),
    ]);
    $resultado = 'VENDIDO';
} catch (MongoDB\Driver\Exception\BulkWriteException $e) {
    $codigo = $e->getWriteResult()->getWriteErrors()[0]->getCode() ?? 0;
    $resultado = $codigo === 11000 ? 'RECHAZADO (11000, clave duplicada)' : "ERROR {$codigo}";
}
$ms = round((microtime(true) - $inicio) * 1000);

// Hora real de salida (respecto al instante acordado) para ver que salieron juntos
$desfase = round($inicio * 1000 - $instante);
printf("Proceso %s · salió %+d ms del instante acordado · número %02d · %s · %d ms\n",
    $nombre, $desfase, NUMERO_PRUEBA, $resultado, $ms);
