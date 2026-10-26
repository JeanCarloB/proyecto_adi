<?php
/**
 * Editar una reserva: espacio, fecha, franja y contacto.
 *
 * El estado no se edita aquí: se cambia con "Confirmar pago" (confirmar.php).
 * Un apartado conserva su "vence" original, así editarlo no le regala más tiempo.
 * Si el nuevo espacio/fecha/franja ya está tomado, el índice único responde
 * con el error 11000 y se muestra un mensaje claro.
 *
 * Se usa desde la tarjeta del calendario (el formulario se envía aquí) y
 * también como página propia si hay errores o se abre desde la lista.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario

$coleccion = $baseDatos->selectCollection('reservas');

$idReserva = idValido(texto($_POST['id'] ?? $_GET['id'] ?? ''));
$reserva = $idReserva ? $coleccion->findOne(['_id' => $idReserva]) : null;

if (!$reserva) {
    http_response_code(404);
    exit('La reserva no existe. Puede que se haya cancelado o que el apartado ya venciera.');
}

$errores = [];

// Datos para los selects
$espacios = $baseDatos->selectCollection('espacios')
    // Activos, más el de la reserva aunque ya esté inactivo (para poder dejarlo igual)
    ->find(['$or' => [['activo' => true], ['_id' => $reserva['espacio_id']]]], ['sort' => ['nombre' => 1]])
    ->toArray();

$contactos = $baseDatos->selectCollection('contactos')
    ->find(['$or' => [['activo' => ['$ne' => false]], ['_id' => $reserva['contacto_id']]]], [
        'sort'       => ['nombre' => 1],
        'projection' => ['nombre' => 1, 'tipo' => 1],
    ])
    ->toArray();

$tarifas = [];
foreach ($espacios as $esp) {
    foreach ($esp['tarifas'] ?? [] as $t) {
        $tarifas[(string) $esp['_id']][$t['franja']] = $t['monto'];
    }
}

// Valores actuales de la reserva (o los que se enviaron)
$fechaActual = $reserva['fecha']->toDateTime()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
$espacioId  = texto($_POST['espacio']  ?? (string) $reserva['espacio_id']);
$franja     = texto($_POST['franja']   ?? $reserva['franja']);
$fecha      = texto($_POST['fecha']    ?? $fechaActual);
$contactoId = texto($_POST['contacto'] ?? (string) $reserva['contacto_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idEspacio  = idValido($espacioId);
    $idContacto = idValido($contactoId);
    $dia        = diaUtc($fecha);

    $cambiaHorario = $espacioId !== (string) $reserva['espacio_id']
        || $franja !== $reserva['franja']
        || $fecha !== $fechaActual;

    if (!$idEspacio || !isset($tarifas[(string) $idEspacio])) {
        $errores[] = 'Seleccioná un espacio.';
    } elseif (!isset($tarifas[(string) $idEspacio][$franja])) {
        $errores[] = 'Ese espacio no tiene tarifa para la franja elegida.';
    }

    if (!$dia) {
        $errores[] = 'Indicá una fecha válida.';
    } elseif ($cambiaHorario && $fecha < hoyLocal()) {
        $errores[] = 'No se puede mover una reserva a una fecha que ya pasó.';
    }

    if (!$idContacto
        || !$baseDatos->selectCollection('contactos')->countDocuments(['_id' => $idContacto], ['limit' => 1])) {
        $errores[] = 'Seleccioná el contacto que hace la reserva.';
    }

    if (!$errores) {
        $cambios = [
            'espacio_id'  => $idEspacio,
            'contacto_id' => $idContacto,
            'fecha'       => $dia,
            'franja'      => $franja,
        ];
        // El monto se recalcula solo si cambió el espacio o la franja.
        // Si solo cambió el contacto o el día, se respeta lo acordado.
        if ($espacioId !== (string) $reserva['espacio_id'] || $franja !== $reserva['franja']) {
            $cambios['monto'] = $tarifas[(string) $idEspacio][$franja];
        }

        try {
            $coleccion->updateOne(['_id' => $idReserva], ['$set' => $cambios]);
            header('Location: ' . destinoReservas('ok=editada'));
            exit;
        } catch (Throwable $e) {
            $errores[] = match (codigoError($e)) {
                11000   => 'Ese horario ya está reservado. Elegí otra fecha, franja o espacio.',
                121     => 'La base de datos rechazó el cambio porque no cumple el esquema.',
                default => 'No se pudo guardar el cambio. Intentá de nuevo.',
            };
        }
    }
}

$volver = texto($_POST['volver'] ?? $_GET['volver'] ?? '') === 'calendario';
$mesCal = texto($_POST['mes'] ?? $_GET['mes'] ?? '');
$filtro = texto($_POST['filtro'] ?? $_GET['filtro'] ?? '');
$apartado = $reserva['estado'] === 'apartado';

encabezado('Editar reserva', 'reservas');
?>
<div class="adi-form-narrow">
    <a href="<?= e(destinoReservas('')) ?>" class="adi-sub text-decoration-none d-inline-block mb-3">
        ← <?= $volver ? 'Calendario' : 'Reservas' ?>
    </a>
    <?php tituloPagina(
        'Editar reserva',
        $apartado
            ? 'Sigue apartada y vence ' . mostrarHora($reserva['vence']) . '. Editarla no cambia el vencimiento.'
            : 'Reserva confirmada. Si cambiás el espacio o la franja, el monto se ajusta a la tarifa.'
    ); ?>

    <?php listaErrores($errores); ?>

    <form method="POST" class="adi-card adi-card-body">
        <?= campoCsrf() ?>
        <input type="hidden" name="id" value="<?= e($idReserva) ?>">
        <?php if ($volver): ?>
            <input type="hidden" name="volver" value="calendario">
            <input type="hidden" name="mes" value="<?= e($mesCal) ?>">
            <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="espacio" class="form-label">Espacio</label>
            <select class="form-select" id="espacio" name="espacio" required>
                <?php foreach ($espacios as $esp): ?>
                    <option value="<?= e($esp['_id']) ?>" <?= $espacioId === (string) $esp['_id'] ? 'selected' : '' ?>>
                        <?= e($esp['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label for="fecha" class="form-label">Fecha</label>
                <input type="date" class="form-control" id="fecha" name="fecha" required value="<?= e($fecha) ?>">
            </div>
            <div class="col-sm-6">
                <label for="franja" class="form-label">Franja</label>
                <select class="form-select" id="franja" name="franja" required>
                    <?php foreach (FRANJAS as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $franja === $valor ? 'selected' : '' ?>
                            <?= isset($tarifas[$espacioId][$valor]) ? '' : 'disabled' ?>>
                            <?= e($etiqueta) ?> · <?= e(horarioFranja($valor)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-4">
            <label for="contacto" class="form-label">Contacto</label>
            <select class="form-select" id="contacto" name="contacto" required>
                <?php foreach ($contactos as $c): ?>
                    <option value="<?= e($c['_id']) ?>" <?= $contactoId === (string) $c['_id'] ? 'selected' : '' ?>>
                        <?= e($c['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn adi-btn-primary">Guardar cambios</button>
            <a href="<?= e(destinoReservas('')) ?>" class="btn adi-btn-ghost">Cancelar</a>
        </div>
    </form>
</div>
<?php pie();
