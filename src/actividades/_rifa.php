<?php
/**
 * Sección de la rifa en el detalle de la actividad (la incluye ver.php).
 *
 * Usa las variables que prepara ver.php: $id, $abierta, $admin, $vendidos,
 * $libres, $precio, $numeros, $contactos, $paraVender, $mios y $misApartados.
 *
 * Administración: cuadrícula con vendidos y apartados, formulario para vender
 * y tabla de números con confirmar pago, liberar y anular.
 * Comunidad (Issue #114): cuadrícula con libres, ocupados y los suyos, y el
 * formulario para apartar un número. No ve quién tiene los demás números.
 */

if (!isset($actividad)) {   // solo se usa incluido desde ver.php
    http_response_code(404);
    exit;
}

$puedeElegir = $abierta && $precio !== null && $libres
    && ($admin || $misApartados < MAX_APARTADOS_RIFA);
?>
<div class="adi-rifa-layout">
    <div class="adi-card adi-card-body">
        <h2 class="adi-section-title">Números</h2>
        <div class="adi-rifa" role="list">
            <?php for ($n = 0; $n < NUMEROS_RIFA; $n++):
                $ocupado  = $vendidos[$n] ?? null;
                $mio      = $mios[$n] ?? null;
                $apartado = $admin
                    ? ($ocupado['estado'] ?? '') === 'apartado'
                    : ($mio['estado'] ?? '') === 'apartado';
            ?>
                <?php if ($ocupado):
                    if ($admin) {
                        $quien = $apartado
                            ? 'Apartado por ' . ($ocupado['nombre'] ?? 'una cuenta') . ' hasta ' . mostrarHora($ocupado['vence'])
                            : 'Vendido a ' . ($contactos[(string) ($ocupado['contacto_id'] ?? '')] ?? 'Contacto');
                    } elseif ($mio) {
                        $quien = $apartado ? 'Tuyo: apartado hasta ' . mostrarHora($mio['vence']) : 'Tuyo: pagado';
                    } else {
                        $quien = 'No disponible';
                    }
                    $clase = $mio ? 'is-mine' : ($apartado ? 'is-held' : 'is-sold');
                ?>
                    <span class="adi-rifa-num <?= $clase ?>" role="listitem" title="<?= e($quien) ?>">
                        <?= numeroRifa($n) ?>
                    </span>
                <?php elseif ($puedeElegir): ?>
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
            <?php if ($admin): ?>
                <span><span class="adi-rifa-swatch is-held"></span>Apartado (sin pagar)</span>
                <span><span class="adi-rifa-swatch is-sold"></span>Vendido</span>
            <?php else: ?>
                <span><span class="adi-rifa-swatch is-mine"></span>Tuyo</span>
                <span><span class="adi-rifa-swatch is-sold"></span>No disponible</span>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <?php if (!$abierta): ?>
            <div class="adi-card adi-empty">
                <strong>Rifa finalizada</strong>
                <?= $admin ? 'Ya no se venden números. Reabrila si necesitás corregir algo.' : 'Ya no se venden números.' ?>
            </div>
        <?php elseif ($precio === null): ?>
            <div class="adi-card adi-empty">
                <strong><?= $admin ? 'Falta el precio por número' : 'Todavía no se venden números' ?></strong>
                <?php if ($admin): ?>
                    <a href="form.php?id=<?= e($id) ?>">Editá la rifa</a> para indicarlo antes de vender.
                <?php else: ?>
                    La junta todavía no definió el precio de los números.
                <?php endif; ?>
            </div>
        <?php elseif (!$libres): ?>
            <div class="adi-card adi-empty">
                <strong>¡Todos los números están vendidos o apartados!</strong>
                <?= $admin ? 'La rifa está completa.' : 'Si alguien no paga su apartado a tiempo, el número vuelve a quedar libre.' ?>
            </div>
        <?php elseif ($admin): ?>
            <form action="../rifas/vender.php" method="POST" class="adi-card adi-card-body">
                <?= campoCsrf() ?>
                <h2 class="adi-section-title">Vender un número</h2>
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
        <?php elseif ($misApartados >= MAX_APARTADOS_RIFA): ?>
            <div class="adi-card adi-empty">
                <strong>Ya tenés <?= MAX_APARTADOS_RIFA ?> números apartados</strong>
                Pagalos a la junta o liberá alguno en <a href="mis.php">Mis compras</a> para apartar otro.
            </div>
        <?php else: ?>
            <form action="../rifas/apartar.php" method="POST" class="adi-card adi-card-body">
                <?= campoCsrf() ?>
                <h2 class="adi-section-title">Apartar un número</h2>
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
                <p class="adi-sub mb-0">
                    Queda a tu nombre por <?= HORAS_APARTADO_RIFA ?> horas. Pagáselo a una persona de la junta;
                    si no se confirma el pago a tiempo, el número se libera solo.
                </p>
                <div class="adi-total">
                    <span>Precio del número</span>
                    <strong><?= dinero($precio) ?></strong>
                </div>
                <button type="submit" class="btn adi-btn-primary">Apartar número</button>
            </form>
        <?php endif; ?>

        <?php if (!$admin && $mios): ?>
            <div class="adi-card adi-card-body mt-3">
                <h2 class="adi-section-title">Tus números en esta rifa</h2>
                <ul class="adi-rates mb-2">
                    <?php foreach ($mios as $numero => $m): $esApartado = ($m['estado'] ?? '') === 'apartado'; ?>
                        <li>
                            <span><span class="adi-rifa-num is-mine is-inline"><?= numeroRifa($numero) ?></span></span>
                            <strong>
                                <?php if ($esApartado): ?>
                                    <span class="adi-pill adi-pill--warn">Apartado</span>
                                    <span class="adi-sub d-block text-end mt-1">vence <?= mostrarHora($m['vence']) ?></span>
                                <?php else: ?>
                                    <span class="adi-pill adi-pill--ok">Pagado</span>
                                <?php endif; ?>
                            </strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a href="mis.php" class="adi-link-quiet adi-sub">Ver todo en Mis compras →</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($admin): ?>
<div class="adi-card mt-4">
    <?php if (!$numeros): ?>
        <div class="adi-empty">
            <strong>Todavía no se ha vendido ni apartado ningún número</strong>
            Elegí un número disponible en la cuadrícula para empezar.
        </div>
    <?php else: ?>
        <div class="adi-table-meta">
            <span><?= count($numeros) ?> <?= count($numeros) === 1 ? 'número ocupado' : 'números ocupados' ?></span>
            <span>Ordenados por número</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr><th>Número</th><th>Comprador</th><th>Estado</th><th class="adi-num">Monto</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($numeros as $n):
                    $esApartado = ($n['estado'] ?? '') === 'apartado';
                    $nombre = $esApartado
                        ? ($n['nombre'] ?? '—')
                        : ($contactos[(string) ($n['contacto_id'] ?? '')] ?? '—');
                ?>
                    <tr>
                        <td><span class="adi-rifa-num <?= $esApartado ? 'is-held' : 'is-sold' ?> is-inline"><?= numeroRifa($n['numero']) ?></span></td>
                        <td>
                            <?= e($nombre) ?>
                            <?php if ($esApartado && isset($n['correo'])): ?>
                                <div class="adi-sub"><?= e($n['correo']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($esApartado): ?>
                                <span class="adi-pill adi-pill--warn">Apartado</span>
                                <div class="adi-sub mt-1">Vence <?= mostrarHora($n['vence']) ?></div>
                            <?php else: ?>
                                <span class="adi-pill adi-pill--ok">Pagado</span>
                                <div class="adi-sub mt-1"><?= mostrarHora($n['fecha']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="adi-num"><?= dinero($n['monto']) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($abierta && $esApartado): ?>
                                <form action="../rifas/confirmar.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="Confirmar pago"
                                      data-confirmar="El número <?= numeroRifa($n['numero']) ?> queda vendido a <?= e($nombre) ?> y ya no vence. Si no está entre los contactos, se registra."
                                      data-confirmar-boton="Confirmar pago">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($n['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-soft">Confirmar pago</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($abierta): ?>
                                <form action="../rifas/anular.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="<?= $esApartado ? 'Liberar apartado' : 'Anular número' ?>"
                                      data-confirmar="El número <?= numeroRifa($n['numero']) ?> quedará libre otra vez."
                                      data-confirmar-boton="<?= $esApartado ? 'Liberar' : 'Anular' ?>" data-confirmar-peligro="1">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($n['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-quiet-danger"><?= $esApartado ? 'Liberar' : 'Anular' ?></button>
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
// Tocar un número disponible lo elige en el formulario (vender o apartar)
document.querySelectorAll('.adi-rifa-num[data-numero]').forEach(function (boton) {
    boton.addEventListener('click', function () {
        const select = document.getElementById('numero');
        if (!select) return;
        select.value = boton.dataset.numero;
        document.querySelectorAll('.adi-rifa-num.is-picked').forEach(b => b.classList.remove('is-picked'));
        boton.classList.add('is-picked');
        (document.getElementById('contacto') || select.form.querySelector('button[type="submit"]')).focus();
    });
});
</script>
