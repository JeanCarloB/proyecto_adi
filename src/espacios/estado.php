<?php
/**
 * Activar o desactivar un espacio (Issue #11).
 * Un espacio no se borra: se marca inactivo para conservar su historial de reservas.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador del espacio no es válido.');
}

$activo = ($_POST['activo'] ?? '') === '1';

$baseDatos->selectCollection('espacios')->updateOne(
    ['_id' => $id],
    ['$set' => ['activo' => $activo]]
);

header('Location: index.php?ok=' . ($activo ? 'activado' : 'desactivado'));
exit;
