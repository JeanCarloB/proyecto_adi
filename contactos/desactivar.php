
<?php
require_once dirname(__DIR__) . '/includes/conexion.php';

use MongoDB\BSON\ObjectId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id = $_POST['id'] ?? '';

if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) {
    http_response_code(400);
    exit('El identificador del contacto no es válido.');
}

$coleccion = $baseDatos->selectCollection('contactos');

$resultado = $coleccion->updateOne(
    [
        '_id' => new ObjectId($id),
        'activo' => true
    ],
    [
        '$set' => ['activo' => false]
    ]
);

if ($resultado->getModifiedCount() === 1) {
    header('Location: index.php?desactivado=1');
    exit;
}

header('Location: index.php?desactivado=0');
exit;
