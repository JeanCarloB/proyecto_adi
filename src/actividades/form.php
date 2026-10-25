<?php
/**
 * Crear o editar una actividad: turno, feria o rifa (Issue #31).
 * Sin ?id= crea una nueva; con ?id= edita la existente.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';
requiereRol('admin');

$coleccion = $baseDatos->selectCollection('actividades');

$id      = idValido($_GET['id'] ?? $_POST['id'] ?? '');
$editar  = $id !== null;
$errores = [];

// Valores del formulario
$nombre = '';
$tipo   = texto($_GET['tipo'] ?? '');
$fecha  = '';
$precio = '';

// ¿Tiene ventas o números vendidos? Entonces ya no puede cambiar de tipo:
// una feria con ventas no puede convertirse en rifa, ni al revés.
$conMovimientos = false;

if ($editar) {
    $actividad = $coleccion->findOne(['_id' => $id]);
    if (!$actividad) {
        http_response_code(404);
        exit('La actividad no existe.');
    }
    $conMovimientos = $baseDatos->selectCollection('ventas')->countDocuments(['actividad_id' => $id], ['limit' => 1]) > 0
        || $baseDatos->selectCollection('rifas')->countDocuments(['rifa_id' => $id], ['limit' => 1]) > 0;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $nombre = $actividad['nombre'];
        $tipo   = $actividad['tipo'];
        $fecha  = fechaInput($actividad['fecha']);
        $precio = isset($actividad['precio_numero']) ? (string) (float) (string) $actividad['precio_numero'] : '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        rechazarCsrf();   // no vino de este formulario: no se guarda nada
    }
    $nombre = texto($_POST['nombre'] ?? '');
    $tipo   = $conMovimientos ? $actividad['tipo'] : texto($_POST['tipo'] ?? '');
    $fecha  = texto($_POST['fecha'] ?? '');
    $precio = texto($_POST['precio_numero'] ?? '');

    // Validaciones en la aplicación: dan mensajes claros al usuario.
    // Las mismas reglas las vuelve a exigir el JSON Schema en la base.
    if (mb_strlen($nombre) < 3) {
        $errores[] = 'El nombre debe tener al menos 3 caracteres.';
    }
    if (!isset(TIPOS_ACTIVIDAD[$tipo])) {
        $errores[] = 'Seleccioná si es turno, feria o rifa.';
    }
    $dia = diaUtc($fecha);
    if (!$dia) {
        $errores[] = 'Indicá una fecha válida.';
    }
    if ($tipo === 'rifa' && (!is_numeric($precio) || (float) $precio <= 0)) {
        $errores[] = 'Indicá el precio de cada número de la rifa.';
    }

    if (!$errores) {
        $datos = ['nombre' => $nombre, 'tipo' => $tipo, 'fecha' => $dia];
        if ($tipo === 'rifa') {
            $datos['precio_numero'] = decimal($precio);
        }
        try {
            if ($editar) {
                $cambio = ['$set' => $datos];
                if ($tipo !== 'rifa') {
                    $cambio['$unset'] = ['precio_numero' => ''];   // solo las rifas tienen precio por número
                }
                $coleccion->updateOne(['_id' => $id], $cambio);
                header('Location: ver.php?id=' . $id . '&ok=editada');
            } else {
                $nuevo = $coleccion->insertOne($datos + ['estado' => 'programada']);
                header('Location: ver.php?id=' . $nuevo->getInsertedId() . '&ok=creada');
            }
            exit;
        } catch (Throwable $e) {
            $errores[] = codigoError($e) === 121
                ? 'La base de datos rechazó la actividad porque no cumple el esquema.'
                : 'No se pudo guardar la actividad. Intentá de nuevo.';
        }
    }
}

$titulo = $editar ? 'Editar actividad' : 'Nueva actividad';
encabezado($titulo, 'actividades');
?>
<div class="adi-form-narrow">
    <a href="<?= $editar ? 'ver.php?id=' . e($id) : 'index.php' ?>" class="adi-sub text-decoration-none d-inline-block mb-3">
        ← <?= $editar ? e($actividad['nombre']) : 'Actividades' ?>
    </a>
    <?php tituloPagina(
        $titulo,
        'En turnos y ferias se registran ventas de productos; en las rifas se venden los números del 00 al 99.',
        null,
        'ventas'
    ); ?>

    <?php listaErrores($errores); ?>

    <form method="POST" class="adi-card adi-card-body">
        <?= campoCsrf() ?>
        <?php if ($editar): ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="nombre" class="form-label">Nombre</label>
            <input type="text" class="form-control" id="nombre" name="nombre"
                   required minlength="3" maxlength="80" value="<?= e($nombre) ?>"
                   placeholder="Turno navideño 2026">
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label for="tipo" class="form-label">Tipo</label>
                <select class="form-select" id="tipo" name="tipo" required <?= $conMovimientos ? 'disabled' : '' ?>>
                    <option value="">Elegí</option>
                    <?php foreach (TIPOS_ACTIVIDAD as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $tipo === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($conMovimientos): ?>
                    <div class="form-text">Ya tiene ventas registradas: el tipo no se puede cambiar.</div>
                <?php endif; ?>
            </div>
            <div class="col-sm-6">
                <label for="fecha" class="form-label">Fecha</label>
                <input type="date" class="form-control" id="fecha" name="fecha" required value="<?= e($fecha) ?>">
                <div class="form-text">En las rifas, el día del sorteo.</div>
            </div>
        </div>

        <div class="mb-1" id="campo-precio" <?= $tipo === 'rifa' ? '' : 'hidden' ?>>
            <label for="precio_numero" class="form-label">Precio de cada número</label>
            <div class="input-group">
                <span class="input-group-text">₡</span>
                <input type="number" class="form-control" id="precio_numero" name="precio_numero"
                       min="1" step="1" value="<?= e($precio) ?>" placeholder="1000">
            </div>
        </div>

        <div class="d-flex gap-2 pt-2 mt-3 border-top" style="border-color: var(--adi-border) !important">
            <button type="submit" class="btn adi-btn-primary mt-3">Guardar actividad</button>
            <a href="<?= $editar ? 'ver.php?id=' . e($id) : 'index.php' ?>" class="btn adi-btn-ghost mt-3">Cancelar</a>
        </div>
    </form>
</div>

<script>
// El precio por número solo aplica a las rifas
(function () {
    const tipo  = document.getElementById('tipo');
    const campo = document.getElementById('campo-precio');
    const input = document.getElementById('precio_numero');
    function actualizar() {
        const esRifa = tipo.value === 'rifa';
        campo.hidden = !esRifa;
        input.required = esRifa;
    }
    tipo.addEventListener('change', actualizar);
    actualizar();
})();
</script>
<?php pie();
