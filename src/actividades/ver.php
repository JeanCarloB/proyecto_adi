<?php
/**
 * Detalle de una actividad (Issues #32, #33 y #34).
 *
 * Turnos y ferias: total recaudado y productos más vendidos con aggregation
 * pipeline, y las ventas registradas.
 * Rifas: cuadrícula de los 100 números con los vendidos marcados y el
 * formulario para vender uno. El índice único {rifa_id, numero} garantiza
 * que un número no se venda dos veces.
 *
 * Vista por rol (Issue #80): administración ve todo y tiene las acciones.
 * La comunidad ve los totales, los productos más vendidos y qué números de
 * la rifa quedan libres, pero no quién compró ni el detalle de cada venta.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

const LIMITE_VENTAS = 100;

$actividad = buscarActividad($baseDatos, $_GET['id'] ?? '');
if (!$actividad) {
    http_response_code(404);
    exit('La actividad no existe.');
}

$id      = $actividad['_id'];
$esRifa  = $actividad['tipo'] === 'rifa';
$abierta = aceptaVentas($actividad);
$admin   = esAdmin();

// --- Totales con aggregation pipeline ---------------------------------------

$resumen = totalesPorActividad($baseDatos, [$id])[(string) $id] ?? ['n' => 0, 'total' => 0.0];

if ($esRifa) {
    if ($admin) {
        // Números vendidos con su comprador: como mucho 100 documentos, se traen todos.
        $numeros = $baseDatos->selectCollection('rifas')
            ->find(['rifa_id' => $id], ['sort' => ['numero' => 1]])
            ->toArray();
        $contactos  = nombresContactos($baseDatos, $numeros);
        $paraVender = $abierta ? contactosActivos($baseDatos) : [];
    } else {
        // Comunidad: solo qué números están vendidos. Con la proyección {_id: 0, numero: 1}
        // es una consulta CUBIERTA por el índice único {rifa_id, numero}: MongoDB responde
        // desde el índice sin abrir los documentos, así que no lee quién compró cada número.
        $numeros = $baseDatos->selectCollection('rifas')
            ->find(['rifa_id' => $id], ['projection' => ['_id' => 0, 'numero' => 1], 'sort' => ['numero' => 1]])
            ->toArray();
        $contactos  = [];
        $paraVender = [];
    }
    $vendidos = [];
    foreach ($numeros as $n) {
        $vendidos[(int) $n['numero']] = $n;
    }
    $libres = array_values(array_diff(range(0, NUMEROS_RIFA - 1), array_keys($vendidos)));
    $precio = $actividad['precio_numero'] ?? null;
} else {
    // Productos más vendidos: se "desenrolla" el arreglo de ítems y se agrupa por producto.
    $productos = $baseDatos->selectCollection('ventas')->aggregate([
        ['$match'  => ['actividad_id' => $id]],
        ['$unwind' => '$items'],
        ['$group'  => [
            '_id'      => '$items.producto',
            'unidades' => ['$sum' => '$items.cantidad'],
            'monto'    => ['$sum' => ['$multiply' => ['$items.cantidad', '$items.precio']]],
        ]],
        ['$sort'  => ['monto' => -1]],
        ['$limit' => 10],
    ])->toArray();

    // Últimas ventas, solo para administración (llevan el nombre de quien compró).
    // Usa el índice {actividad_id, fecha}: filtra y ordena sin ordenar en memoria.
    $ventas = $contactos = [];
    if ($admin) {
        $ventas = $baseDatos->selectCollection('ventas')
            ->find(['actividad_id' => $id], ['sort' => ['fecha' => -1], 'limit' => LIMITE_VENTAS])
            ->toArray();
        $contactos = nombresContactos($baseDatos, $ventas);
    }
}

encabezado($actividad['nombre'], 'actividades');
?>
<a href="index.php" class="adi-sub text-decoration-none d-inline-block mb-3">← Actividades</a>

<div class="adi-page-head">
    <div>
        <div class="adi-eyebrow">
            <span class="adi-dot adi-dot--ventas"></span><?= e(MODULOS['ventas']) ?>
        </div>
        <h1 class="adi-title"><?= e($actividad['nombre']) ?></h1>
        <p class="adi-lead d-flex flex-wrap align-items-center gap-2">
            <span class="adi-tag"><?= e(TIPOS_ACTIVIDAD[$actividad['tipo']] ?? $actividad['tipo']) ?></span>
            <span><?= e(ucfirst(diaSemana($actividad['fecha']))) ?> <?= diaLargo($actividad['fecha']) ?></span>
            <?= pillActividad($actividad['estado']) ?>
        </p>
    </div>
    <?php if ($admin): ?>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        <?php if ($abierta && !$esRifa): ?>
            <a href="../ventas/form.php?actividad=<?= e($id) ?>" class="btn adi-btn-primary text-nowrap">Registrar venta</a>
        <?php endif; ?>
        <a href="form.php?id=<?= e($id) ?>" class="btn adi-btn-ghost">Editar</a>
        <form action="estado.php" method="POST"
              onsubmit="return confirm('<?= $abierta
                  ? '¿Finalizar la actividad? Ya no se podrán registrar ni cambiar ventas.'
                  : '¿Reabrir la actividad? Volverá a aceptar ventas.' ?>');">
            <?= campoCsrf() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="estado" value="<?= $abierta ? 'finalizada' : 'programada' ?>">
            <button type="submit" class="btn <?= $abierta ? 'adi-btn-quiet-danger' : 'adi-btn-soft' ?>">
                <?= $abierta ? 'Finalizar' : 'Reabrir' ?>
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php
avisoExito([
    'creada'         => 'La actividad se registró correctamente.',
    'editada'        => 'Los cambios se guardaron.',
    'finalizada'     => 'La actividad se finalizó. Su total recaudado quedó cerrado.',
    'reabierta'      => 'La actividad volvió a quedar programada y acepta ventas.',
    'venta'          => 'La venta se registró.',
    'venta-editada'  => 'La venta se actualizó.',
    'venta-anulada'  => 'La venta se anuló y ya no suma al total.',
    'numero'         => 'Número ' . numeroRifa(texto($_GET['numero'] ?? '0')) . ' vendido.',
    'numero-anulado' => 'El número quedó libre otra vez.',
]);

$error = texto($_GET['error'] ?? '');
$mensajesError = [
    'repetido' => 'El número ' . numeroRifa(texto($_GET['numero'] ?? '0'))
        . ' ya se vendió. La base de datos no permite venderlo dos veces: elegí otro.',
    'cerrada'  => 'La actividad está finalizada: no acepta ventas ni cambios. Reabrila si necesitás corregir algo.',
    'datos'    => 'Revisá el número y el contacto: alguno no es válido.',
    'precio'   => 'Esta rifa no tiene precio por número. Editá la actividad para indicarlo.',
    'esquema'  => 'La base de datos rechazó la venta porque no cumple el esquema.',
    'general'  => 'No se pudo completar la operación. Intentá de nuevo.',
];
if (isset($mensajesError[$error])) {
    aviso($error === 'repetido' || $error === 'cerrada' ? 'warn' : 'danger', $mensajesError[$error]);
}
?>

<section class="adi-stats" aria-label="Resumen de la actividad">
    <div class="adi-stat">
        <div class="adi-stat-label">Total recaudado</div>
        <div class="adi-stat-value"><?= dinero($resumen['total']) ?></div>
    </div>
    <?php if ($esRifa): ?>
        <div class="adi-stat">
            <div class="adi-stat-label">Números vendidos</div>
            <div class="adi-stat-value"><?= $resumen['n'] ?> <span class="adi-sub">de <?= NUMEROS_RIFA ?></span></div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Disponibles</div>
            <div class="adi-stat-value"><?= count($libres) ?></div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Precio por número</div>
            <div class="adi-stat-value"><?= dinero($precio) ?></div>
        </div>
    <?php else: ?>
        <div class="adi-stat">
            <div class="adi-stat-label">Ventas</div>
            <div class="adi-stat-value"><?= number_format($resumen['n'], 0, ',', '.') ?></div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Venta promedio</div>
            <div class="adi-stat-value"><?= $resumen['n'] ? dinero($resumen['total'] / $resumen['n']) : '—' ?></div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Producto más vendido</div>
            <div class="adi-stat-value adi-stat-value--text"><?= $productos ? e($productos[0]['_id']) : '—' ?></div>
        </div>
    <?php endif; ?>
</section>

<?php if ($esRifa): ?>
    <?php // ------------------------------------------------------------- Rifa ?>
    <div class="adi-rifa-layout">
        <div class="adi-card adi-card-body">
            <h2 class="adi-section-title">Números</h2>
            <div class="adi-rifa" role="list">
                <?php for ($n = 0; $n < NUMEROS_RIFA; $n++):
                    $venta = $vendidos[$n] ?? null;
                    $quien = $venta && $admin ? ($contactos[(string) $venta['contacto_id']] ?? 'Contacto') : '';
                ?>
                    <?php if ($venta): ?>
                        <span class="adi-rifa-num is-sold" role="listitem"
                              title="<?= $quien !== '' ? 'Vendido a ' . e($quien) : 'Vendido' ?>">
                            <?= numeroRifa($n) ?>
                        </span>
                    <?php elseif ($admin && $abierta && $precio !== null): ?>
                        <button type="button" class="adi-rifa-num" role="listitem" data-numero="<?= $n ?>"
                                title="Disponible: elegir el <?= numeroRifa($n) ?>">
                            <?= numeroRifa($n) ?>
                        </button>
                    <?php else: ?>
                        <span class="adi-rifa-num" role="listitem"><?= numeroRifa($n) ?></span>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <div class="adi-rifa-legend">
                <span><span class="adi-rifa-swatch"></span>Disponible</span>
                <span><span class="adi-rifa-swatch is-sold"></span>Vendido</span>
            </div>
        </div>

        <div>
            <?php if (!$admin): ?>
                <?php // Comunidad: información para comprar, sin formulario de venta ?>
                <div class="adi-card adi-card-body">
                    <h2 class="adi-section-title">¿Cómo comprar un número?</h2>
                    <?php if (!$abierta): ?>
                        <p class="mb-0">La rifa ya terminó: no se venden más números.</p>
                    <?php elseif (!$libres): ?>
                        <p class="mb-0">¡Todos los números se vendieron!</p>
                    <?php else: ?>
                        <p>Elegí un número libre de la cuadrícula y pedíselo a una persona de la junta
                           o a los voluntarios de la ADI. Ellos lo registran a tu nombre.</p>
                        <div class="adi-total mb-0">
                            <span>Precio del número</span>
                            <strong><?= $precio !== null ? dinero($precio) : 'Por definir' ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif (!$abierta): ?>
                <div class="adi-card adi-empty">
                    <strong>Rifa finalizada</strong>
                    Ya no se venden números. Reabrila si necesitás corregir algo.
                </div>
            <?php elseif ($precio === null): ?>
                <div class="adi-card adi-empty">
                    <strong>Falta el precio por número</strong>
                    <a href="form.php?id=<?= e($id) ?>">Editá la rifa</a> para indicarlo antes de vender.
                </div>
            <?php elseif (!$libres): ?>
                <div class="adi-card adi-empty">
                    <strong>¡Todos los números se vendieron!</strong>
                    La rifa está completa.
                </div>
            <?php else: ?>
                <form action="../rifas/vender.php" method="POST" class="adi-card adi-card-body">
                    <h2 class="adi-section-title">Vender un número</h2>
                    <?= campoCsrf() ?>
                    <input type="hidden" name="rifa" value="<?= e($id) ?>">
                    <div class="mb-3">
                        <label for="numero" class="form-label">Número</label>
                        <select class="form-select" id="numero" name="numero" required>
                            <option value="">Elegí o tocá uno en la cuadrícula</option>
                            <?php foreach ($libres as $n): ?>
                                <option value="<?= $n ?>"><?= numeroRifa($n) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label for="contacto" class="form-label">Comprador</label>
                        <select class="form-select" id="contacto" name="contacto" required>
                            <option value="">¿Quién compra?</option>
                            <?php foreach ($paraVender as $c): ?>
                                <option value="<?= e($c['_id']) ?>"><?= e($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            ¿No aparece? <a href="<?= RAIZ ?>src/contactos/crear.php">Registrá el contacto</a>.
                        </div>
                    </div>
                    <div class="adi-total">
                        <span>Precio del número</span>
                        <strong><?= dinero($precio) ?></strong>
                    </div>
                    <button type="submit" class="btn adi-btn-primary">Vender número</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($admin): ?>
    <div class="adi-card mt-4">
        <?php if (!$numeros): ?>
            <div class="adi-empty">
                <strong>Todavía no se ha vendido ningún número</strong>
                Elegí un número disponible en la cuadrícula para empezar.
            </div>
        <?php else: ?>
            <div class="adi-table-meta">
                <span><?= count($numeros) ?> <?= count($numeros) === 1 ? 'número vendido' : 'números vendidos' ?></span>
                <span>Ordenados por número</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover adi-table">
                    <thead>
                        <tr><th>Número</th><th>Comprador</th><th>Vendido</th><th class="adi-num">Monto</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($numeros as $n): ?>
                        <tr>
                            <td><span class="adi-rifa-num is-sold is-inline"><?= numeroRifa($n['numero']) ?></span></td>
                            <td><?= e($contactos[(string) $n['contacto_id']] ?? '—') ?></td>
                            <td class="text-nowrap"><?= mostrarHora($n['fecha']) ?></td>
                            <td class="adi-num"><?= dinero($n['monto']) ?></td>
                            <td class="text-end">
                                <?php if ($abierta): ?>
                                    <form action="../rifas/anular.php" method="POST" class="d-inline"
                                          onsubmit="return confirm('¿Anular el número <?= numeroRifa($n['numero']) ?>? Quedará libre otra vez.');">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="id" value="<?= e($n['_id']) ?>">
                                        <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Anular</button>
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
    <?php endif; ?>

    <script>
    // Tocar un número disponible lo elige en el formulario
    document.querySelectorAll('.adi-rifa-num[data-numero]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const select = document.getElementById('numero');
            if (!select) return;
            select.value = boton.dataset.numero;
            document.querySelectorAll('.adi-rifa-num.is-picked').forEach(b => b.classList.remove('is-picked'));
            boton.classList.add('is-picked');
            document.getElementById('contacto').focus();
        });
    });
    </script>

<?php else: ?>
    <?php // --------------------------------------------------- Turno o feria ?>
    <div class="<?= $admin ? 'adi-ventas-layout' : '' ?>">
        <?php if ($admin): ?>
        <div class="adi-card">
            <?php if (!$ventas): ?>
                <div class="adi-empty">
                    <strong>Todavía no hay ventas</strong>
                    <?php if ($abierta): ?>
                        <a href="../ventas/form.php?actividad=<?= e($id) ?>">Registrá la primera venta</a>.
                    <?php else: ?>
                        La actividad se finalizó sin ventas registradas.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="adi-table-meta">
                    <span>
                        <?= count($ventas) === LIMITE_VENTAS
                            ? 'Últimas ' . LIMITE_VENTAS . ' de ' . number_format($resumen['n'], 0, ',', '.') . ' ventas'
                            : count($ventas) . ' ' . (count($ventas) === 1 ? 'venta' : 'ventas') ?>
                    </span>
                    <a class="adi-link-quiet" href="../ventas/index.php?actividad=<?= e($id) ?>">Ver todas con filtros</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover adi-table">
                        <thead>
                            <tr><th>Hora</th><th>Productos</th><th>Contacto</th><th class="adi-num">Total</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ventas as $v): ?>
                            <tr>
                                <td class="text-nowrap"><?= mostrarHora($v['fecha']) ?></td>
                                <td><?= e(resumenItems($v['items'])) ?></td>
                                <td><?= isset($v['contacto_id'])
                                    ? e($contactos[(string) $v['contacto_id']] ?? '—')
                                    : '<span class="adi-sub">—</span>' ?></td>
                                <td class="adi-num"><?= dinero($v['total']) ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($abierta): ?>
                                        <a href="../ventas/form.php?id=<?= e($v['_id']) ?>" class="btn btn-sm adi-btn-ghost">Editar</a>
                                        <form action="../ventas/anular.php" method="POST" class="d-inline"
                                              onsubmit="return confirm('¿Anular esta venta? Dejará de sumar al total.');">
                                            <?= campoCsrf() ?>
                                            <input type="hidden" name="id" value="<?= e($v['_id']) ?>">
                                            <input type="hidden" name="volver" value="actividad">
                                            <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Anular</button>
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
        <?php endif; ?>

        <div class="adi-card adi-card-body">
            <h2 class="adi-section-title">Productos más vendidos</h2>
            <?php if (!$productos): ?>
                <p class="adi-sub mb-0">Aparecen aquí cuando haya ventas.</p>
            <?php else: ?>
                <ul class="adi-rates">
                    <?php foreach ($productos as $p): ?>
                        <li>
                            <span><?= e($p['_id']) ?> <small class="adi-sub">· <?= (int) $p['unidades'] ?> u.</small></span>
                            <strong><?= dinero((string) $p['monto']) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="adi-sub mb-0">Calculado con <code>$unwind</code> + <code>$group</code> sobre todas las ventas.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php pie();
