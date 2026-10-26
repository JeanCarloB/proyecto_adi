<?php
/**
 * Editar los datos de un contacto.
 */
require_once dirname(__DIR__) . '/includes/contactos.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$errores = [];
$coleccion = $baseDatos->selectCollection('contactos');
$id = $_GET['id'] ?? '';

if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) {
    http_response_code(400);
    exit('El identificador del contacto no es válido.');
}

$objectId = new ObjectId($id);
$contacto = $coleccion->findOne(['_id' => $objectId]);

if (!$contacto) {
    http_response_code(404);
    exit('No se encontró el contacto solicitado.');
}

$nombre = $contacto['nombre'] ?? '';
$tipo = $contacto['tipo'] ?? '';
$telefono = $contacto['telefono'] ?? '';
$correo = $contacto['correo'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo = trim($_POST['correo'] ?? '');

    if (mb_strlen($nombre) < 2) {
        $errores[] = 'El nombre debe tener al menos 2 caracteres.';
    }

    if (!in_array($tipo, ['vecino', 'comercio', 'institucion'], true)) {
        $errores[] = 'Seleccioná un tipo válido.';
    }

    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingresá un correo electrónico válido.';
    }

    if (!$errores) {
        try {
            $coleccion->updateOne(
                ['_id' => $objectId],
                ['$set' => [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'telefono' => $telefono,
                    'correo' => $correo
                ]]
            );

            header('Location: index.php?editado=1');
            exit;
        } catch (Throwable $e) {
            $errores[] = 'No se pudo actualizar el contacto. Revisá los datos e intentá nuevamente.';
        }
    }
}

$textoBoton = 'Guardar cambios';

encabezado('Editar contacto', 'contactos');
?>
<div class="adi-form-narrow">
    <a href="index.php" class="adi-sub text-decoration-none d-inline-block mb-3">← Contactos</a>
    <?php tituloPagina('Editar contacto', (string) $nombre, null, 'contactos'); ?>

    <?php listaErrores($errores); ?>

    <?php require __DIR__ . '/_campos.php'; ?>
</div>
<?php pie();
