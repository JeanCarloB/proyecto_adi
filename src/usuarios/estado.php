<?php
/**
 * Activar o desactivar un usuario.
 * Un usuario no se borra: se marca inactivo y login.php ya no lo deja entrar.
 */
require_once dirname(__DIR__) . '/includes/usuarios.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id = idUsuario($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador del usuario no es válido.');
}
if (esUsuarioActual($id)) {
    header('Location: index.php?ok=propio');
    exit;
}

$activo = ($_POST['activo'] ?? '') === '1';

coleccionUsuarios($baseDatos)->updateOne(['_id' => $id], ['$set' => ['activo' => $activo]]);

header('Location: index.php?ok=' . ($activo ? 'activado' : 'desactivado'));
exit;
