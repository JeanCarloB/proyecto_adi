<?php
/**
 * La junta cancela un encargo pendiente, por ejemplo si se acabó un producto (Issue #115).
 * Queda como cancelado (sin "expira") para que la persona lo vea en Mis compras.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';
exigirAccionAdmin();   // rol admin + POST + token CSRF

use MongoDB\BSON\UTCDateTime;

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador del encargo no es válido.');
}

$encargos = $baseDatos->selectCollection('encargos');
$encargo  = $encargos->findOne(['_id' => $id], ['projection' => ['actividad_id' => 1]]);
if (!$encargo) {
    header('Location: ../actividades/index.php');
    exit;
}

$r = $encargos->updateOne(
    ['_id' => $id, 'estado' => 'pendiente'],
    ['$set' => ['estado' => 'cancelado', 'resuelto' => new UTCDateTime()], '$unset' => ['expira' => '']]
);

header('Location: ../actividades/ver.php?id=' . $encargo['actividad_id'] . '&'
    . ($r->getModifiedCount() === 1 ? 'ok=encargo-cancelado' : 'error=encargo-resuelto'));
exit;
