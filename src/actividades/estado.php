<?php
/**
 * Finalizar o reabrir una actividad (Issue #31).
 *
 * Una actividad no se borra: se finaliza para conservar sus ventas.
 * Mientras esté finalizada no acepta ventas nuevas ni cambios en las que
 * ya tiene, así el total recaudado que se reportó a la junta no se mueve.
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
    exit('El identificador de la actividad no es válido.');
}

$estado = ($_POST['estado'] ?? '') === 'finalizada' ? 'finalizada' : 'programada';

$baseDatos->selectCollection('actividades')->updateOne(
    ['_id' => $id],
    ['$set' => ['estado' => $estado]]
);

header('Location: ver.php?id=' . $id . '&ok=' . ($estado === 'finalizada' ? 'finalizada' : 'reabierta'));
exit;
