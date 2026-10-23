<?php
/**
 * Listado de actividades con su total recaudado (Issues #31 y #34).
 *
 * El total de cada actividad no se guarda en la actividad: se calcula con
 * aggregation pipeline sobre ventas y rifas. Así nunca queda desactualizado.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

// --- Filtros ----------------------------------------------------------------

$fTipo   = texto($_GET['tipo']   ?? '');
$fEstado = texto($_GET['estado'] ?? '');

$filtro = [];
if (isset(TIPOS_ACTIVIDAD[$fTipo])) {
    $filtro['tipo'] = $fTipo;
}
if (isset(ESTADOS_ACTIVIDAD[$fEstado])) {
    $filtro['estado'] = $fEstado;
}

// --- Consulta ---------------------------------------------------------------

// Las programadas primero; dentro de cada grupo, la más próxima o reciente arriba
$actividades = $baseDatos->selectCollection('actividades')
    ->find($filtro, ['sort' => ['estado' => -1, 'fecha' => -1]])
    ->toArray();

$totales = totalesPorActividad($baseDatos, array_map(fn ($a) => $a['_id'], $actividades));

$recaudado   = 0.0;
$programadas = 0;
foreach ($actividades as $a) {
    $recaudado += $totales[(string) $a['_id']]['total'] ?? 0.0;
    if ($a['estado'] === 'programada') {
        $programadas++;
    }
}

encabezado('Actividades', 'actividades');

tituloPagina(
    'Actividades',
    'Turnos, ferias y rifas. Una actividad finalizada ya no acepta ventas y su total queda cerrado.',
    ['texto' => 'Nueva actividad', 'url' => 'form.php'],
    'ventas'
);
?>

<section class="adi-stats" aria-label="Resumen de actividades">
    <div class="adi-stat">
        <div class="adi-stat-label">Actividades</div>
        <div class="adi-stat-value"><?= number_format(count($actividades), 0, ',', '.') ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label"><span class="adi-dot" style="background: var(--adi-ok-ink)"></span>Programadas</div>
        <div class="adi-stat-value"><?= number_format($programadas, 0, ',', '.') ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label">Finalizadas</div>
        <div class="adi-stat-value"><?= number_format(count($actividades) - $programadas, 0, ',', '.') ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label">Total recaudado</div>
        <div class="adi-stat-value"><?= dinero($recaudado) ?></div>
    </div>
</section>

<form method="GET" class="adi-card adi-filters adi-filters--ventas mb-4">
    <div>
        <label for="tipo" class="form-label">Tipo</label>
        <select class="form-select" id="tipo" name="tipo">
            <option value="">Todos</option>
            <?php foreach (TIPOS_ACTIVIDAD as $valor => $etiqueta): ?>
                <option value="<?= e($valor) ?>" <?= $fTipo === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="estado" class="form-label">Estado</label>
        <select class="form-select" id="estado" name="estado">
            <option value="">Todos</option>
            <?php foreach (ESTADOS_ACTIVIDAD as $valor => $etiqueta): ?>
                <option value="<?= e($valor) ?>" <?= $fEstado === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn adi-btn-primary">Filtrar</button>
        <a href="index.php" class="btn adi-btn-ghost text-nowrap">Ver todas</a>
    </div>
</form>

<div class="adi-card">
    <?php if (!$actividades): ?>
        <div class="adi-empty">
            <strong>No hay actividades con estos filtros</strong>
            <a href="form.php">Registrá un turno, una feria o una rifa</a> para empezar a vender.
        </div>
    <?php else: ?>
        <div class="adi-table-meta">
            <span><?= count($actividades) ?> <?= count($actividades) === 1 ? 'actividad' : 'actividades' ?></span>
            <span>Programadas primero</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Actividad</th>
                        <th>Estado</th>
                        <th class="adi-num">Ventas</th>
                        <th class="adi-num">Recaudado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($actividades as $a):
                    $id    = (string) $a['_id'];
                    $t     = $totales[$id] ?? ['n' => 0, 'total' => 0.0];
                    $esRifa = $a['tipo'] === 'rifa';
                ?>
                    <tr>
                        <td class="adi-date text-nowrap">
                            <strong><?= diaLargo($a['fecha']) ?></strong>
                            <small><?= diaSemana($a['fecha']) ?></small>
                        </td>
                        <td>
                            <a class="adi-contact-name adi-link-quiet" href="ver.php?id=<?= e($id) ?>"><?= e($a['nombre']) ?></a>
                            <div class="mt-1"><span class="adi-tag"><?= e(TIPOS_ACTIVIDAD[$a['tipo']] ?? $a['tipo']) ?></span></div>
                        </td>
                        <td><?= pillActividad($a['estado']) ?></td>
                        <td class="adi-num">
                            <?= number_format($t['n'], 0, ',', '.') ?>
                            <div class="adi-sub"><?= $esRifa ? 'números' : ($t['n'] === 1 ? 'venta' : 'ventas') ?></div>
                        </td>
                        <td class="adi-num"><?= dinero($t['total']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="ver.php?id=<?= e($id) ?>" class="btn btn-sm adi-btn-soft">
                                <?= aceptaVentas($a) ? ($esRifa ? 'Vender números' : 'Ver y vender') : 'Ver' ?>
                            </a>
                            <a href="form.php?id=<?= e($id) ?>" class="btn btn-sm adi-btn-ghost">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php pie();
