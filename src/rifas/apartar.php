<?php
/**
 * Apartar un número de rifa desde una cuenta de comunidad (Issue #114).
 *
 * El número queda "apartado" a nombre de la cuenta por HORAS_APARTADO_RIFA
 * horas. Si la junta no confirma el pago antes, el índice TTL sobre "vence"
 * borra el documento y el número vuelve a estar libre, sin ningún proceso
 * de la aplicación. Es el mismo mecanismo de los apartados de alquiler.
 *
 * Igual que al vender, no se revisa antes si el número está libre: se
 * inserta y el índice único {rifa_id, numero} decide. Si otra persona lo
 * apartó o la junta lo vendió en el mismo instante, solo una inserción pasa.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

use MongoDB\BSON\UTCDateTime;

$usuario = requiereRol('comunidad');   // la junta vende directo con vender.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}
if (!csrfValido()) {
    rechazarCsrf();
}

$rifa = buscarActividad($baseDatos, $_POST['rifa'] ?? '');
if (!$rifa || $rifa['tipo'] !== 'rifa') {
    http_response_code(400);
    exit('La rifa no existe.');
}

$volver = 'Location: ../actividades/ver.php?id=' . $rifa['_id'] . '&';
$numero = texto($_POST['numero'] ?? '');

if (!aceptaVentas($rifa)) {
    header($volver . 'error=cerrada');
    exit;
}
if (!isset($rifa['precio_numero'])) {
    header($volver . 'error=precio');
    exit;
}
if (!ctype_digit($numero) || (int) $numero >= NUMEROS_RIFA) {
    header($volver . 'error=datos');
    exit;
}

$rifas = $baseDatos->selectCollection('rifas');
$miId  = miUsuarioId();

// Límite de apartados sin pagar por persona en esta rifa, para que nadie acapare números
$pendientes = $rifas->countDocuments(['rifa_id' => $rifa['_id'], 'usuario_id' => $miId, 'estado' => 'apartado']);
if ($pendientes >= MAX_APARTADOS_RIFA) {
    header($volver . 'error=limite');
    exit;
}

try {
    $rifas->insertOne([
        'rifa_id'    => $rifa['_id'],
        'numero'     => (int) $numero,
        'monto'      => $rifa['precio_numero'],   // el precio sale de la rifa, nunca del formulario
        'fecha'      => new UTCDateTime(),
        'estado'     => 'apartado',
        'usuario_id' => $miId,
        'nombre'     => mb_substr((string) $usuario['nombre'], 0, 120),
        'correo'     => mb_substr((string) $usuario['correo'], 0, 200),
        'vence'      => new UTCDateTime((time() + HORAS_APARTADO_RIFA * 3600) * 1000),
    ]);
    header($volver . 'ok=apartado&numero=' . (int) $numero);
} catch (Throwable $e) {
    $error = match (codigoError($e)) {
        11000   => 'ocupado',
        121     => 'esquema',
        default => 'general',
    };
    header($volver . 'error=' . $error . '&numero=' . (int) $numero);
}
exit;
