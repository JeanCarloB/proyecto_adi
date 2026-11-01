<?php
/**
 * Cambiar el rol de un usuario (admin ↔ comunidad).
 * El cambio se aplica la próxima vez que la persona inicie sesión.
 */
require_once dirname(__DIR__) . '/includes/usuarios.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id  = idUsuario($_POST['id'] ?? '');
$rol = (string) ($_POST['rol'] ?? '');
if ($id === null || !isset(ROLES[$rol])) {
    http_response_code(400);
    exit('El usuario o el rol no son válidos.');
}
if (esUsuarioActual($id)) {
    header('Location: index.php?ok=propio');
    exit;
}

coleccionUsuarios($baseDatos)->updateOne(['_id' => $id], ['$set' => ['rol' => $rol]]);

header('Location: index.php?ok=rol');
exit;
