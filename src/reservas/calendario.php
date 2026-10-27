<?php
/**
 * Calendario mensual de ocupación (Issue #14).
 *
 * Una sola consulta (aggregation pipeline) trae las reservas de las semanas
 * visibles junto con los datos del contacto ($lookup). El filtro usa
 * espacio_id con $in + rango de fechas para que MongoDB aproveche el índice
 * único {espacio_id, fecha, franja}: sus dos primeros campos sirven de prefijo.
 * Filtrar SOLO por fecha no podría usar ese índice (fecha no es el primer
 * campo) y terminaría en un COLLSCAN.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';

use MongoDB\BSON\UTCDateTime;

const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                      'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DIAS_CORTOS  = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const MAX_EVENTOS_DIA = 3;

$utc = new DateTimeZone('UTC');
$hoy = hoyLocal();

// --- Mes y filtro -----------------------------------------------------------

$mes = texto($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = substr($hoy, 0, 7);
}
$primero = DateTimeImmutable::createFromFormat('!Y-m-d', $mes . '-01', $utc);
$ultimo  = $primero->modify('last day of this month');

// La cuadrícula empieza el lunes anterior al día 1 y termina el domingo posterior al último día
$inicioGrid = $primero->modify('-' . ((int) $primero->format('N') - 1) . ' days');
$finGrid    = $ultimo->modify('+' . (7 - (int) $ultimo->format('N')) . ' days');

$mesAnterior  = $primero->modify('-1 month')->format('Y-m');
$mesSiguiente = $primero->modify('+1 month')->format('Y-m');

// Espacios: todos (también los inactivos, porque pueden tener reservas viejas)
$espacios = [];
$i = 0;
foreach ($baseDatos->selectCollection('espacios')->find([], ['sort' => ['nombre' => 1]]) as $esp) {
    $tarifas = [];
    foreach ($esp['tarifas'] ?? [] as $t) {
        $tarifas[$t['franja']] = dinero($t['monto']);
    }
    $espacios[(string) $esp['_id']] = [
        'id'      => $esp['_id'],
        'nombre'  => $esp['nombre'],
        'tipo'    => TIPOS_ESPACIO[$esp['tipo'] ?? ''] ?? '',
        'activo'  => ($esp['activo'] ?? true) === true,
        'color'   => COLORES_ESPACIO[$i++ % count(COLORES_ESPACIO)],
        'tarifas' => $tarifas,
    ];
}

$filtro = texto($_GET['espacio'] ?? '');
if (!isset($espacios[$filtro])) {
    $filtro = '';
}
$idsEspacio = $filtro !== ''
    ? [$espacios[$filtro]['id']]
    : array_column($espacios, 'id');

// --- Consulta ---------------------------------------------------------------

$pipeline = [
    ['$match' => [
        'espacio_id' => ['$in' => array_values($idsEspacio)],
        'fecha'      => [
            '$gte' => new UTCDateTime($inicioGrid),
            '$lt'  => new UTCDateTime($finGrid->modify('+1 day')),
        ],
    ]],
    // Las reservas guardan solo la referencia al contacto; $lookup trae sus datos
    ['$lookup' => [
        'from'         => 'contactos',
        'localField'   => 'contacto_id',
        'foreignField' => '_id',
        'as'           => 'contacto',
    ]],
    ['$unwind' => ['path' => '$contacto', 'preserveNullAndEmptyArrays' => true]],
    ['$project' => [
        'espacio_id' => 1, 'contacto_id' => 1, 'fecha' => 1, 'franja' => 1, 'estado' => 1, 'monto' => 1, 'vence' => 1,
        'contacto.nombre' => 1, 'contacto.tipo' => 1, 'contacto.telefono' => 1, 'contacto.correo' => 1,
    ]],
];

$ordenFranja = array_flip(array_keys(FRANJAS));
$porDia   = [];   // 'Y-m-d' => [reservas...]
$reservas = [];   // id => datos para la tarjeta de detalle (JavaScript)
$resumen  = ['total' => 0, 'apartado' => 0, 'confirmado' => 0];
$porEspacio = [];  // reservas del mes por espacio, para la leyenda de colores

if ($idsEspacio) {
    foreach ($baseDatos->selectCollection('reservas')->aggregate($pipeline) as $r) {
        $id      = (string) $r['_id'];
        $dia     = $r['fecha']->toDateTime()->setTimezone($utc)->format('Y-m-d');
        $espacio = $espacios[(string) $r['espacio_id']] ?? null;
        $apartado = $r['estado'] === 'apartado';

        $vence = null;
        if ($apartado && isset($r['vence'])) {
            $horas = (int) ceil(((int) (string) $r['vence'] / 1000 - time()) / 3600);
            $vence = [
                'texto' => mostrarHora($r['vence']),
                'falta' => $horas > 1 ? "en {$horas} h" : ($horas === 1 ? 'en menos de 1 h' : 'en breve'),
            ];
        }

        $reservas[$id] = [
            'id'       => $id,
            'dia'      => $dia,
            'franja'   => $r['franja'],
            'etFranja' => FRANJAS[$r['franja']] ?? $r['franja'],
            'horario'  => horarioFranja($r['franja']),
            'inicio'   => HORARIOS_FRANJA[$r['franja']][0] ?? '',
            'estado'   => $r['estado'],
            'monto'    => dinero($r['monto']),
            'vence'    => $vence,
            'espacioId' => (string) $r['espacio_id'],
            'contactoId' => (string) $r['contacto_id'],
            'espacio'  => $espacio['nombre'] ?? 'Espacio eliminado',
            'color'    => $espacio['color'] ?? '#8a8f8b',
            'contacto' => [
                'nombre'   => $r['contacto']['nombre'] ?? 'Contacto no encontrado',
                'tipo'     => $r['contacto']['tipo'] ?? '',
                'telefono' => (string) ($r['contacto']['telefono'] ?? ''),
                'correo'   => (string) ($r['contacto']['correo'] ?? ''),
            ],
        ];
        $porDia[$dia][] = $id;

        // El resumen cuenta solo los días del mes, no los de las semanas vecinas
        if (str_starts_with($dia, $mes)) {
            $resumen['total']++;
            $resumen[$r['estado']]++;
            $porEspacio[(string) $r['espacio_id']] = ($porEspacio[(string) $r['espacio_id']] ?? 0) + 1;
        }
    }
}

// Dentro de cada día: mañana → tarde → noche, y luego por espacio
foreach ($porDia as &$ids) {
    usort($ids, fn ($a, $b) =>
        [$ordenFranja[$reservas[$a]['franja']] ?? 9, $reservas[$a]['espacio']]
        <=> [$ordenFranja[$reservas[$b]['franja']] ?? 9, $reservas[$b]['espacio']]);
}
unset($ids);

// Días de la cuadrícula con su etiqueta larga ("viernes 17 de octubre")
$dias = [];
for ($d = $inicioGrid; $d <= $finGrid; $d = $d->modify('+1 day')) {
    $clave = $d->format('Y-m-d');
    $dias[$clave] = [
        'numero' => (int) $d->format('j'),
        'fuera'  => $d->format('Y-m') !== $mes,
        'pasado' => $clave < $hoy,
        'hoy'    => $clave === $hoy,
        'titulo' => DIAS[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . MESES_LARGOS[(int) $d->format('n') - 1],
    ];
}

/** URL del calendario conservando el filtro. */
function urlMes(string $mes, string $filtro): string
{
    return 'calendario.php?' . http_build_query(array_filter(['mes' => $mes, 'espacio' => $filtro]));
}

// Datos que usa la tarjeta de detalle en el navegador
$datosJs = [
    'csrf'     => tokenCsrf(),   // confirmar y cancelar exigen el token (exigirCsrf)
    'reservas' => $reservas,
    'porDia'   => $porDia,
    'dias'     => array_map(fn ($d) => ['titulo' => $d['titulo'], 'pasado' => $d['pasado']], $dias),
    'espacios' => array_values(array_map(fn ($e) => [
        'id'      => (string) $e['id'],
        'nombre'  => $e['nombre'],
        'color'   => $e['color'],
        'tarifas' => $e['tarifas'],
    ], array_filter($espacios, fn ($e, $k) => $e['activo'] && ($filtro === '' || $filtro === $k), ARRAY_FILTER_USE_BOTH))),
    'franjas'  => array_map(fn ($f) => ['etiqueta' => FRANJAS[$f], 'horario' => horarioFranja($f)], array_combine(array_keys(FRANJAS), array_keys(FRANJAS))),
    'mes'      => $mes,
    'filtro'   => $filtro,
    'hoy'      => $hoy,
    // Opciones del formulario de edición dentro de la tarjeta
    'opciones' => [
        'espacios'  => array_values(array_map(fn ($e) => [
            'id' => (string) $e['id'], 'nombre' => $e['nombre'], 'franjas' => array_keys($e['tarifas']),
        ], array_filter($espacios, fn ($e) => $e['activo']))),
        'contactos' => array_map(fn ($c) => ['id' => (string) $c['_id'], 'nombre' => $c['nombre']],
            $baseDatos->selectCollection('contactos')->find(
                ['activo' => ['$ne' => false]],
                ['sort' => ['nombre' => 1], 'projection' => ['nombre' => 1]]
            )->toArray()),
    ],
];

encabezado('Calendario', 'calendario');

tituloPagina(
    'Calendario',
    'Ocupación de los espacios mes a mes. Tocá una reserva para ver el detalle o un día para ver lo que queda libre.',
    ['texto' => 'Nueva reserva', 'url' => 'crear.php?' . http_build_query(['volver' => 'calendario', 'mes' => $mes, 'filtro' => $filtro, 'espacio' => $filtro])]
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

<section class="adi-stats adi-stats--3" aria-label="Resumen del mes">
    <div class="adi-stat">
        <div class="adi-stat-label">Reservas del mes</div>
        <div class="adi-stat-value"><?= $resumen['total'] ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label"><span class="adi-dot" style="background: var(--adi-warn-ink)"></span>Apartadas</div>
        <div class="adi-stat-value"><?= $resumen['apartado'] ?></div>
    </div>
    <div class="adi-stat">
        <div class="adi-stat-label"><span class="adi-dot" style="background: var(--adi-ok-ink)"></span>Confirmadas</div>
        <div class="adi-stat-value"><?= $resumen['confirmado'] ?></div>
    </div>
</section>

<div class="adi-card adi-cal">
    <div class="adi-cal-toolbar">
        <div class="adi-cal-nav">
            <a class="btn btn-sm adi-btn-ghost adi-cal-arrow" href="<?= e(urlMes($mesAnterior, $filtro)) ?>" aria-label="Mes anterior">‹</a>
            <h2 class="adi-cal-month"><?= ucfirst(MESES_LARGOS[(int) $primero->format('n') - 1]) ?> <span><?= $primero->format('Y') ?></span></h2>
            <a class="btn btn-sm adi-btn-ghost adi-cal-arrow" href="<?= e(urlMes($mesSiguiente, $filtro)) ?>" aria-label="Mes siguiente">›</a>
            <?php if ($mes !== substr($hoy, 0, 7)): ?>
                <a class="btn btn-sm adi-btn-soft" href="<?= e(urlMes(substr($hoy, 0, 7), $filtro)) ?>">Hoy</a>
            <?php endif; ?>
        </div>

        <div class="adi-cal-tools">
            <form method="GET" class="adi-cal-filter">
                <input type="hidden" name="mes" value="<?= e($mes) ?>">
                <label for="espacio" class="visually-hidden">Espacio</label>
                <select class="form-select form-select-sm" id="espacio" name="espacio" onchange="this.form.submit()">
                    <option value="">Todos los espacios</option>
                    <?php foreach ($espacios as $idE => $esp): ?>
                        <option value="<?= e($idE) ?>" <?= $filtro === $idE ? 'selected' : '' ?>>
                            <?= e($esp['nombre']) ?><?= $esp['activo'] ? '' : ' (inactivo)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm adi-btn-ghost">Ver</button></noscript>
            </form>
        </div>
    </div>

    <?php if (!$espacios): ?>
        <div class="adi-empty">
            <strong>Todavía no hay espacios</strong>
            <a href="../espacios/form.php">Registrá uno</a> para empezar a recibir reservas.
        </div>
    <?php else: ?>

    <!-- Vista de mes (pantallas medianas y grandes) -->
    <div class="adi-cal-grid" role="grid" aria-label="Calendario del mes">
        <?php foreach (DIAS_CORTOS as $nombreDia): ?>
            <div class="adi-cal-head" role="columnheader"><?= $nombreDia ?></div>
        <?php endforeach; ?>

        <?php foreach ($dias as $clave => $dia):
            $ids = $porDia[$clave] ?? [];
            $clases = array_filter([
                'adi-cal-day',
                $dia['fuera'] ? 'is-out' : '',
                $dia['pasado'] ? 'is-past' : '',
                $dia['hoy'] ? 'is-today' : '',
            ]);
        ?>
            <div class="<?= implode(' ', $clases) ?>" role="gridcell" data-dia="<?= $clave ?>">
                <button type="button" class="adi-cal-date" data-dia="<?= $clave ?>"
                        aria-label="<?= e($dia['titulo']) ?>: <?= count($ids) ?> <?= count($ids) === 1 ? 'reserva' : 'reservas' ?>">
                    <?= $dia['numero'] ?>
                </button>
                <?php foreach (array_slice($ids, 0, MAX_EVENTOS_DIA) as $idR):
                    $r = $reservas[$idR];
                ?>
                    <button type="button" class="adi-cal-event is-<?= e($r['estado']) ?>" data-reserva="<?= e($idR) ?>"
                            style="--esp: <?= e($r['color']) ?>"
                            title="<?= e($r['etFranja'] . ' ' . $r['horario'] . ' · ' . $r['espacio'] . ' · ' . $r['contacto']['nombre']) ?>">
                        <span class="adi-cal-time"><?= e($r['inicio']) ?></span>
                        <span class="adi-cal-label"><?= e($filtro !== '' ? $r['contacto']['nombre'] : $r['espacio']) ?></span>
                    </button>
                <?php endforeach; ?>
                <?php if (count($ids) > MAX_EVENTOS_DIA): ?>
                    <button type="button" class="adi-cal-more" data-dia="<?= $clave ?>">+<?= count($ids) - MAX_EVENTOS_DIA ?> más</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Agenda (celular): solo los días del mes que tienen reservas -->
    <div class="adi-cal-agenda">
        <?php $hayAlgo = false;
        foreach ($dias as $clave => $dia):
            if ($dia['fuera'] || empty($porDia[$clave])) continue;
            $hayAlgo = true; ?>
            <div class="adi-agenda-day <?= $dia['hoy'] ? 'is-today' : '' ?> <?= $dia['pasado'] ? 'is-past' : '' ?>">
                <button type="button" class="adi-agenda-date" data-dia="<?= $clave ?>">
                    <strong><?= $dia['numero'] ?></strong><span><?= e(explode(' ', $dia['titulo'])[0]) ?></span>
                </button>
                <div class="adi-agenda-events">
                    <?php foreach ($porDia[$clave] as $idR): $r = $reservas[$idR]; ?>
                        <button type="button" class="adi-cal-event is-<?= e($r['estado']) ?>" data-reserva="<?= e($idR) ?>"
                                style="--esp: <?= e($r['color']) ?>">
                            <span class="adi-cal-time"><?= e($r['inicio']) ?></span>
                            <span class="adi-cal-label"><?= e($r['espacio']) ?> · <?= e($r['contacto']['nombre']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$hayAlgo): ?>
            <div class="adi-empty">
                <strong>Sin reservas este mes</strong>
                <a href="crear.php?<?= e(http_build_query(['volver' => 'calendario', 'mes' => $mes, 'filtro' => $filtro])) ?>">Registrá una</a>.
            </div>
        <?php endif; ?>
    </div>

    <!-- Leyenda: qué significa cada color y cada estilo -->
    <footer class="adi-cal-key" aria-label="Cómo leer el calendario">
        <div class="adi-key-group">
            <h3 class="adi-legend-title">Espacios</h3>
            <ul class="adi-key-list">
                <?php foreach ($espacios as $idE => $esp):
                    if ($filtro !== '' && $filtro !== $idE) continue;
                    $n = $porEspacio[$idE] ?? 0; ?>
                    <li class="<?= $esp['activo'] ? '' : 'is-off' ?>">
                        <span class="adi-key-swatch" style="--esp: <?= e($esp['color']) ?>"></span>
                        <span>
                            <strong><?= e($esp['nombre']) ?></strong>
                            <small><?= e($esp['tipo']) ?><?= $esp['activo'] ? '' : ' · inactivo' ?> · <?= $n ?> <?= $n === 1 ? 'reserva' : 'reservas' ?> este mes</small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="adi-key-group">
            <h3 class="adi-legend-title">Estado</h3>
            <ul class="adi-key-list">
                <li>
                    <span class="adi-legend-chip is-confirmado">08:00</span>
                    <span><strong>Confirmada</strong><small>Pago recibido. La reserva es permanente.</small></span>
                </li>
                <li>
                    <span class="adi-legend-chip is-apartado">08:00</span>
                    <span><strong>Apartada</strong><small>Sin pagar. Si no se confirma en <?= HORAS_APARTADO ?> h, vence sola y el horario se libera.</small></span>
                </li>
            </ul>
        </div>
    </footer>
    <?php endif; ?>
</div>

<!-- Tarjeta de detalle (se llena desde JavaScript) -->
<dialog id="detalle" class="adi-dialog" aria-labelledby="detalle-titulo">
    <div id="detalle-contenido"></div>
</dialog>

<script id="datos-calendario" type="application/json"><?= json_encode($datosJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="calendario.js"></script>
<?php pie();
