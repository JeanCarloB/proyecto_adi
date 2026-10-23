<?php
/**
 * Listado de reservas con filtros (Issue #12).
 *
 * Filtrar por espacio y rango de fechas aprovecha el índice único
 * {espacio_id, fecha, franja}: sus dos primeros campos sirven como prefijo.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';

use MongoDB\BSON\ObjectId;


// --- Filtros ----------------------------------------------------------------

$fEspacio = texto($_GET['espacio'] ?? '');
$fDesde   = texto($_GET['desde']   ?? hoyLocal());
$fHasta   = texto($_GET['hasta']   ?? '');
$fEstado  = texto($_GET['estado']  ?? '');

$filtro = [];

if ($id = idValido($fEspacio)) {
    $filtro['espacio_id'] = $id;
}

$rango = [];
if ($fDesde !== '' && ($d = diaUtc($fDesde))) {
    $rango['$gte'] = $d;
}
if ($fHasta !== '' && ($h = diaUtc($fHasta))) {
    $rango['$lte'] = $h;
}
if ($rango) {
    $filtro['fecha'] = $rango;
}

if (in_array($fEstado, ['apartado', 'confirmado'], true)) {
    $filtro['estado'] = $fEstado;
}

// --- Consulta ---------------------------------------------------------------

// Un arreglo vacío de PHP se envía a MongoDB como lista, no como documento,
// y $match lo rechaza. Con (object) siempre viaja como documento ({} = sin filtro).
$matchFiltro = (object) $filtro;

// Resumen con aggregation pipeline: cuántas reservas hay por estado y cuánto suman.
// Se calcula sobre todo el filtro, no solo sobre las filas que se muestran.
$resumen = ['apartado' => ['n' => 0, 'monto' => 0.0], 'confirmado' => ['n' => 0, 'monto' => 0.0]];
$pipeline = [
    ['$match' => $matchFiltro],
    ['$group' => ['_id' => '$estado', 'n' => ['$sum' => 1], 'monto' => ['$sum' => '$monto']]],
];
foreach ($baseDatos->selectCollection('reservas')->aggregate($pipeline) as $g) {
    if (isset($resumen[$g['_id']])) {
        $resumen[$g['_id']] = ['n' => $g['n'], 'monto' => (float) (string) $g['monto']];
    }
}
$total = $resumen['apartado']['n'] + $resumen['confirmado']['n'];

// --- Paginación -------------------------------------------------------------

$porPagina = leerPorPagina(15);
$paginas   = max(1, (int) ceil($total / $porPagina));
$pagina   = min(max(1, (int) ($_GET['pagina'] ?? 1)), $paginas);

// Orden: fecha, luego mañana → tarde → noche. La franja es texto y en orden
// alfabético quedaría mañana, noche, tarde; por eso se calcula su posición
// con $indexOfArray. Así el orden es correcto también entre una página y otra.
// $skip y $limit traen solo las filas de la página actual.
$reservas = $baseDatos->selectCollection('reservas')->aggregate([
    ['$match' => $matchFiltro],
    ['$addFields' => ['ordenFranja' => ['$indexOfArray' => [array_keys(FRANJAS), '$franja']]]],
    ['$sort' => ['fecha' => 1, 'ordenFranja' => 1, '_id' => 1]],
    ['$skip' => ($pagina - 1) * $porPagina],
    ['$limit' => $porPagina],
])->toArray();

// Nombres de espacios y contactos. Las reservas guardan solo referencias.
$espacios = [];
foreach ($baseDatos->selectCollection('espacios')->find([], ['sort' => ['nombre' => 1]]) as $esp) {
    $espacios[(string) $esp['_id']] = $esp['nombre'];
}

$idsContacto = array_values(array_unique(
    array_map(fn ($r) => (string) $r['contacto_id'], $reservas)
));
$contactos = [];
if ($idsContacto) {
    $cursor = $baseDatos->selectCollection('contactos')->find(
        ['_id' => ['$in' => array_map(fn ($i) => new ObjectId($i), $idsContacto)]],
        ['projection' => ['nombre' => 1]]
    );
    foreach ($cursor as $c) {
        $contactos[(string) $c['_id']] = $c['nombre'];
    }
}

encabezado('Reservas', 'reservas');

tituloPagina(
    'Reservas',
    'Un apartado sin confirmar vence solo a las ' . HORAS_APARTADO . ' horas y libera el horario.',
    ['texto' => 'Nueva reserva', 'url' => 'crear.php']
);

avisoExito([
    'creada'     => 'Reserva apartada. Si no se confirma el pago en ' . HORAS_APARTADO . ' horas, vence sola.',
    'confirmada' => 'Pago confirmado. La reserva ya no vence.',
    'cancelada'  => 'La reserva se canceló y el horario quedó libre.',
    'editada'    => 'Los cambios de la reserva se guardaron.',
]);
if (($_GET['error'] ?? '') === 'confirmar') {
    aviso('warn', 'No se pudo confirmar: la reserva ya estaba confirmada o el apartado venció.');
}
?>

<section class="adi-stats" aria-label="Resumen del filtro">
    <div class="adi-stat">
        <div class="adi-stat-label">Reservas</div>
        <div class="adi-stat-value"><?= number_format($total, 0, ',', '.') ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label"><span class="adi-dot" style="background: var(--adi-warn-ink)"></span>Apartadas</div>
        <div class="adi-stat-value"><?= number_format($resumen['apartado']['n'], 0, ',', '.') ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label"><span class="adi-dot" style="background: var(--adi-ok-ink)"></span>Confirmadas</div>
        <div class="adi-stat-value"><?= number_format($resumen['confirmado']['n'], 0, ',', '.') ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label">Ingresos confirmados</div>
        <div class="adi-stat-value"><?= dinero($resumen['confirmado']['monto']) ?></div>
    </div>
</section>

<form method="GET" class="adi-card adi-filters mb-4">
    <div>
        <label for="espacio" class="form-label">Espacio</label>
        <select class="form-select" id="espacio" name="espacio">
            <option value="">Todos los espacios</option>
            <?php foreach ($espacios as $idE => $nombreE): ?>
                <option value="<?= e($idE) ?>" <?= $fEspacio === $idE ? 'selected' : '' ?>><?= e($nombreE) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="desde" class="form-label">Desde</label>
        <input type="date" class="form-control" id="desde" name="desde" value="<?= e($fDesde) ?>">
    </div>
    <div>
        <label for="hasta" class="form-label">Hasta</label>
        <input type="date" class="form-control" id="hasta" name="hasta" value="<?= e($fHasta) ?>">
    </div>
    <div>
        <label for="estado" class="form-label">Estado</label>
        <select class="form-select" id="estado" name="estado">
            <option value="">Todos</option>
            <option value="apartado" <?= $fEstado === 'apartado' ? 'selected' : '' ?>>Apartado</option>
            <option value="confirmado" <?= $fEstado === 'confirmado' ? 'selected' : '' ?>>Confirmado</option>
        </select>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn adi-btn-primary">Filtrar</button>
        <a href="index.php?desde=" class="btn adi-btn-ghost text-nowrap">Ver todas</a>
    </div>
</form>

<div class="adi-card">
    <?php if (!$reservas): ?>
        <div class="adi-empty">
            <strong>No hay reservas con estos filtros</strong>
            Probá con otro rango de fechas o <a href="crear.php">registrá una nueva</a>.
        </div>
    <?php else: ?>
        <div class="adi-table-meta">
            <span><?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'reserva' : 'reservas' ?></span>
            <span>Ordenadas por fecha</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Franja</th>
                        <th>Espacio</th>
                        <th>Contacto</th>
                        <th class="adi-num">Monto</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reservas as $r):
                    $apartado = $r['estado'] === 'apartado';
                ?>
                    <tr>
                        <td class="adi-date text-nowrap">
                            <strong><?= diaLargo($r['fecha']) ?></strong>
                            <small><?= diaSemana($r['fecha']) ?></small>
                        </td>
                        <td><?= e(FRANJAS[$r['franja']] ?? $r['franja']) ?></td>
                        <td><?= e($espacios[(string) $r['espacio_id']] ?? '—') ?></td>
                        <td><?= e($contactos[(string) $r['contacto_id']] ?? '—') ?></td>
                        <td class="adi-num"><?= dinero($r['monto']) ?></td>
                        <td>
                            <?php if ($apartado): ?>
                                <span class="adi-pill adi-pill--warn">Apartado</span>
                                <div class="adi-sub mt-1">Vence <?= mostrarHora($r['vence']) ?></div>
                            <?php else: ?>
                                <span class="adi-pill adi-pill--ok">Confirmado</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="editar.php?id=<?= e($r['_id']) ?>" class="btn btn-sm adi-btn-ghost">Editar</a>
                            <?php if ($apartado): ?>
                                <form action="confirmar.php" method="POST" class="d-inline"
                                      onsubmit="return confirm('¿Confirmar el pago de esta reserva?');">
                                    <input type="hidden" name="id" value="<?= e($r['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-soft">Confirmar pago</button>
                                </form>
                            <?php endif; ?>
                            <form action="cancelar.php" method="POST" class="d-inline"
                                  onsubmit="return confirm('¿Cancelar esta reserva? El horario quedará libre.');">
                                <input type="hidden" name="id" value="<?= e($r['_id']) ?>">
                                <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Cancelar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php paginador($pagina, $paginas, $total, $porPagina); ?>
    <?php endif; ?>
</div>
<?php pie();
