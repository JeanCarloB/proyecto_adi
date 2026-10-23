<?php
/**
 * Mis solicitudes: lo que pidió la persona que inició sesión y en qué quedó.
 * Puede retirar una solicitud mientras siga pendiente.
 *
 * Consulta por {usuario_id, creada} (índice en scripts/crear_colecciones.js).
 * Solo ve las suyas: el filtro siempre lleva su usuario_id, nunca uno de la URL.
 */
define('PAGINA_PUBLICA', true);
require_once dirname(__DIR__) . '/includes/alquileres.php';

use MongoDB\BSON\ObjectId;

$usuario   = requiereLogin();
$miId      = new ObjectId($usuario['id']);
$coleccion = $baseDatos->selectCollection('solicitudes');

// Retirar una solicitud propia que siga pendiente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = idValido($_POST['id'] ?? '');
    $ok = $id && csrfValido()
        && $coleccion->deleteOne(['_id' => $id, 'usuario_id' => $miId, 'estado' => 'pendiente'])->getDeletedCount() === 1;
    header('Location: mis_solicitudes.php?' . ($ok ? 'ok=retirada' : 'error=1'));
    exit;
}

$espacios    = espaciosConColor($baseDatos);
$solicitudes = $coleccion->find(['usuario_id' => $miId], ['sort' => ['creada' => -1], 'limit' => 50])->toArray();

encabezadoPublico('Mis solicitudes', 'mis');

tituloPagina(
    'Mis solicitudes',
    'Lo que pediste a la junta y en qué quedó. Las pendientes vencen solas a los ' . DIAS_SOLICITUD . ' días si nadie las atiende.',
    ['texto' => 'Nueva solicitud', 'url' => 'solicitar.php']
);

avisoExito(['retirada' => 'Retiraste la solicitud.']);
if (isset($_GET['error'])) {
    aviso('warn', 'No se pudo retirar: la solicitud ya no está pendiente.');
}
?>

<div class="adi-card">
    <?php if (!$solicitudes): ?>
        <div class="adi-empty">
            <strong>Todavía no has pedido ninguna reserva</strong>
            Elegí un día libre en el <a href="calendario.php">calendario</a>.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table adi-table">
                <thead>
                    <tr><th>Fecha</th><th>Espacio</th><th class="adi-num">Tarifa</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($solicitudes as $s):
                    $esp = $espacios[(string) $s['espacio_id']] ?? null;
                    [$clase, $texto] = match ($s['estado']) {
                        'aprobada'  => ['adi-pill--ok', 'Aprobada'],
                        'rechazada' => ['adi-pill--neutral', 'Rechazada'],
                        default     => ['adi-pill--warn', 'Pendiente'],
                    };
                ?>
                    <tr>
                        <td class="adi-date text-nowrap">
                            <strong><?= diaLargo($s['fecha']) ?></strong>
                            <small><?= diaSemana($s['fecha']) ?> · <?= e(FRANJAS[$s['franja']] ?? $s['franja']) ?> <?= e(horarioFranja($s['franja'])) ?></small>
                        </td>
                        <td>
                            <?php if ($esp): ?><span class="adi-dot" style="background: <?= e($esp['color']) ?>"></span><?php endif; ?>
                            <?= e($esp['nombre'] ?? '—') ?>
                        </td>
                        <td class="adi-num"><?= $esp && isset($esp['tarifas'][$s['franja']]) ? dinero($esp['tarifas'][$s['franja']]) : '—' ?></td>
                        <td>
                            <span class="adi-pill <?= $clase ?>"><?= $texto ?></span>
                            <div class="adi-sub mt-1">
                                <?php if ($s['estado'] === 'aprobada'): ?>
                                    Apartada por <?= HORAS_APARTADO ?> h: la junta te contacta para el pago.
                                <?php elseif ($s['estado'] === 'rechazada'): ?>
                                    <?= e($s['motivo'] ?? 'Sin motivo indicado.') ?>
                                <?php else: ?>
                                    Enviada el <?= e(mostrarHora($s['creada'])) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-end">
                            <?php if ($s['estado'] === 'pendiente'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Retirar esta solicitud?');">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($s['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Retirar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php pie();
