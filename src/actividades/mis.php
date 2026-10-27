<?php
/**
 * Mis compras: los números de rifa que apartó o compró la persona con sesión
 * (Issue #114) y sus encargos en turnos y ferias (Issue #115).
 *
 * Solo ve lo suyo: el filtro siempre lleva el usuario_id de la sesión, nunca
 * uno de la URL. Los números traen el nombre y la fecha de su rifa con un
 * $lookup a actividades, sobre el índice parcial {usuario_id, fecha}.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

$miId = miUsuarioId();

$numeros = $baseDatos->selectCollection('rifas')->aggregate([
    ['$match'  => ['usuario_id' => $miId]],
    ['$sort'   => ['fecha' => -1]],
    ['$limit'  => 100],
    ['$lookup' => ['from' => 'actividades', 'localField' => 'rifa_id', 'foreignField' => '_id', 'as' => 'rifa']],
    ['$unwind' => ['path' => '$rifa', 'preserveNullAndEmptyArrays' => true]],
    ['$project' => [
        'numero' => 1, 'estado' => 1, 'vence' => 1, 'monto' => 1, 'fecha' => 1, 'rifa_id' => 1,
        'rifa.nombre' => 1, 'rifa.fecha' => 1, 'rifa.estado' => 1,
    ]],
])->toArray();

// Encargos en turnos y ferias, con el nombre de la actividad (índice {usuario_id, creado})
$encargos = $baseDatos->selectCollection('encargos')->aggregate([
    ['$match'  => ['usuario_id' => $miId]],
    ['$sort'   => ['creado' => -1]],
    ['$limit'  => 50],
    ['$lookup' => ['from' => 'actividades', 'localField' => 'actividad_id', 'foreignField' => '_id', 'as' => 'actividad']],
    ['$unwind' => ['path' => '$actividad', 'preserveNullAndEmptyArrays' => true]],
    ['$project' => [
        'items' => 1, 'total' => 1, 'estado' => 1, 'creado' => 1, 'nota' => 1, 'actividad_id' => 1,
        'actividad.nombre' => 1, 'actividad.fecha' => 1,
    ]],
])->toArray();

encabezado('Mis compras', 'mis');

tituloPagina(
    'Mis compras',
    'Los números de rifa que apartaste o compraste y tus encargos en turnos y ferias.',
    ['texto' => 'Ver actividades', 'url' => 'index.php'],
    'ventas'
);

avisoExito([
    'liberado'         => 'Liberaste el número: ya está disponible para otra persona.',
    'encargo-retirado' => 'Retiraste el encargo.',
]);
if (($_GET['error'] ?? '') === 'liberar') {
    aviso('warn', 'No se pudo liberar: el número ya no está apartado a tu nombre (venció o ya se pagó).');
}
if (($_GET['error'] ?? '') === 'retirar') {
    aviso('warn', 'No se pudo retirar: el encargo ya no está pendiente.');
}
?>

<h2 class="adi-section-title mt-2">Números de rifa</h2>
<div class="adi-card mb-4">
    <?php if (!$numeros): ?>
        <div class="adi-empty">
            <strong>Todavía no tenés números de rifa</strong>
            Abrí una rifa en <a href="index.php">Actividades</a> y apartá un número libre.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table adi-table">
                <thead>
                    <tr><th>Número</th><th>Rifa</th><th>Estado</th><th class="adi-num">Precio</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($numeros as $n): $esApartado = ($n['estado'] ?? '') === 'apartado'; ?>
                    <tr>
                        <td><span class="adi-rifa-num is-mine is-inline"><?= numeroRifa($n['numero']) ?></span></td>
                        <td>
                            <?php if (isset($n['rifa']['nombre'])): ?>
                                <a class="adi-link-quiet" href="ver.php?id=<?= e($n['rifa_id']) ?>"><?= e($n['rifa']['nombre']) ?></a>
                                <div class="adi-sub">Sorteo: <?= diaLargo($n['rifa']['fecha']) ?></div>
                            <?php else: ?>
                                <span class="adi-sub">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($esApartado): ?>
                                <span class="adi-pill adi-pill--warn">Apartado</span>
                                <div class="adi-sub mt-1">Pagalo antes del <?= mostrarHora($n['vence']) ?></div>
                            <?php else: ?>
                                <span class="adi-pill adi-pill--ok">Pagado</span>
                            <?php endif; ?>
                        </td>
                        <td class="adi-num"><?= dinero($n['monto']) ?></td>
                        <td class="text-end">
                            <?php if ($esApartado): ?>
                                <form action="../rifas/liberar.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="Liberar número"
                                      data-confirmar="El número <?= numeroRifa($n['numero']) ?> dejará de estar a tu nombre y otra persona podrá apartarlo."
                                      data-confirmar-boton="Liberar" data-confirmar-peligro="1">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($n['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Liberar</button>
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

<h2 class="adi-section-title">Encargos en turnos y ferias</h2>
<div class="adi-card">
    <?php if (!$encargos): ?>
        <div class="adi-empty">
            <strong>Todavía no has encargado nada</strong>
            Abrí un turno o una feria en <a href="index.php">Actividades</a> para ver su menú.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table adi-table">
                <thead>
                    <tr><th>Actividad</th><th>Productos</th><th>Estado</th><th class="adi-num">Total</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($encargos as $en):
                    [$clase, $texto] = match ($en['estado']) {
                        'entregado' => ['adi-pill--ok', 'Entregado'],
                        'cancelado' => ['adi-pill--neutral', 'Cancelado'],
                        default     => ['adi-pill--warn', 'Pendiente'],
                    };
                ?>
                    <tr>
                        <td>
                            <?php if (isset($en['actividad']['nombre'])): ?>
                                <a class="adi-link-quiet" href="ver.php?id=<?= e($en['actividad_id']) ?>"><?= e($en['actividad']['nombre']) ?></a>
                                <div class="adi-sub"><?= diaLargo($en['actividad']['fecha']) ?></div>
                            <?php else: ?>
                                <span class="adi-sub">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e(resumenItems($en['items'])) ?>
                            <?php if (isset($en['nota'])): ?><div class="adi-sub"><?= e($en['nota']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="adi-pill <?= $clase ?>"><?= e($texto) ?></span></td>
                        <td class="adi-num"><?= dinero($en['total']) ?></td>
                        <td class="text-end">
                            <?php if ($en['estado'] === 'pendiente'): ?>
                                <form action="../encargos/retirar.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="Retirar encargo"
                                      data-confirmar="La junta ya no va a preparar este encargo."
                                      data-confirmar-boton="Retirar" data-confirmar-peligro="1">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($en['_id']) ?>">
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
