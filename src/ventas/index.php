<?php
/**
 * Listado de ventas con filtros por actividad y por fecha (Issue #32).
 *
 * Las filas salen de un aggregation pipeline con $lookup a actividades y
 * contactos (Issue #82): una sola consulta trae la venta con el nombre de su
 * actividad y de quien compró. El $match + $sort + $limit del inicio usan un
 * índice: {actividad_id, fecha} si se filtra por actividad, o {fecha: -1}
 * si se filtra solo por fechas o no hay filtro. El resumen (cantidad y total)
 * se calcula con otro pipeline sobre todo el filtro, no solo sobre las filas
 * visibles.
 *
 * Solo administración (Issue #80): cada venta puede llevar el nombre de quien
 * compró. La comunidad ve los totales de cada actividad en actividades/.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

if (!esAdmin()) {
    header('Location: ../actividades/index.php');
    exit;
}

const LIMITE = 200;

// --- Filtros ----------------------------------------------------------------

$fActividad = texto($_GET['actividad'] ?? '');
$fDesde     = texto($_GET['desde']     ?? '');
$fHasta     = texto($_GET['hasta']     ?? '');

$filtro = [];
if ($idActividad = idValido($fActividad)) {
    $filtro['actividad_id'] = $idActividad;
}

$rango = [];
if ($fDesde !== '' && ($d = inicioDiaLocal($fDesde))) {
    $rango['$gte'] = $d;
}
if ($fHasta !== '' && ($h = inicioDiaLocal($fHasta, true))) {
    $rango['$lt'] = $h;   // hasta el final de ese día en hora de Costa Rica
}
if ($rango) {
    $filtro['fecha'] = $rango;
}

// --- Consulta ---------------------------------------------------------------

$coleccion = $baseDatos->selectCollection('ventas');

// $match, $sort y $limit van primero: MongoDB los resuelve con el índice y el
// $lookup solo trabaja sobre las 200 ventas que se muestran, no sobre todas.
$ventas = $coleccion->aggregate([
    ['$match' => (object) $filtro],
    ['$sort'  => ['fecha' => -1]],
    ['$limit' => LIMITE],
    ['$lookup' => ['from' => 'actividades', 'localField' => 'actividad_id', 'foreignField' => '_id', 'as' => 'actividad']],
    ['$unwind' => ['path' => '$actividad', 'preserveNullAndEmptyArrays' => true]],
    ['$lookup' => ['from' => 'contactos', 'localField' => 'contacto_id', 'foreignField' => '_id', 'as' => 'contacto']],
    ['$unwind' => ['path' => '$contacto', 'preserveNullAndEmptyArrays' => true]],
    ['$project' => [
        'fecha' => 1, 'items' => 1, 'total' => 1, 'actividad_id' => 1,
        'actividad.nombre' => 1, 'actividad.estado' => 1, 'contacto.nombre' => 1,
    ]],
])->toArray();

$resumen = ['n' => 0, 'total' => 0.0];

require_once dirname(__DIR__) . '/includes/redis.php';

$redis = conexionRedis();

$claveResumen = 'administra:ventas:resumen:' . hash(
    'sha256',
    json_encode([
        'actividad' => isset($filtro['actividad_id'])
            ? (string) $filtro['actividad_id']
            : null,
        'desde' => $fDesde,
        'hasta' => $fHasta,
    ])
);


$desdeCache = false;

if ($redis !== null) {
    try {
        $guardado = $redis->get($claveResumen);

        if ($guardado !== null) {
            $datos = json_decode($guardado, true);

            if (
                is_array($datos)
                && isset($datos['n'], $datos['total'])
                && is_numeric($datos['n'])
                && is_numeric($datos['total'])
            ) {
                $resumen = [
                    'n' => (int) $datos['n'],
                    'total' => (float) $datos['total'],
                ];
                $desdeCache = true;
            }
        }
    } catch (Throwable $e) {
        error_log('ADIministra: no se pudo leer la caché de ventas: ' . $e->getMessage());
    }
}

if (!$desdeCache) {
    foreach ($coleccion->aggregate([
        ['$match' => (object) $filtro],
        ['$group' => [
            '_id' => null,
            'n' => ['$sum' => 1],
            'total' => ['$sum' => '$total'],
        ]],
    ]) as $g) {
        $resumen = [
            'n' => (int) $g['n'],
            'total' => (float) (string) $g['total'],
        ];
    }

    if ($redis !== null) {
        try {
            $redis->setex(
                $claveResumen,
                60,
                json_encode($resumen, JSON_THROW_ON_ERROR)
            );
        } catch (Throwable $e) {
            error_log('ADIministra: no se pudo guardar la caché de ventas: ' . $e->getMessage());
        }
    }
}

// Turnos y ferias para el filtro de actividad
$actividades = [];
foreach ($baseDatos->selectCollection('actividades')->find(
    ['tipo' => ['$in' => ['turno', 'feria']]],
    ['sort' => ['fecha' => -1], 'projection' => ['nombre' => 1, 'estado' => 1]]
) as $a) {
    $actividades[(string) $a['_id']] = $a;
}

encabezado('Ventas', 'ventas');

tituloPagina(
    'Ventas',
    'Lo que se vendió en turnos y ferias. Cada venta queda asociada a su actividad.',
    ['texto' => 'Registrar venta', 'url' => 'form.php' . ($idActividad ? '?actividad=' . $idActividad : '')],
    'ventas'
);

avisoExito(['anulada' => 'La venta se anuló y ya no suma al total.']);
if (($_GET['error'] ?? '') === 'cerrada') {
    aviso('warn', 'No se puede anular: la actividad de esa venta ya está finalizada.');
}
?>

<section class="adi-stats adi-stats--3" aria-label="Resumen del filtro">
    <div class="adi-stat">
        <div class="adi-stat-label">Ventas</div>
        <div class="adi-stat-value"><?= number_format($resumen['n'], 0, ',', '.') ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label">Total recaudado</div>
        <div class="adi-stat-value"><?= dinero($resumen['total']) ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label">Venta promedio</div>
        <div class="adi-stat-value"><?= $resumen['n'] ? dinero($resumen['total'] / $resumen['n']) : '—' ?></div>
    </div>
</section>

<form method="GET" class="adi-card adi-filters mb-4">
    <div>
        <label for="actividad" class="form-label">Actividad</label>
        <select class="form-select" id="actividad" name="actividad">
            <option value="">Todas las actividades</option>
            <?php foreach ($actividades as $idA => $a): ?>
                <option value="<?= e($idA) ?>" <?= $fActividad === $idA ? 'selected' : '' ?>>
                    <?= e($a['nombre']) ?><?= $a['estado'] === 'finalizada' ? ' (finalizada)' : '' ?>
                </option>
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
    <div class="d-flex gap-2">
        <button type="submit" class="btn adi-btn-primary">Filtrar</button>
        <a href="index.php" class="btn adi-btn-ghost text-nowrap">Ver todas</a>
    </div>
</form>

<div class="adi-card">
    <?php if (!$ventas): ?>
        <div class="adi-empty">
            <strong>No hay ventas con estos filtros</strong>
            Probá con otro rango de fechas o <a href="form.php">registrá una venta</a>.
        </div>
    <?php else: ?>
        <div class="adi-table-meta">
            <span>
                <?= count($ventas) === LIMITE && $resumen['n'] > LIMITE
                    ? 'Mostrando las ' . LIMITE . ' más recientes de ' . number_format($resumen['n'], 0, ',', '.')
                    : count($ventas) . ' ' . (count($ventas) === 1 ? 'venta' : 'ventas') ?>
            </span>
            <span>Más recientes primero</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Actividad</th>
                        <th>Productos</th>
                        <th>Contacto</th>
                        <th class="adi-num">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ventas as $v):
                    $act     = $v['actividad'] ?? null;   // del $lookup
                    $abierta = $act !== null && ($act['estado'] ?? '') === 'programada';
                ?>
                    <tr>
                        <td class="text-nowrap"><?= mostrarHora($v['fecha']) ?>
                            <div class="adi-sub"><?= $v['fecha']->toDateTime()->format('Y') ?></div>
                        </td>
                        <td>
                            <?php if ($act): ?>
                                <a class="adi-link-quiet" href="../actividades/ver.php?id=<?= e($v['actividad_id']) ?>"><?= e($act['nombre']) ?></a>
                            <?php else: ?>
                                <span class="adi-sub">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(resumenItems($v['items'])) ?></td>
                        <td><?= isset($v['contacto']['nombre'])
                            ? e($v['contacto']['nombre'])
                            : '<span class="adi-sub">—</span>' ?></td>
                        <td class="adi-num"><?= dinero($v['total']) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($abierta): ?>
                                <a href="form.php?id=<?= e($v['_id']) ?>" class="btn btn-sm adi-btn-ghost">Editar</a>
                                <form action="anular.php" method="POST" class="d-inline"
                                      onsubmit="return confirm('¿Anular esta venta? Dejará de sumar al total.');">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($v['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Anular</button>
                                </form>
                            <?php else: ?>
                                <span class="adi-sub">Cerrada</span>
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
