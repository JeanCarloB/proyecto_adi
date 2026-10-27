<?php
/**
 * Sección de un turno o una feria en el detalle de la actividad (la incluye ver.php).
 *
 * Usa las variables que prepara ver.php: $id, $abierta, $admin, $resumen,
 * $productos, $ventas, $contactos, $menu, $encargosPendientes y $misEncargos.
 *
 * Administración: encargos pendientes para entregar o cancelar, ventas
 * registradas y productos más vendidos.
 * Comunidad (Issue #115): menú para encargar, sus encargos en esta actividad
 * y los productos más vendidos. No ve las ventas ni los encargos de otros.
 */

if (!isset($actividad)) {   // solo se usa incluido desde ver.php
    http_response_code(404);
    exit;
}

$estadosEncargo = [
    'pendiente' => ['adi-pill--warn', 'Pendiente'],
    'entregado' => ['adi-pill--ok', 'Entregado'],
    'cancelado' => ['adi-pill--neutral', 'Cancelado'],
];
?>
<?php if ($admin && $encargosPendientes): ?>
    <div class="adi-card mb-4">
        <div class="adi-table-meta">
            <span><strong><?= count($encargosPendientes) ?></strong>
                <?= count($encargosPendientes) === 1 ? 'encargo pendiente' : 'encargos pendientes' ?> de la comunidad</span>
            <span>Del más viejo al más nuevo</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr><th>Encargó</th><th>Productos</th><th>Nota</th><th class="adi-num">Total</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($encargosPendientes as $en): ?>
                    <tr>
                        <td>
                            <?= e($en['nombre']) ?>
                            <div class="adi-sub"><?= e($en['correo']) ?> · <?= mostrarHora($en['creado']) ?></div>
                        </td>
                        <td><?= e(resumenItems($en['items'])) ?></td>
                        <td><?= isset($en['nota']) ? e($en['nota']) : '<span class="adi-sub">—</span>' ?></td>
                        <td class="adi-num"><?= dinero($en['total']) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($abierta): ?>
                                <form action="../encargos/entregar.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="Entregar encargo"
                                      data-confirmar="Se registra una venta de <?= e(dinero($en['total'])) ?> a nombre de <?= e($en['nombre']) ?>. Si no está entre los contactos, se registra."
                                      data-confirmar-boton="Entregar">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($en['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-soft">Entregar</button>
                                </form>
                                <form action="../encargos/cancelar.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="Cancelar encargo"
                                      data-confirmar="El encargo de <?= e($en['nombre']) ?> se cancela y no se registra ninguna venta."
                                      data-confirmar-boton="Cancelar encargo" data-confirmar-peligro="1">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($en['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Cancelar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="adi-ventas-layout">
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
                                          data-confirmar-titulo="Anular venta"
                                          data-confirmar="La venta deja de sumar al total recaudado."
                                          data-confirmar-boton="Anular" data-confirmar-peligro="1">
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
    <?php else: ?>
    <div>
        <?php if (!$abierta): ?>
            <div class="adi-card adi-empty">
                <strong>Esta actividad ya terminó</strong>
                No recibe más encargos.
            </div>
        <?php elseif (!$menu): ?>
            <div class="adi-card adi-empty">
                <strong>Esta actividad no recibe encargos</strong>
                Podés comprar directamente el día de la actividad.
            </div>
        <?php else: ?>
            <form action="../encargos/crear.php" method="POST" class="adi-card adi-card-body" id="form-encargo">
                <?= campoCsrf() ?>
                <input type="hidden" name="actividad" value="<?= e($id) ?>">
                <h2 class="adi-section-title mb-1">Encargar productos</h2>
                <p class="adi-sub mb-3">Indicá cuántas unidades querés. La junta te lo entrega en la actividad y lo pagás al recibirlo.</p>
                <ul class="adi-encargo-lista">
                    <?php foreach ($menu as $i => $m): ?>
                        <li>
                            <label for="cantidad-<?= $i ?>">
                                <strong><?= e($m['producto']) ?></strong>
                                <span class="adi-sub"><?= dinero($m['precio']) ?> c/u</span>
                            </label>
                            <input type="number" class="form-control" id="cantidad-<?= $i ?>" name="cantidad[<?= $i ?>]"
                                   min="0" max="<?= MAX_UNIDADES_ENCARGO ?>" step="1" value="0"
                                   data-precio="<?= (float) (string) $m['precio'] ?>" aria-label="Unidades de <?= e($m['producto']) ?>">
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="mb-1">
                    <label for="nota" class="form-label">Nota <span class="adi-sub">(opcional)</span></label>
                    <input type="text" class="form-control" id="nota" name="nota" maxlength="200"
                           placeholder="Por ejemplo: lo recojo a las 10 a. m.">
                </div>
                <div class="adi-total">
                    <span>Total del encargo</span>
                    <strong id="total-encargo">—</strong>
                </div>
                <button type="submit" class="btn adi-btn-primary">Encargar</button>
            </form>
        <?php endif; ?>

        <?php if ($misEncargos): ?>
            <div class="adi-card adi-card-body mt-3">
                <h2 class="adi-section-title">Tus encargos en esta actividad</h2>
                <ul class="adi-rates mb-2">
                    <?php foreach ($misEncargos as $en): [$clase, $texto] = $estadosEncargo[$en['estado']]; ?>
                        <li>
                            <span><?= e(resumenItems($en['items'])) ?> <small class="adi-sub">· <?= dinero($en['total']) ?></small></span>
                            <strong><span class="adi-pill <?= $clase ?>"><?= e($texto) ?></span></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a href="mis.php" class="adi-link-quiet adi-sub">Ver todo en Mis compras →</a>
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
        <?php if ($admin && $menu): ?>
            <p class="adi-sub mt-3 mb-0">Esta actividad recibe encargos: <?= count($menu) ?> productos en el menú.
                <a href="form.php?id=<?= e($id) ?>">Editar menú</a></p>
        <?php endif; ?>
    </div>
</div>

<?php if (!$admin && $abierta && $menu): ?>
<script>
// Vista previa del total del encargo; el servidor lo vuelve a calcular con los precios del menú
(function () {
    const form = document.getElementById('form-encargo');
    const total = document.getElementById('total-encargo');
    const colones = n => '₡' + String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    function recalcular() {
        let suma = 0;
        form.querySelectorAll('input[data-precio]').forEach(function (i) {
            suma += (parseInt(i.value, 10) || 0) * parseFloat(i.dataset.precio);
        });
        total.textContent = suma ? colones(suma) : '—';
    }
    form.addEventListener('input', recalcular);
    recalcular();
})();
</script>
<?php endif; ?>
