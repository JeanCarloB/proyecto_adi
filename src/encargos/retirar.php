<?php
/**
 * La persona retira un encargo suyo que todavía está pendiente (Issue #115).
 * El filtro lleva siempre su usuario_id de la sesión, nunca uno del formulario.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

requiereLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}
if (!csrfValido()) {
    rechazarCsrf();
}

$id = idValido($_POST['id'] ?? '');
$borrados = $id === null ? 0 : $baseDatos->selectCollection('encargos')->deleteOne([
    '_id'        => $id,
    'usuario_id' => miUsuarioId(),
    'estado'     => 'pendiente',
])->getDeletedCount();

header('Location: ../actividades/mis.php?' . ($borrados === 1 ? 'ok=encargo-retirado' : 'error=retirar'));
exit;
