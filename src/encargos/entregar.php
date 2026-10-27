<?php
/**
 * La junta entrega un encargo y queda registrado como venta (Issue #115).
 *
 * Son tres escrituras que deben ir juntas, por eso van en una TRANSACCIÓN:
 *   1. Buscar el contacto por el correo de la cuenta; si no existe, crearlo.
 *   2. Insertar la venta con los productos y el total del encargo.
 *   3. Marcar el encargo como entregado con el id de la venta y quitar
 *      "expira" (así el TTL ya no lo borra y queda de historial).
 * Si el encargo ya no está pendiente (lo entregó otra persona, se canceló o
 * venció), se deshace todo: nunca queda una venta duplicada ni un encargo
 * entregado sin su venta.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';
exigirAccionAdmin();   // rol admin + POST + token CSRF

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Session;

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador del encargo no es válido.');
}

$encargos = $baseDatos->selectCollection('encargos');
$encargo  = $encargos->findOne(['_id' => $id, 'estado' => 'pendiente']);
if (!$encargo) {
    header('Location: ../actividades/index.php');
    exit;
}

$volver = 'Location: ../actividades/ver.php?id=' . $encargo['actividad_id'] . '&';

if (!aceptaVentas(buscarActividad($baseDatos, $encargo['actividad_id']))) {
    header($volver . 'error=cerrada');
    exit;
}

$sesion = $clienteMongo->startSession();
try {
    MongoDB\with_transaction($sesion, function (Session $sesion) use ($baseDatos, $encargos, $encargo): void {
        $contactoId = contactoPorCorreo($baseDatos, $encargo['nombre'], $encargo['correo'], $sesion);
        $ahora = new UTCDateTime();

        $ventaId = $baseDatos->selectCollection('ventas')->insertOne([
            'actividad_id' => $encargo['actividad_id'],
            'items'        => $encargo['items'],
            'total'        => $encargo['total'],
            'fecha'        => $ahora,
            'contacto_id'  => $contactoId,
        ], ['session' => $sesion])->getInsertedId();

        $r = $encargos->updateOne(
            ['_id' => $encargo['_id'], 'estado' => 'pendiente'],
            [
                '$set'   => ['estado' => 'entregado', 'resuelto' => $ahora, 'venta_id' => $ventaId],
                '$unset' => ['expira' => ''],
            ],
            ['session' => $sesion]
        );
        if ($r->getModifiedCount() !== 1) {
            throw new RuntimeException('resuelto');   // ya no estaba pendiente: se deshace la venta
        }
    });
    header($volver . 'ok=encargo-entregado');
} catch (Throwable $e) {
    header($volver . 'error=' . ($e->getMessage() === 'resuelto' ? 'encargo-resuelto' : 'general'));
} finally {
    $sesion->endSession();
}
exit;
