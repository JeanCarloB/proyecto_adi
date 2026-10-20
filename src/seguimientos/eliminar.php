
<?php
require_once dirname(__DIR__) . '/includes/seguimientos.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = $_POST['id'] ?? '';

if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) {
    header('Location: index.php?ok=error');
    exit;
}

$coleccion = $baseDatos->selectCollection('seguimientos');

$resultado = $coleccion->deleteOne([
    '_id' => new MongoDB\BSON\ObjectId($id),
]);

if ($resultado->getDeletedCount() === 1) {
    header('Location: index.php?ok=eliminado');
} else {
    header('Location: index.php?ok=error');
}

exit;
