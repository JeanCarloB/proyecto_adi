<?php
/**
 * Solicitud de reserva hecha por una persona de la comunidad (con sesión).
 *
 * No crea una reserva: guarda una "solicitud" pendiente que la junta aprueba
 * o rechaza desde src/reservas/solicitudes.php. Así nadie puede ocupar un
 * horario sin que la junta lo revise.
 *
 * Protección contra abusos, en capas:
 *   1. Exige iniciar sesión (y el login exige captcha ALTCHA).
 *   2. Token CSRF: el formulario no se puede enviar desde otro sitio.
 *   3. Índice único parcial {espacio_id, fecha, franja, usuario_id} en pendientes:
 *      una persona no puede repetir el mismo pedido.
 *   4. JSON Schema de la colección: tipos y largos máximos.
 *   5. TTL: lo que nadie atiende se borra solo a los DIAS_SOLICITUD días.
 */
// Página pública: el control de acceso (login) no debe exigir sesión aquí.
define('PAGINA_PUBLICA', true);

require_once dirname(__DIR__) . '/includes/alquileres.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// Pedir una reserva exige cuenta. Sin sesión, login.php la pide y vuelve aquí.
$usuario = requiereLogin();

$espacios = array_filter(espaciosConColor($baseDatos), fn ($e) => $e['activo'] && $e['tarifas']);
$hoy      = hoyLocal();
$limite   = (new DateTimeImmutable($hoy))->modify('+' . DIAS_ADELANTE_SOLICITUD . ' days')->format('Y-m-d');

$errores = [];
$enviada = false;

$espacioId = texto($_POST['espacio'] ?? $_GET['espacio'] ?? '');
$fecha     = texto($_POST['fecha']   ?? $_GET['fecha']   ?? '');
$franja    = texto($_POST['franja']  ?? $_GET['franja']  ?? '');
$telefono  = texto($_POST['telefono'] ?? '');
$mensaje   = texto($_POST['mensaje']  ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $espacio = $espacios[$espacioId] ?? null;
    $dia     = diaUtc($fecha);

    if (!$espacio) {
        $errores[] = 'Elegí un espacio.';
    } elseif (!isset($espacio['tarifas'][$franja])) {
        $errores[] = 'Ese espacio no se alquila en la franja elegida.';
    }
    if (!$dia) {
        $errores[] = 'Indicá una fecha válida.';
    } elseif ($fecha < $hoy || $fecha > $limite) {
        $errores[] = 'Se pueden pedir fechas desde hoy y hasta ' . DIAS_ADELANTE_SOLICITUD . ' días adelante.';
    }
    if (!csrfValido()) {
        $errores[] = 'La página venció. Volvé a intentarlo.';
    }
    if ($telefono !== '' && !preg_match('/^[0-9 +()-]{8,20}$/', $telefono)) {
        $errores[] = 'El teléfono solo puede tener números, espacios y los signos + ( ) -.';
    }
    if (mb_strlen($mensaje) > 300) {
        $errores[] = 'El mensaje puede tener hasta 300 caracteres.';
    }

    // ¿Sigue libre? (si ya hay una reserva, no tiene sentido pedirlo)
    if (!$errores && $baseDatos->selectCollection('reservas')->countDocuments(
        ['espacio_id' => $espacio['id'], 'fecha' => $dia, 'franja' => $franja], ['limit' => 1]
    )) {
        $errores[] = 'Esa franja ya está ocupada. Elegí otra en el calendario.';
    }

    if (!$errores) {
        $documento = [
            'espacio_id' => $espacio['id'],
            'fecha'      => $dia,
            'franja'     => $franja,
            'usuario_id' => new ObjectId($usuario['id']),
            'nombre'     => $usuario['nombre'],
            'correo'     => mb_strtolower($usuario['correo']),
            'estado'     => 'pendiente',
            'creada'     => new UTCDateTime(),
            'expira'     => new UTCDateTime((time() + DIAS_SOLICITUD * 86400) * 1000),
        ];
        if ($telefono !== '') {
            $documento['telefono'] = $telefono;
        }
        if ($mensaje !== '') {
            $documento['mensaje'] = $mensaje;
        }

        try {
            $baseDatos->selectCollection('solicitudes')->insertOne($documento);
            $enviada = true;
        } catch (Throwable $e) {
            $errores[] = match (codigoError($e)) {
                11000   => 'Ya tenés una solicitud pendiente para ese espacio, día y franja.',
                121     => 'Algún dato no tiene el formato correcto. Revisá el correo y los textos.',
                default => 'No se pudo enviar la solicitud. Intentá de nuevo en un momento.',
            };
        }
    }
}

encabezadoPublico('Solicitar una reserva', 'solicitar');
?>
<div class="adi-form-narrow">
    <a href="calendario.php<?= $fecha ? '?mes=' . e(substr($fecha, 0, 7)) : '' ?>" class="adi-sub text-decoration-none d-inline-block mb-3">← Calendario</a>

    <?php if ($enviada): ?>
        <?php tituloPagina('¡Solicitud enviada!', 'La junta directiva la va a revisar y te contactará por correo para confirmar la reserva y coordinar el pago.'); ?>
        <div class="adi-card adi-card-body">
            <dl class="adi-dl">
                <div class="adi-dl-row"><dt>Espacio</dt><dd><?= e($espacios[$espacioId]['nombre']) ?></dd></div>
                <div class="adi-dl-row"><dt>Fecha</dt><dd><?= e(diaLargo(diaUtc($fecha))) ?> · <?= e(diaSemana(diaUtc($fecha))) ?></dd></div>
                <div class="adi-dl-row"><dt>Horario</dt><dd><?= e(FRANJAS[$franja]) ?> · <?= e(horarioFranja($franja)) ?></dd></div>
                <div class="adi-dl-row"><dt>Tarifa</dt><dd><?= dinero($espacios[$espacioId]['tarifas'][$franja]) ?></dd></div>
            </dl>
            <p class="adi-sub mt-3 mb-0">
                El horario todavía no está apartado: queda reservado cuando la junta aprueba la solicitud.
                Si en <?= DIAS_SOLICITUD ?> días nadie la atiende, vence sola.
            </p>
            <div class="d-flex gap-2 mt-4">
                <a href="mis_solicitudes.php" class="btn adi-btn-primary">Ver mis solicitudes</a>
                <a href="calendario.php" class="btn adi-btn-ghost">Volver al calendario</a>
            </div>
        </div>
    <?php else: ?>
        <?php tituloPagina(
            'Solicitar una reserva',
            'Contanos qué espacio necesitás y cuándo. La junta revisa cada solicitud y te confirma por correo.'
        ); ?>

        <?php listaErrores($errores); ?>

        <?php if (!$espacios): ?>
            <div class="adi-card adi-empty"><strong>Por ahora no hay espacios disponibles</strong></div>
        <?php else: ?>
        <form method="POST" class="adi-card adi-card-body" novalidate>
            <?= campoCsrf() ?>
            <h2 class="adi-section-title">¿Qué necesitás?</h2>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <label for="espacio" class="form-label">Espacio</label>
                    <select class="form-select" id="espacio" name="espacio" required>
                        <option value="">Elegí un espacio</option>
                        <?php foreach ($espacios as $idE => $esp): ?>
                            <option value="<?= e($idE) ?>" <?= $espacioId === $idE ? 'selected' : '' ?>><?= e($esp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="fecha" class="form-label">Fecha</label>
                    <input type="date" class="form-control" id="fecha" name="fecha" required
                           min="<?= e($hoy) ?>" max="<?= e($limite) ?>" value="<?= e($fecha) ?>">
                </div>
                <div class="col-sm-6">
                    <label for="franja" class="form-label">Franja</label>
                    <select class="form-select" id="franja" name="franja" required>
                        <option value="">Elegí una franja</option>
                        <?php foreach (FRANJAS as $valor => $etiqueta): ?>
                            <option value="<?= e($valor) ?>" <?= $franja === $valor ? 'selected' : '' ?>>
                                <?= e($etiqueta) ?> · <?= e(horarioFranja($valor)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h2 class="adi-section-title">Tus datos</h2>
            <div class="adi-account mb-3">
                <strong><?= e($usuario['nombre']) ?></strong>
                <span><?= e($usuario['correo']) ?></span>
                <small>La junta te contacta a este correo. Sale de tu cuenta.</small>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-sm-6">
                    <label for="telefono" class="form-label">Teléfono <span class="adi-sub">(opcional)</span></label>
                    <input type="tel" class="form-control" id="telefono" name="telefono"
                           maxlength="20" autocomplete="tel" value="<?= e($telefono) ?>">
                </div>
                <div class="col-12">
                    <label for="mensaje" class="form-label">Mensaje <span class="adi-sub">(opcional)</span></label>
                    <textarea class="form-control" id="mensaje" name="mensaje" rows="3" maxlength="300"
                              placeholder="Por ejemplo: cumpleaños para 40 personas"><?= e($mensaje) ?></textarea>
                </div>
            </div>

            <div class="adi-total" id="tarifa">
                <span>Tarifa de referencia</span>
                <strong id="monto">—</strong>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn adi-btn-primary">Enviar solicitud</button>
                <a href="calendario.php" class="btn adi-btn-ghost">Cancelar</a>
            </div>
            <p class="adi-sub mt-3 mb-0">Usamos tus datos solo para responder esta solicitud.</p>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!$enviada && $espacios): ?>
<script>
// Solo informativo: muestra la tarifa y desactiva las franjas que el espacio no ofrece.
const tarifas = <?= json_encode(array_map(fn ($e) => array_map('dinero', $e['tarifas']), $espacios), JSON_UNESCAPED_UNICODE) ?>;
const espacio = document.getElementById('espacio');
const franja  = document.getElementById('franja');
const monto   = document.getElementById('monto');
function actualizar() {
    const del = tarifas[espacio.value] || {};
    for (const o of franja.options) { if (o.value) o.disabled = espacio.value !== '' && !(o.value in del); }
    if (franja.selectedOptions[0] && franja.selectedOptions[0].disabled) franja.value = '';
    monto.textContent = del[franja.value] || '—';
}
espacio.addEventListener('change', actualizar);
franja.addEventListener('change', actualizar);
actualizar();
</script>
<?php endif; ?>
<?php pie();
