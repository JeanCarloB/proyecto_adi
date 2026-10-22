<?php
/**
 * Cancelar una reserva (Issue #12).
 *
 * Se elimina el documento, igual que hace el índice TTL con un apartado
 * vencido: el horario queda libre de inmediato para otra reserva.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';
requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador de la reserva no es válido.');
}

$baseDatos->selectCollection('reservas')->deleteOne(['_id' => $id]);

header('Location: ' . destinoReservas('ok=cancelada'));
exit;
