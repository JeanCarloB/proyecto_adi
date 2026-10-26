<?php
/**
 * Anular la venta de un número de rifa (Issue #33).
 * Se elimina el documento y el número queda libre para venderse otra vez.
 * Solo mientras la rifa siga programada.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';
exigirAccionAdmin();   // rol admin + POST + token CSRF

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador del número no es válido.');
}

$coleccion = $baseDatos->selectCollection('rifas');
$numero    = $coleccion->findOne(['_id' => $id], ['projection' => ['rifa_id' => 1]]);
if (!$numero) {
    header('Location: ../actividades/index.php');
    exit;
}

$volver = 'Location: ../actividades/ver.php?id=' . $numero['rifa_id'] . '&';

if (!aceptaVentas(buscarActividad($baseDatos, $numero['rifa_id']))) {
    header($volver . 'error=cerrada');
    exit;
}

$coleccion->deleteOne(['_id' => $id]);

header($volver . 'ok=numero-anulado');
exit;
