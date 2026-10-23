<?php
/**
 * Crear o editar un espacio (Issue #11).
 * Sin ?id= crea uno nuevo; con ?id= edita el existente.
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';
requiereRol('admin');

$coleccion = $baseDatos->selectCollection('espacios');

$id      = idValido($_GET['id'] ?? $_POST['id'] ?? '');
$editar  = $id !== null;
$errores = [];

// Valores del formulario
$nombre  = '';
$tipo    = '';
$montos  = array_fill_keys(array_keys(FRANJAS), '');

if ($editar && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $espacio = $coleccion->findOne(['_id' => $id]);
    if (!$espacio) {
        http_response_code(404);
        exit('El espacio no existe.');
    }
    $nombre = $espacio['nombre'];
    $tipo   = $espacio['tipo'];
    foreach ($espacio['tarifas'] ?? [] as $t) {
        $montos[$t['franja']] = (string) (float) (string) $t['monto'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = texto($_POST['nombre'] ?? '');
    $tipo   = texto($_POST['tipo'] ?? '');

    // Validaciones en la aplicación: dan mensajes claros al usuario.
    // Las mismas reglas las vuelve a exigir el JSON Schema en la base.
    if (mb_strlen($nombre) < 3) {
        $errores[] = 'El nombre debe tener al menos 3 caracteres.';
    }
    if (!isset(TIPOS_ESPACIO[$tipo])) {
        $errores[] = 'Seleccioná si es salón o cancha.';
    }

    $tarifas = [];
    foreach (FRANJAS as $franja => $etiqueta) {
        $valor = texto($_POST['monto'][$franja] ?? '');
        $montos[$franja] = $valor;
        if ($valor === '') {
            continue; // Franja no ofrecida: no se guarda
        }
        if (!is_numeric($valor) || (float) $valor <= 0) {
            $errores[] = "La tarifa de la $etiqueta debe ser un monto mayor que cero.";
            continue;
        }
        // Tarifas embebidas: siempre se leen junto al espacio y son pocas.
        $tarifas[] = ['franja' => $franja, 'monto' => decimal($valor)];
    }
    if (!$tarifas && !$errores) {
        $errores[] = 'Indicá la tarifa de al menos una franja.';
    }

    if (!$errores) {
        $datos = ['nombre' => $nombre, 'tipo' => $tipo, 'tarifas' => $tarifas];
        try {
            if ($editar) {
                $coleccion->updateOne(['_id' => $id], ['$set' => $datos]);
                header('Location: index.php?ok=editado');
            } else {
                $coleccion->insertOne($datos + ['activo' => true]);
                header('Location: index.php?ok=creado');
            }
            exit;
        } catch (Throwable $e) {
            $errores[] = codigoError($e) === 121
                ? 'La base de datos rechazó el espacio porque no cumple el esquema.'
                : 'No se pudo guardar el espacio. Intentá de nuevo.';
        }
    }
}

encabezado($editar ? 'Editar espacio' : 'Nuevo espacio', 'espacios');
?>
<div class="adi-form-narrow">
    <a href="index.php" class="adi-sub text-decoration-none d-inline-block mb-3">← Espacios</a>
    <?php tituloPagina(
        $editar ? 'Editar espacio' : 'Nuevo espacio',
        'Las franjas sin tarifa no se ofrecen al reservar.'
    ); ?>

    <?php listaErrores($errores); ?>

    <form method="POST" class="adi-card adi-card-body">
        <?php if ($editar): ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-sm-8">
                <label for="nombre" class="form-label">Nombre</label>
                <input type="text" class="form-control" id="nombre" name="nombre"
                       required minlength="3" value="<?= e($nombre) ?>"
                       placeholder="Salón comunal">
            </div>
            <div class="col-sm-4">
                <label for="tipo" class="form-label">Tipo</label>
                <select class="form-select" id="tipo" name="tipo" required>
                    <option value="">Elegí</option>
                    <?php foreach (TIPOS_ESPACIO as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $tipo === $valor ? 'selected' : '' ?>>
                            <?= e($etiqueta) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h2 class="adi-section-title">Tarifas por franja</h2>
        <div class="row g-3 mb-4">
            <?php foreach (FRANJAS as $franja => $etiqueta): ?>
                <div class="col-sm-4">
                    <label for="monto-<?= e($franja) ?>" class="form-label"><?= e($etiqueta) ?></label>
                    <div class="input-group">
                        <span class="input-group-text">₡</span>
                        <input type="number" class="form-control" min="1" step="1"
                               id="monto-<?= e($franja) ?>"
                               name="monto[<?= e($franja) ?>]"
                               value="<?= e($montos[$franja]) ?>"
                               placeholder="—">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex gap-2 pt-2 border-top" style="border-color: var(--adi-border) !important">
            <button type="submit" class="btn adi-btn-primary mt-3">Guardar espacio</button>
            <a href="index.php" class="btn adi-btn-ghost mt-3">Cancelar</a>
        </div>
    </form>
</div>
<?php pie();
