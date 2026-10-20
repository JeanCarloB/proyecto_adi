<?php
/**
 * Vender un número de rifa (Issue #33).
 *
 * No se revisa antes si el número está libre: se intenta insertar y el
 * índice único {rifa_id, numero} decide. Si dos voluntarios venden el mismo
 * número al mismo tiempo, solo una inserción pasa; la otra recibe el error
 * 11000 (clave duplicada) y aquí se traduce a un mensaje claro.
 * Revisar primero y después insertar dejaría una ventana para el doble venta.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

use MongoDB\BSON\UTCDateTime;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$rifa = buscarActividad($baseDatos, $_POST['rifa'] ?? '');
if (!$rifa || $rifa['tipo'] !== 'rifa') {
    http_response_code(400);
    exit('La rifa no existe.');
}

$volver = 'Location: ../actividades/ver.php?id=' . $rifa['_id'] . '&';

if (!aceptaVentas($rifa)) {
    header($volver . 'error=cerrada');
    exit;
}
if (!isset($rifa['precio_numero'])) {
    header($volver . 'error=precio');
    exit;
}

$numero     = texto($_POST['numero'] ?? '');
$idContacto = idValido($_POST['contacto'] ?? '');

$numeroValido = ctype_digit($numero) && (int) $numero < NUMEROS_RIFA;
$contactoExiste = $idContacto
    && $baseDatos->selectCollection('contactos')->countDocuments(['_id' => $idContacto], ['limit' => 1]) > 0;

if (!$numeroValido || !$contactoExiste) {
    header($volver . 'error=datos');
    exit;
}

try {
    $baseDatos->selectCollection('rifas')->insertOne([
        'rifa_id'     => $rifa['_id'],
        'numero'      => (int) $numero,
        'contacto_id' => $idContacto,
        'monto'       => $rifa['precio_numero'],   // el precio sale de la rifa, nunca del formulario
        'fecha'       => new UTCDateTime(),
    ]);
    header($volver . 'ok=numero&numero=' . (int) $numero);
} catch (Throwable $e) {
    $error = match (codigoError($e)) {
        11000   => 'repetido',
        121     => 'esquema',
        default => 'general',
    };
    header($volver . 'error=' . $error . '&numero=' . (int) $numero);
}
exit;
