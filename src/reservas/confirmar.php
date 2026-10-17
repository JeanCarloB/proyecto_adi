<?php
/**
 * Confirmar el pago de una reserva (Issue #13).
 *
 * En UNA sola actualización: estado → "confirmado" y se elimina "vence".
 * Sin "vence", el índice TTL ya no la toca y la reserva queda permanente.
 *
 * Tiene que ser una sola operación: el validador exige que una reserva
 * confirmada NO tenga "vence". Si se hiciera en dos pasos, el primero
 * dejaría el documento inválido y MongoDB lo rechazaría.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador de la reserva no es válido.');
}

$resultado = $baseDatos->selectCollection('reservas')->updateOne(
    // Solo si sigue apartada: si ya venció (el TTL la borró) o ya estaba confirmada, no hace nada
    ['_id' => $id, 'estado' => 'apartado'],
    [
        '$set'   => ['estado' => 'confirmado'],
        '$unset' => ['vence' => ''],
    ]
);

header('Location: index.php?' . ($resultado->getModifiedCount() === 1 ? 'ok=confirmada' : 'error=confirmar'));
exit;
