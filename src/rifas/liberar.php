<?php
/**
 * La persona libera un número que apartó y todavía no pagó (Issue #114).
 *
 * Solo borra si el número es suyo y sigue apartado: el filtro lleva siempre
 * su usuario_id de la sesión, nunca uno que venga del formulario. Los números
 * ya pagados solo los puede anular la junta.
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
$borrados = $id === null ? 0 : $baseDatos->selectCollection('rifas')->deleteOne([
    '_id'        => $id,
    'usuario_id' => miUsuarioId(),
    'estado'     => 'apartado',
])->getDeletedCount();

header('Location: ../actividades/mis.php?' . ($borrados === 1 ? 'ok=liberado' : 'error=liberar'));
exit;
