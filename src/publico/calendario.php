<?php
/**
 * Calendario PÚBLICO de los espacios (vista de la comunidad, sin iniciar sesión).
 *
 * Solo muestra qué franjas están ocupadas. No muestra quién reservó, ni
 * teléfonos, ni montos cobrados, ni si el pago está confirmado: esos datos
 * son de la junta. Desde un día libre se puede pedir una reserva
 * (solicitar.php, exige sesión), que la junta aprueba o rechaza.
 *
 * Consulta: find() con proyección {_id: 0, espacio_id, fecha, franja}.
 * Los tres campos están en el índice único {espacio_id, fecha, franja},
 * así que MongoDB responde solo con el índice, sin leer los documentos
 * ("covered query": totalDocsExamined = 0 en explain()).
 */
// Página pública: el control de acceso (login) no debe exigir sesión aquí.
define('PAGINA_PUBLICA', true);

require_once dirname(__DIR__) . '/includes/alquileres.php';

use MongoDB\BSON\UTCDateTime;

const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                      'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DIAS_CORTOS  = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const MAX_EVENTOS_DIA = 3;

$utc = new DateTimeZone('UTC');
$hoy = hoyLocal();
$limite = (new DateTimeImmutable($hoy))->modify('+' . DIAS_ADELANTE_SOLICITUD . ' days')->format('Y-m-d');

// --- Mes y filtro -----------------------------------------------------------

$mes = texto($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = substr($hoy, 0, 7);
}
$primero    = DateTimeImmutable::createFromFormat('!Y-m-d', $mes . '-01', $utc);
$ultimo     = $primero->modify('last day of this month');
$inicioGrid = $primero->modify('-' . ((int) $primero->format('N') - 1) . ' days');
$finGrid    = $ultimo->modify('+' . (7 - (int) $ultimo->format('N')) . ' days');

// Solo espacios activos: los inactivos no se ofrecen a la comunidad
$espacios = array_filter(espaciosConColor($baseDatos), fn ($e) => $e['activo']);

$filtro = texto($_GET['espacio'] ?? '');
if (!isset($espacios[$filtro])) {
    $filtro = '';
}
$idsEspacio = $filtro !== '' ? [$espacios[$filtro]['id']] : array_column($espacios, 'id');

// --- Consulta (cubierta por el índice) ------------------------------------

$ocupadas = [];   // 'Y-m-d' => [['espacio' => id, 'franja' => ...], ...]
if ($idsEspacio) {
    $cursor = $baseDatos->selectCollection('reservas')->find(
        [
            'espacio_id' => ['$in' => array_values($idsEspacio)],
            'fecha'      => [
                '$gte' => new UTCDateTime($inicioGrid),
                '$lt'  => new UTCDateTime($finGrid->modify('+1 day')),
            ],
        ],
        ['projection' => ['_id' => 0, 'espacio_id' => 1, 'fecha' => 1, 'franja' => 1]]
    );
    foreach ($cursor as $r) {
        $ocupadas[diaTexto($r['fecha'])][] = ['espacio' => (string) $r['espacio_id'], 'franja' => $r['franja']];
    }
}

$ordenFranja = array_flip(array_keys(FRANJAS));
foreach ($ocupadas as &$lista) {
    usort($lista, fn ($a, $b) =>
        [$ordenFranja[$a['franja']] ?? 9, $espacios[$a['espacio']]['nombre'] ?? '']
        <=> [$ordenFranja[$b['franja']] ?? 9, $espacios[$b['espacio']]['nombre'] ?? '']);
}
unset($lista);

$dias = [];
for ($d = $inicioGrid; $d <= $finGrid; $d = $d->modify('+1 day')) {
    $clave = $d->format('Y-m-d');
    $dias[$clave] = [
        'numero'    => (int) $d->format('j'),
        'fuera'     => $d->format('Y-m') !== $mes,
        'pasado'    => $clave < $hoy,
        'hoy'       => $clave === $hoy,
        'pedible'   => $clave >= $hoy && $clave <= $limite,
        'titulo'    => DIAS[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . MESES_LARGOS[(int) $d->format('n') - 1],
    ];
}

function urlMesPublico(string $mes, string $filtro): string
{
    return 'calendario.php?' . http_build_query(array_filter(['mes' => $mes, 'espacio' => $filtro]));
}

// Datos para la tarjeta del día (sin información personal)
$datosJs = [
    'ocupadas' => $ocupadas,
    'dias'     => array_map(fn ($d) => ['titulo' => $d['titulo'], 'pedible' => $d['pedible'], 'pasado' => $d['pasado']], $dias),
    'espacios' => array_values(array_map(fn ($e) => [
        'id'      => (string) $e['id'],
        'nombre'  => $e['nombre'],
        'color'   => $e['color'],
        'tarifas' => array_map('dinero', $e['tarifas']),
    ], $filtro !== '' ? [$espacios[$filtro]] : $espacios)),
    'franjas'  => array_map(fn ($f) => ['etiqueta' => FRANJAS[$f], 'horario' => horarioFranja($f)],
                            array_combine(array_keys(FRANJAS), array_keys(FRANJAS))),
];

encabezadoPublico('Calendario de espacios', 'calendario');

tituloPagina(
    'Calendario de espacios',
    'Consultá qué días están libres el salón y la cancha. Tocá un día para ver las franjas disponibles y pedir una reserva a la junta.',
    ['texto' => 'Solicitar una reserva', 'url' => 'solicitar.php']
);
?>

<div class="adi-card adi-cal">
    <div class="adi-cal-toolbar">
        <div class="adi-cal-nav">
            <a class="btn btn-sm adi-btn-ghost adi-cal-arrow" href="<?= e(urlMesPublico($primero->modify('-1 month')->format('Y-m'), $filtro)) ?>" aria-label="Mes anterior">‹</a>
            <h2 class="adi-cal-month"><?= ucfirst(MESES_LARGOS[(int) $primero->format('n') - 1]) ?> <span><?= $primero->format('Y') ?></span></h2>
            <a class="btn btn-sm adi-btn-ghost adi-cal-arrow" href="<?= e(urlMesPublico($primero->modify('+1 month')->format('Y-m'), $filtro)) ?>" aria-label="Mes siguiente">›</a>
            <?php if ($mes !== substr($hoy, 0, 7)): ?>
                <a class="btn btn-sm adi-btn-soft" href="<?= e(urlMesPublico(substr($hoy, 0, 7), $filtro)) ?>">Hoy</a>
            <?php endif; ?>
        </div>
        <form method="GET" class="adi-cal-filter">
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <label for="espacio" class="visually-hidden">Espacio</label>
            <select class="form-select form-select-sm" id="espacio" name="espacio" onchange="this.form.submit()">
                <option value="">Todos los espacios</option>
                <?php foreach ($espacios as $idE => $esp): ?>
                    <option value="<?= e($idE) ?>" <?= $filtro === $idE ? 'selected' : '' ?>><?= e($esp['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm adi-btn-ghost">Ver</button></noscript>
        </form>
    </div>

    <?php if (!$espacios): ?>
        <div class="adi-empty"><strong>No hay espacios disponibles por ahora</strong></div>
    <?php else: ?>

    <div class="adi-cal-grid" role="grid" aria-label="Calendario del mes">
        <?php foreach (DIAS_CORTOS as $nombreDia): ?>
            <div class="adi-cal-head" role="columnheader"><?= $nombreDia ?></div>
        <?php endforeach; ?>

        <?php foreach ($dias as $clave => $dia):
            $lista = $ocupadas[$clave] ?? [];
            $clases = array_filter(['adi-cal-day', $dia['fuera'] ? 'is-out' : '', $dia['pasado'] ? 'is-past' : '', $dia['hoy'] ? 'is-today' : '']);
        ?>
            <div class="<?= implode(' ', $clases) ?>" role="gridcell" data-dia="<?= $clave ?>">
                <button type="button" class="adi-cal-date" data-dia="<?= $clave ?>"
                        aria-label="<?= e($dia['titulo']) ?>: <?= count($lista) ?> ocupadas">
                    <?= $dia['numero'] ?>
                </button>
                <?php foreach (array_slice($lista, 0, MAX_EVENTOS_DIA) as $o):
                    $esp = $espacios[$o['espacio']]; ?>
                    <span class="adi-cal-event is-confirmado" style="--esp: <?= e($esp['color']) ?>"
                          title="<?= e(FRANJAS[$o['franja']] . ' ' . horarioFranja($o['franja']) . ' · ' . $esp['nombre'] . ' · Ocupado') ?>">
                        <span class="adi-cal-time"><?= e(HORARIOS_FRANJA[$o['franja']][0]) ?></span>
                        <span class="adi-cal-label"><?= e($esp['nombre']) ?></span>
                    </span>
                <?php endforeach; ?>
                <?php if (count($lista) > MAX_EVENTOS_DIA): ?>
                    <button type="button" class="adi-cal-more" data-dia="<?= $clave ?>">+<?= count($lista) - MAX_EVENTOS_DIA ?> más</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Agenda (celular): días del mes desde hoy, con lo ocupado -->
    <div class="adi-cal-agenda">
        <?php $hayAlgo = false;
        foreach ($dias as $clave => $dia):
            if ($dia['fuera'] || $dia['pasado'] || empty($ocupadas[$clave])) continue;
            $hayAlgo = true; ?>
            <div class="adi-agenda-day <?= $dia['hoy'] ? 'is-today' : '' ?>">
                <button type="button" class="adi-agenda-date" data-dia="<?= $clave ?>">
                    <strong><?= $dia['numero'] ?></strong><span><?= e(explode(' ', $dia['titulo'])[0]) ?></span>
                </button>
                <div class="adi-agenda-events">
                    <?php foreach ($ocupadas[$clave] as $o): $esp = $espacios[$o['espacio']]; ?>
                        <span class="adi-cal-event is-confirmado" style="--esp: <?= e($esp['color']) ?>">
                            <span class="adi-cal-time"><?= e(HORARIOS_FRANJA[$o['franja']][0]) ?></span>
                            <span class="adi-cal-label"><?= e($esp['nombre']) ?> · Ocupado</span>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$hayAlgo): ?>
            <div class="adi-empty">
                <strong>Todo libre este mes</strong>
                <a href="solicitar.php">Pedí una reserva</a> para el día que necesités.
            </div>
        <?php endif; ?>
    </div>

    <footer class="adi-cal-key" aria-label="Cómo leer el calendario">
        <div class="adi-key-group">
            <h3 class="adi-legend-title">Espacios</h3>
            <ul class="adi-key-list">
                <?php foreach ($espacios as $idE => $esp): if ($filtro !== '' && $filtro !== $idE) continue; ?>
                    <li>
                        <span class="adi-key-swatch" style="--esp: <?= e($esp['color']) ?>"></span>
                        <span>
                            <strong><?= e($esp['nombre']) ?></strong>
                            <small><?= e($esp['tipo']) ?> · <?= e(implode(' · ', array_map(
                                fn ($f) => FRANJAS[$f] . ' ' . dinero($esp['tarifas'][$f]),
                                array_keys(array_intersect_key(FRANJAS, $esp['tarifas']))
                            ))) ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="adi-key-group">
            <h3 class="adi-legend-title">Cómo reservar</h3>
            <ul class="adi-key-list">
                <li><span><strong>1. Elegí un día libre</strong><small>Las franjas que aparecen están ocupadas.</small></span></li>
                <li><span><strong>2. Iniciá sesión y enviá la solicitud</strong><small>Con la cuenta de comunidad que te dio la junta.</small></span></li>
                <li><span><strong>3. La junta te confirma</strong><small>Revisá el estado en "Mis solicitudes". La junta te contacta para el pago.</small></span></li>
            </ul>
        </div>
    </footer>
    <?php endif; ?>
</div>

<dialog id="detalle" class="adi-dialog" aria-labelledby="detalle-titulo">
    <div id="detalle-contenido"></div>
</dialog>

<script id="datos-calendario" type="application/json"><?= json_encode($datosJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="calendario.js"></script>
<?php pie();
