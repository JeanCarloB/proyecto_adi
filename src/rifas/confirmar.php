<?php
/**
 * Confirmar el pago de un número apartado por la comunidad (Issue #114).
 *
 * Hace dos escrituras que deben ir juntas, por eso usan una TRANSACCIÓN
 * (igual que aprobar una solicitud de alquiler):
 *   1. Buscar el contacto por el correo de la cuenta; si no existe, crearlo.
 *   2. Pasar el número a "pagado" con ese contacto y quitar "vence", en una
 *      sola actualización: sin "vence" el TTL ya no lo toca, y el validador
 *      exige que un número pagado tenga contacto y no tenga "vence".
 * Si el apartado ya venció (el TTL lo borró) o alguien ya lo confirmó, no se
 * cambia nada y no queda un contacto creado de más.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';
exigirAccionAdmin();   // rol admin + POST + token CSRF

use MongoDB\Driver\Session;

$id = idValido($_POST['id'] ?? '');
if ($id === null) {
    http_response_code(400);
    exit('El identificador del número no es válido.');
}

$rifas    = $baseDatos->selectCollection('rifas');
$apartado = $rifas->findOne(['_id' => $id, 'estado' => 'apartado']);
if (!$apartado) {
    header('Location: ../actividades/index.php?error=vencido');
    exit;
}

$volver = 'Location: ../actividades/ver.php?id=' . $apartado['rifa_id'] . '&';

if (!aceptaVentas(buscarActividad($baseDatos, $apartado['rifa_id']))) {
    header($volver . 'error=cerrada');
    exit;
}

$sesion = $clienteMongo->startSession();
try {
    MongoDB\with_transaction($sesion, function (Session $sesion) use ($baseDatos, $rifas, $apartado): void {
        $contactoId = contactoPorCorreo($baseDatos, $apartado['nombre'], $apartado['correo'], $sesion);

        $r = $rifas->updateOne(
            ['_id' => $apartado['_id'], 'estado' => 'apartado'],
            ['$set' => ['estado' => 'pagado', 'contacto_id' => $contactoId], '$unset' => ['vence' => '']],
            ['session' => $sesion]
        );
        if ($r->getModifiedCount() !== 1) {
            // Venció o lo confirmó otra persona entre la lectura y ahora: se deshace todo
            throw new RuntimeException('vencido');
        }
    });
    header($volver . 'ok=pagado&numero=' . (int) $apartado['numero']);
} catch (Throwable $e) {
    header($volver . 'error=' . ($e->getMessage() === 'vencido' ? 'vencido' : 'general'));
} finally {
    $sesion->endSession();
}
exit;
