<?php
/**
 * Aprobar o rechazar una solicitud de la comunidad.
 *
 * Aprobar hace tres escrituras que deben ir juntas, por eso usan una
 * TRANSACCIÓN de MongoDB (Atlas es un replica set, así que las soporta):
 *   1. Buscar el contacto por correo; si no existe, crearlo (tipo vecino).
 *   2. Insertar la reserva como "apartado" con su "vence" (TTL de 48 h).
 *   3. Marcar la solicitud como aprobada, guardar reserva_id y quitar "expira".
 * Si cualquiera falla (por ejemplo, el índice único detecta que el horario ya
 * se ocupó: error 11000), se deshace todo y no queda nada a medias.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$id     = idValido($_POST['id'] ?? '');
$accion = texto($_POST['accion'] ?? '');
if ($id === null || !in_array($accion, ['aprobar', 'rechazar'], true)) {
    http_response_code(400);
    exit('Solicitud no válida.');
}

$solicitudes = $baseDatos->selectCollection('solicitudes');
$ahora = new UTCDateTime();

if ($accion === 'rechazar') {
    $cambios = ['estado' => 'rechazada', 'resuelta' => $ahora];
    $motivo = mb_substr(texto($_POST['motivo'] ?? ''), 0, 200);
    if ($motivo !== '') {
        $cambios['motivo'] = $motivo;
    }
    $r = $solicitudes->updateOne(
        ['_id' => $id, 'estado' => 'pendiente'],
        ['$set' => $cambios, '$unset' => ['expira' => '']]
    );
    header('Location: solicitudes.php?' . ($r->getModifiedCount() === 1 ? 'ok=rechazada' : 'error=resuelta'));
    exit;
}

// --- Aprobar ----------------------------------------------------------------

$solicitud = $solicitudes->findOne(['_id' => $id, 'estado' => 'pendiente']);
if (!$solicitud) {
    header('Location: solicitudes.php?error=resuelta');
    exit;
}

$espacio = $baseDatos->selectCollection('espacios')->findOne(['_id' => $solicitud['espacio_id'], 'activo' => true]);
$monto = null;
foreach ($espacio['tarifas'] ?? [] as $t) {
    if ($t['franja'] === $solicitud['franja']) {
        $monto = $t['monto'];
    }
}
if ($monto === null) {
    header('Location: solicitudes.php?error=tarifa');
    exit;
}

$sesion = $clienteMongo->startSession();
try {
    MongoDB\with_transaction($sesion, function (Session $sesion) use ($baseDatos, $solicitud, $monto, $ahora): void {
        $contactos = $baseDatos->selectCollection('contactos');

        // 1. Contacto por correo (sin distinguir mayúsculas). Si no existe, se registra.
        $contacto = $contactos->findOne(
            ['correo' => ['$regex' => '^' . preg_quote($solicitud['correo'], '/') . '$', '$options' => 'i']],
            ['session' => $sesion, 'projection' => ['_id' => 1]]
        );
        if ($contacto) {
            $contactoId = $contacto['_id'];
        } else {
            $nuevo = [
                'nombre'         => $solicitud['nombre'],
                'tipo'           => 'vecino',
                'correo'         => $solicitud['correo'],
                'telefono'       => $solicitud['telefono'] ?? '',
                'activo'         => true,
                'fecha_registro' => $ahora,
            ];
            $contactoId = $contactos->insertOne($nuevo, ['session' => $sesion])->getInsertedId();
        }

        // 2. Reserva apartada, igual que una creada desde crear.php
        $reservaId = $baseDatos->selectCollection('reservas')->insertOne([
            'espacio_id'  => $solicitud['espacio_id'],
            'contacto_id' => $contactoId,
            'fecha'       => $solicitud['fecha'],
            'franja'      => $solicitud['franja'],
            'estado'      => 'apartado',
            'monto'       => $monto,
            'vence'       => new UTCDateTime((time() + HORAS_APARTADO * 3600) * 1000),
        ], ['session' => $sesion])->getInsertedId();

        // 3. Cerrar la solicitud. Sin "expira", el TTL ya no la borra (queda de historial).
        $baseDatos->selectCollection('solicitudes')->updateOne(
            ['_id' => $solicitud['_id'], 'estado' => 'pendiente'],
            [
                '$set'   => ['estado' => 'aprobada', 'resuelta' => $ahora, 'reserva_id' => $reservaId],
                '$unset' => ['expira' => ''],
            ],
            ['session' => $sesion]
        );
    });
    header('Location: solicitudes.php?ok=aprobada');
} catch (Throwable $e) {
    header('Location: solicitudes.php?error=' . (codigoError($e) === 11000 ? 'ocupado' : 'fallo'));
} finally {
    $sesion->endSession();
}
exit;
