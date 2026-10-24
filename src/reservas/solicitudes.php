<?php
/**
 * Bandeja de solicitudes de la comunidad (vista de la junta).
 *
 * Las solicitudes llegan desde la página pública src/publico/solicitar.php.
 * Aprobar crea la reserva como "apartado" (con su vencimiento de 48 h, igual
 * que una reserva hecha aquí) dentro de una transacción; rechazar la cierra.
 * Las que nadie atiende las borra el índice TTL a los DIAS_SOLICITUD días.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';
requiereRol('admin');

$espacios = espaciosConColor($baseDatos);
$vista    = texto($_GET['ver'] ?? '') === 'resueltas' ? 'resueltas' : 'pendientes';
$coleccion = $baseDatos->selectCollection('solicitudes');

// Pendientes: de la más antigua a la más nueva (índice {estado, creada}).
// Resueltas: las 30 más recientes.
$solicitudes = $vista === 'pendientes'
    ? $coleccion->find(['estado' => 'pendiente'], ['sort' => ['creada' => 1]])->toArray()
    : $coleccion->find(['estado' => ['$in' => ['aprobada', 'rechazada']]], ['sort' => ['resuelta' => -1], 'limit' => 30])->toArray();

$cuantasPendientes = $vista === 'pendientes'
    ? count($solicitudes)
    : $coleccion->countDocuments(['estado' => 'pendiente']);

// ¿Ya se ocupó el horario que piden? Una sola consulta para toda la página.
$ocupado = [];
if ($vista === 'pendientes' && $solicitudes) {
    $or = array_map(fn ($s) => ['espacio_id' => $s['espacio_id'], 'fecha' => $s['fecha'], 'franja' => $s['franja']], $solicitudes);
    foreach ($baseDatos->selectCollection('reservas')->find(['$or' => $or], ['projection' => ['_id' => 0, 'espacio_id' => 1, 'fecha' => 1, 'franja' => 1]]) as $r) {
        $ocupado[(string) $r['espacio_id'] . '|' . diaTexto($r['fecha']) . '|' . $r['franja']] = true;
    }
}

encabezado('Solicitudes', 'solicitudes');

tituloPagina(
    'Solicitudes de la comunidad',
    'Pedidos hechos desde el calendario público. Al aprobar, se aparta el espacio por ' . HORAS_APARTADO
        . ' horas mientras se confirma el pago. Las que no se atienden vencen solas a los ' . DIAS_SOLICITUD . ' días.',
    ['texto' => 'Ver calendario público', 'url' => RAIZ . 'src/publico/calendario.php']
);

avisoExito([
    'aprobada'  => 'Solicitud aprobada: la reserva quedó apartada y el contacto registrado.',
    'rechazada' => 'Solicitud rechazada.',
]);
$errores = [
    'ocupado'   => 'No se pudo aprobar: ese horario ya está reservado. Podés rechazar la solicitud.',
    'resuelta'  => 'Esa solicitud ya no está pendiente (alguien la atendió o venció).',
    'tarifa'    => 'No se pudo aprobar: el espacio ya no tiene tarifa para esa franja o está inactivo.',
    'fallo'     => 'No se pudo completar la operación. No se guardó ningún cambio.',
];
if (isset($errores[$_GET['error'] ?? ''])) {
    aviso('warn', $errores[$_GET['error']]);
}
?>

<div class="adi-tabs mb-3" role="tablist">
    <a href="solicitudes.php" class="<?= $vista === 'pendientes' ? 'is-active' : '' ?>">
        Pendientes <span class="adi-count"><?= $cuantasPendientes ?></span>
    </a>
    <a href="solicitudes.php?ver=resueltas" class="<?= $vista === 'resueltas' ? 'is-active' : '' ?>">Resueltas</a>
</div>

<div class="adi-card">
    <?php if (!$solicitudes): ?>
        <div class="adi-empty">
            <strong><?= $vista === 'pendientes' ? 'No hay solicitudes pendientes' : 'Todavía no hay solicitudes resueltas' ?></strong>
            Las solicitudes llegan desde el <a href="<?= RAIZ ?>src/publico/calendario.php">calendario público</a>.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table adi-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Espacio</th>
                        <th>Solicitante</th>
                        <th class="adi-num">Tarifa</th>
                        <th><?= $vista === 'pendientes' ? 'Recibida' : 'Estado' ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($solicitudes as $s):
                    $esp   = $espacios[(string) $s['espacio_id']] ?? null;
                    $clave = (string) $s['espacio_id'] . '|' . diaTexto($s['fecha']) . '|' . $s['franja'];
                    $libre = !isset($ocupado[$clave]);
                ?>
                    <tr>
                        <td class="adi-date text-nowrap">
                            <strong><?= diaLargo($s['fecha']) ?></strong>
                            <small><?= diaSemana($s['fecha']) ?> · <?= e(FRANJAS[$s['franja']] ?? $s['franja']) ?> <?= e(horarioFranja($s['franja'])) ?></small>
                        </td>
                        <td>
                            <?php if ($esp): ?><span class="adi-dot" style="background: <?= e($esp['color']) ?>"></span><?php endif; ?>
                            <?= e($esp['nombre'] ?? 'Espacio eliminado') ?>
                        </td>
                        <td>
                            <strong><?= e($s['nombre']) ?></strong>
                            <div class="adi-sub">
                                <a class="adi-link-quiet" href="mailto:<?= e($s['correo']) ?>"><?= e($s['correo']) ?></a>
                                <?= isset($s['telefono']) ? ' · ' . e($s['telefono']) : '' ?>
                            </div>
                            <?php if (!empty($s['mensaje'])): ?>
                                <div class="adi-quote"><?= e($s['mensaje']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="adi-num"><?= $esp && isset($esp['tarifas'][$s['franja']]) ? dinero($esp['tarifas'][$s['franja']]) : '—' ?></td>
                        <td class="text-nowrap">
                            <?php if ($vista === 'pendientes'): ?>
                                <?= e(mostrarHora($s['creada'])) ?>
                                <?php if (!$libre): ?>
                                    <div><span class="adi-pill adi-pill--warn">Horario ya ocupado</span></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="adi-pill <?= $s['estado'] === 'aprobada' ? 'adi-pill--ok' : 'adi-pill--neutral' ?>">
                                    <?= $s['estado'] === 'aprobada' ? 'Aprobada' : 'Rechazada' ?>
                                </span>
                                <div class="adi-sub mt-1"><?= isset($s['resuelta']) ? e(mostrarHora($s['resuelta'])) : '' ?></div>
                                <?php if (!empty($s['motivo'])): ?><div class="adi-sub"><?= e($s['motivo']) ?></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($vista === 'pendientes'): ?>
                                <button type="button" class="btn btn-sm adi-btn-quiet-danger js-rechazar"
                                        data-id="<?= e($s['_id']) ?>"
                                        data-resumen="<?= e(($esp['nombre'] ?? 'Espacio') . ' · ' . diaLargo($s['fecha']) . ' · ' . (FRANJAS[$s['franja']] ?? $s['franja'])) ?>"
                                        data-nombre="<?= e($s['nombre']) ?>">Rechazar</button>
                                <?php if ($libre): ?>
                                    <form action="resolver_solicitud.php" method="POST" class="d-inline"
                                          onsubmit="return confirm('¿Aprobar? Se aparta el espacio por <?= HORAS_APARTADO ?> horas a nombre de esta persona.');">
                                        <input type="hidden" name="id" value="<?= e($s['_id']) ?>">
                                        <input type="hidden" name="accion" value="aprobar">
                                        <button type="submit" class="btn btn-sm adi-btn-primary">Aprobar</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif (isset($s['reserva_id'])): ?>
                                <a class="btn btn-sm adi-btn-ghost" href="calendario.php?mes=<?= e(substr(diaTexto($s['fecha']), 0, 7)) ?>">Ver en calendario</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Cuadro para rechazar con motivo (reemplaza al prompt() del navegador) -->
<dialog id="rechazo" class="adi-dialog" aria-labelledby="rechazo-titulo">
    <form action="resolver_solicitud.php" method="POST">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="accion" value="rechazar">

        <div class="adi-dialog-head">
            <div>
                <div class="adi-dialog-eyebrow"><span class="adi-dot" style="background: var(--adi-danger)"></span>Rechazar solicitud</div>
                <h2 class="adi-dialog-title" id="rechazo-titulo"></h2>
                <div class="adi-sub mt-1" id="rechazo-nombre"></div>
            </div>
            <button type="button" class="adi-dialog-close" data-cerrar aria-label="Cerrar">&times;</button>
        </div>

        <label for="rechazo-motivo" class="form-label">Motivo <span class="adi-sub">(opcional, lo ve la persona en "Mis solicitudes")</span></label>
        <textarea id="rechazo-motivo" name="motivo" class="form-control" rows="3" maxlength="200"
                  placeholder="Por ejemplo: el espacio está en mantenimiento ese día"></textarea>
        <div class="adi-motivo-pie">
            <div class="adi-chips" aria-label="Motivos frecuentes">
                <button type="button" class="adi-chip">El espacio está en mantenimiento</button>
                <button type="button" class="adi-chip">Ese día hay una actividad de la ADI</button>
                <button type="button" class="adi-chip">Faltan datos para contactarle</button>
            </div>
            <span class="adi-sub" id="rechazo-cuenta">0/200</span>
        </div>

        <div class="adi-dialog-actions">
            <button type="button" class="btn adi-btn-ghost" data-cerrar>Cancelar</button>
            <button type="submit" class="btn adi-btn-danger">Rechazar solicitud</button>
        </div>
    </form>
</dialog>

<script>
(() => {
    const dlg = document.getElementById('rechazo');
    const form = dlg.querySelector('form');
    const motivo = document.getElementById('rechazo-motivo');
    const cuenta = document.getElementById('rechazo-cuenta');
    const contar = () => { cuenta.textContent = motivo.value.length + '/200'; };

    document.querySelectorAll('.js-rechazar').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.querySelector('[name=id]').value = btn.dataset.id;
            document.getElementById('rechazo-titulo').textContent = btn.dataset.resumen;
            document.getElementById('rechazo-nombre').textContent = 'Solicitada por ' + btn.dataset.nombre;
            motivo.value = '';
            contar();
            dlg.showModal();
            motivo.focus();
        });
    });
    dlg.querySelectorAll('.adi-chip').forEach((chip) => {
        chip.addEventListener('click', () => { motivo.value = chip.textContent; contar(); motivo.focus(); });
    });
    motivo.addEventListener('input', contar);
    dlg.querySelectorAll('[data-cerrar]').forEach((b) => b.addEventListener('click', () => dlg.close()));
    dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); });   // clic fuera
    form.addEventListener('submit', () => { form.querySelector('[type=submit]').disabled = true; });
})();
</script>
<?php pie();
