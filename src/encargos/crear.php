<?php
/**
 * Una cuenta de comunidad encarga productos del menú de un turno o una feria (Issue #115).
 *
 * El formulario solo manda cuántas unidades de cada producto del menú quiere:
 * el nombre y el precio salen del menú guardado en la actividad, nunca del
 * formulario, y el total lo calcula el servidor. El encargo queda pendiente
 * hasta que la junta lo entregue (y entonces se registra la venta) o lo cancele.
 * Si nadie lo atiende, el TTL sobre "expira" lo borra unos días después de
 * la actividad.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

use MongoDB\BSON\UTCDateTime;

$usuario = requiereRol('comunidad');   // la junta registra ventas directo

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}
if (!csrfValido()) {
    rechazarCsrf();
}

$actividad = buscarActividad($baseDatos, $_POST['actividad'] ?? '');
if (!$actividad || $actividad['tipo'] === 'rifa') {
    http_response_code(400);
    exit('La actividad no existe.');
}

$volver = 'Location: ../actividades/ver.php?id=' . $actividad['_id'] . '&';

if (!aceptaVentas($actividad) || empty($actividad['menu'])) {
    header($volver . 'error=sin-encargos');
    exit;
}

// Cantidades por posición del menú: cantidad[0], cantidad[1], ...
$cantidades = is_array($_POST['cantidad'] ?? null) ? $_POST['cantidad'] : [];
$items = [];
$total = 0.0;
foreach ($actividad['menu'] as $i => $producto) {
    $cantidad = texto($cantidades[$i] ?? '');
    if ($cantidad === '' || $cantidad === '0') {
        continue;
    }
    if (!ctype_digit($cantidad) || (int) $cantidad > MAX_UNIDADES_ENCARGO) {
        header($volver . 'error=cantidad');
        exit;
    }
    $items[] = ['producto' => $producto['producto'], 'cantidad' => (int) $cantidad, 'precio' => $producto['precio']];
    $total  += (int) $cantidad * (float) (string) $producto['precio'];
}
if (!$items) {
    header($volver . 'error=vacio');
    exit;
}

$encargos = $baseDatos->selectCollection('encargos');
$miId     = miUsuarioId();

if ($encargos->countDocuments(['actividad_id' => $actividad['_id'], 'usuario_id' => $miId, 'estado' => 'pendiente']) >= MAX_ENCARGOS) {
    header($volver . 'error=limite-encargos');
    exit;
}

// Vence unos días después de la actividad (o desde hoy, si la fecha ya pasó)
$finActividad = max($actividad['fecha']->toDateTime()->getTimestamp(), time());
$expira = new UTCDateTime(($finActividad + DIAS_EXPIRA_ENCARGO * 86400) * 1000);

$nota = mb_substr(texto($_POST['nota'] ?? ''), 0, 200);
$encargo = [
    'actividad_id' => $actividad['_id'],
    'usuario_id'   => $miId,
    'nombre'       => mb_substr((string) $usuario['nombre'], 0, 120),
    'correo'       => mb_substr((string) $usuario['correo'], 0, 200),
    'items'        => $items,
    'total'        => decimal((string) $total),
    'estado'       => 'pendiente',
    'creado'       => new UTCDateTime(),
    'expira'       => $expira,
];
if ($nota !== '') {
    $encargo['nota'] = $nota;
}

try {
    $encargos->insertOne($encargo);
    header($volver . 'ok=encargo');
} catch (Throwable $e) {
    header($volver . 'error=' . (codigoError($e) === 121 ? 'esquema' : 'general'));
}
exit;
