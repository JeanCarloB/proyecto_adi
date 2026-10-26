<?php
/**
 * Registrar un apartado de un espacio (Issue #12).
 *
 * La reserva nace en estado "apartado" con una fecha "vence". El índice TTL
 * la elimina sola si nadie confirma el pago. El índice único impide que dos
 * personas aparten el mismo espacio, día y franja: si pasa, MongoDB responde
 * con el error 11000 y aquí se traduce a un mensaje claro.
 *
 * Antes de apartar se revisa si alguien de la comunidad pidió ese mismo
 * horario y su solicitud sigue pendiente. Si es así, se avisa y la junta
 * decide: atender primero la solicitud o apartar de todas formas.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario

use MongoDB\BSON\UTCDateTime;

$errores = [];
$pendientes = [];   // solicitudes de la comunidad que esperan el mismo horario

// Datos para los selects
$espacios = $baseDatos->selectCollection('espacios')
    ->find(['activo' => true], ['sort' => ['nombre' => 1]])
    ->toArray();

$contactos = $baseDatos->selectCollection('contactos')
    ->find(['activo' => ['$ne' => false]], [
        'sort'       => ['nombre' => 1],
        'projection' => ['nombre' => 1, 'tipo' => 1],
    ])
    ->toArray();

// Tarifas por espacio, para validar en el servidor y mostrar el monto en pantalla
$tarifas = [];
foreach ($espacios as $esp) {
    foreach ($esp['tarifas'] ?? [] as $t) {
        $tarifas[(string) $esp['_id']][$t['franja']] = $t['monto'];
    }
}

// Valores del formulario (se pueden precargar desde la URL, por ejemplo desde el calendario)
$espacioId  = texto($_POST['espacio']  ?? $_GET['espacio']  ?? '');
$franja     = texto($_POST['franja']   ?? $_GET['franja']   ?? '');
$fecha      = texto($_POST['fecha']    ?? $_GET['fecha']    ?? '');
$contactoId = texto($_POST['contacto'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idEspacio  = idValido($espacioId);
    $idContacto = idValido($contactoId);
    $dia        = diaUtc($fecha);

    if (!$idEspacio || !isset($tarifas[(string) $idEspacio])) {
        $errores[] = 'Seleccioná un espacio activo.';
    } elseif (!isset($tarifas[(string) $idEspacio][$franja])) {
        $errores[] = 'Ese espacio no tiene tarifa para la franja elegida.';
    }

    if (!$dia) {
        $errores[] = 'Indicá una fecha válida.';
    } elseif ($fecha < hoyLocal()) {
        $errores[] = 'No se puede reservar una fecha que ya pasó.';
    }

    if (!$idContacto
        || !$baseDatos->selectCollection('contactos')->countDocuments(['_id' => $idContacto], ['limit' => 1])) {
        $errores[] = 'Seleccioná el contacto que hace la reserva.';
    }

    // ¿Hay solicitudes de la comunidad esperando este mismo horario?
    // Usa el prefijo {espacio_id, fecha, franja} del índice único parcial de solicitudes.
    if (!$errores && ($_POST['aun_asi'] ?? '') !== '1') {
        $pendientes = $baseDatos->selectCollection('solicitudes')->find(
            ['espacio_id' => $idEspacio, 'fecha' => $dia, 'franja' => $franja, 'estado' => 'pendiente'],
            ['projection' => ['nombre' => 1, 'creada' => 1], 'sort' => ['creada' => 1]]
        )->toArray();
    }

    if (!$errores && !$pendientes) {
        // El monto sale de la tarifa guardada, nunca del formulario.
        $monto = $tarifas[(string) $idEspacio][$franja];
        $vence = new UTCDateTime((time() + HORAS_APARTADO * 3600) * 1000);

        try {
            $baseDatos->selectCollection('reservas')->insertOne([
                'espacio_id'  => $idEspacio,
                'contacto_id' => $idContacto,
                'fecha'       => $dia,
                'franja'      => $franja,
                'estado'      => 'apartado',
                'monto'       => $monto,
                'vence'       => $vence,
            ]);
            header('Location: ' . destinoReservas('ok=creada'));
            exit;
        } catch (Throwable $e) {
            $errores[] = match (codigoError($e)) {
                11000   => 'Ese horario ya está reservado. Elegí otra fecha o franja.',
                121     => 'La base de datos rechazó la reserva porque no cumple el esquema.',
                default => 'No se pudo registrar la reserva. Intentá de nuevo.',
            };
        }
    }
}

// Si se llegó desde el calendario, se vuelve a él (mismo mes y filtro)
$volver = texto($_POST['volver'] ?? $_GET['volver'] ?? '') === 'calendario';
$mesCal = texto($_POST['mes'] ?? $_GET['mes'] ?? '');
$filtro = texto($_POST['filtro'] ?? $_GET['filtro'] ?? '');

encabezado('Nueva reserva', 'reservas');
?>
<div class="adi-form-narrow">
    <?php if ($volver): ?>
        <a href="<?= e(destinoReservas('')) ?>" class="adi-sub text-decoration-none d-inline-block mb-3">← Calendario</a>
    <?php else: ?>
        <a href="index.php" class="adi-sub text-decoration-none d-inline-block mb-3">← Reservas</a>
    <?php endif; ?>
    <?php tituloPagina(
        'Nueva reserva',
        'Queda apartada por ' . HORAS_APARTADO . ' horas. Si no se confirma el pago, vence sola y el horario se libera.'
    ); ?>

    <?php listaErrores($errores); ?>

    <?php if ($pendientes): $n = count($pendientes); ?>
        <div class="adi-alert adi-alert--warn" role="alert">
            <span class="adi-alert-icon"><span>!</span></span>
            <div>
                <strong><?= $n === 1 ? 'Alguien de la comunidad ya pidió este horario' : $n . ' personas de la comunidad ya pidieron este horario' ?></strong>
                <ul class="mt-1 mb-2">
                    <?php foreach ($pendientes as $p): ?>
                        <li><?= e($p['nombre']) ?> · enviada el <?= e(mostrarHora($p['creada'])) ?></li>
                    <?php endforeach; ?>
                </ul>
                Revisá primero si la vas a aprobar o rechazar. Si apartás el espacio ahora, la solicitud
                queda sin opción de aprobarse y habrá que rechazarla.
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <a href="solicitudes.php" class="btn btn-sm adi-btn-primary">Ir a la bandeja de solicitudes</a>
                    <button type="submit" form="form-reserva" name="aun_asi" value="1" class="btn btn-sm adi-btn-ghost">Apartar de todas formas</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$espacios): ?>
        <div class="adi-card adi-empty">
            <strong>No hay espacios activos</strong>
            <a href="../espacios/form.php">Registrá uno</a> para poder reservar.
        </div>
    <?php else: ?>
    <form method="POST" id="form-reserva" class="adi-card adi-card-body">
        <?= campoCsrf() ?>
        <?php if ($volver): ?>
            <input type="hidden" name="volver" value="calendario">
            <input type="hidden" name="mes" value="<?= e($mesCal) ?>">
            <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
        <?php endif; ?>
        <div class="mb-3">
            <label for="espacio" class="form-label">Espacio</label>
            <select class="form-select" id="espacio" name="espacio" required>
                <option value="">Elegí un espacio</option>
                <?php foreach ($espacios as $esp): ?>
                    <option value="<?= e($esp['_id']) ?>"
                        <?= $espacioId === (string) $esp['_id'] ? 'selected' : '' ?>>
                        <?= e($esp['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label for="fecha" class="form-label">Fecha</label>
                <input type="date" class="form-control" id="fecha" name="fecha" required
                       min="<?= e(hoyLocal()) ?>" value="<?= e($fecha) ?>">
            </div>
            <div class="col-sm-6">
                <label for="franja" class="form-label">Franja</label>
                <select class="form-select" id="franja" name="franja" required>
                    <option value="">Elegí una franja</option>
                    <?php foreach (FRANJAS as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $franja === $valor ? 'selected' : '' ?>>
                            <?= e($etiqueta) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-1">
            <label for="contacto" class="form-label">Contacto</label>
            <select class="form-select" id="contacto" name="contacto" required>
                <option value="">¿Quién reserva?</option>
                <?php foreach ($contactos as $c): ?>
                    <option value="<?= e($c['_id']) ?>"
                        <?= $contactoId === (string) $c['_id'] ? 'selected' : '' ?>>
                        <?= e($c['nombre']) ?> · <?= e($c['tipo'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">
                ¿No aparece? <a href="<?= RAIZ ?>src/contactos/crear.php">Registrá el contacto</a>.
            </div>
        </div>

        <div class="adi-total">
            <span>Total según tarifa</span>
            <strong id="monto">—</strong>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn adi-btn-primary">Apartar espacio</button>
            <a href="<?= e(destinoReservas('')) ?>" class="btn adi-btn-ghost">Cancelar</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
// Solo para mostrar el monto: el servidor lo vuelve a calcular desde la tarifa guardada.
const tarifas = <?= json_encode(array_map(
    fn ($porFranja) => array_map(fn ($m) => (float) (string) $m, $porFranja),
    $tarifas
), JSON_UNESCAPED_UNICODE) ?>;

const espacio = document.getElementById('espacio');
const franja  = document.getElementById('franja');
const monto   = document.getElementById('monto');

function actualizar() {
    const delEspacio = tarifas[espacio.value] || {};
    for (const opcion of franja.options) {
        if (opcion.value) {
            opcion.disabled = !(opcion.value in delEspacio);
        }
    }
    if (franja.selectedOptions[0]?.disabled) {
        franja.value = '';
    }
    const valor = delEspacio[franja.value];
    monto.textContent = valor ? '₡' + valor.toLocaleString('es-CR') : '—';
}

if (espacio && franja) {
    espacio.addEventListener('change', actualizar);
    franja.addEventListener('change', actualizar);
    actualizar();
}
</script>
<?php pie();
