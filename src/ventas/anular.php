<?php
/**
 * Anular una venta (Issue #32).
 *
 * Se elimina el documento y deja de sumar al total recaudado, que se
 * calcula siempre con aggregation. Solo se permite mientras la actividad
 * siga programada: una actividad finalizada tiene su total cerrado.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';
requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador de la venta no es válido.');
}

$coleccion = $baseDatos->selectCollection('ventas');
$venta     = $coleccion->findOne(['_id' => $id], ['projection' => ['actividad_id' => 1]]);
if (!$venta) {
    header('Location: index.php');
    exit;
}

$alDetalle = ($_POST['volver'] ?? '') === 'actividad';
$destino   = $alDetalle ? '../actividades/ver.php?id=' . $venta['actividad_id'] . '&' : 'index.php?';

if (!aceptaVentas(buscarActividad($baseDatos, $venta['actividad_id']))) {
    header('Location: ' . $destino . 'error=cerrada');
    exit;
}

$coleccion->deleteOne(['_id' => $id]);

header('Location: ' . $destino . 'ok=' . ($alDetalle ? 'venta-anulada' : 'anulada'));
exit;
