
<?php
require_once dirname(__DIR__) . '/includes/seguimientos.php';
requiereRol('admin');

$coleccionSeguimientos = $baseDatos->selectCollection('seguimientos');
$coleccionContactos = $baseDatos->selectCollection('contactos');

$contactos = $coleccionContactos
    ->find(['activo' => true], ['sort' => ['nombre' => 1]])
    ->toArray();

$contactoId = '';
$tipo = '';
$descripcion = '';
$fechaVencimiento = '';
$estado = 'pendiente';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contactoId = trim($_POST['contacto_id'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fechaVencimiento = trim($_POST['fecha_vencimiento'] ?? '');
    $estado = trim($_POST['estado'] ?? 'pendiente');

    if ($contactoId === '') {
        $errores[] = 'Seleccioná un contacto.';
    } else {
        try {
            $contactoValido = $coleccionContactos->findOne([
                '_id' => new MongoDB\BSON\ObjectId($contactoId),
                'activo' => true,
            ]);
            if ($contactoValido === null) {
                $errores[] = 'El contacto seleccionado no existe o está inactivo.';
            }
        } catch (Throwable $e) {
            $errores[] = 'El contacto seleccionado no es válido.';
        }
    }

    if (!isset(TIPOS_SEGUIMIENTO[$tipo])) {
        $errores[] = 'Seleccioná un tipo de seguimiento válido.';
    }

    if ($descripcion === '') {
        $errores[] = 'La descripción es obligatoria.';
    }

    if (!isset(ESTADOS_SEGUIMIENTO[$estado])) {
        $errores[] = 'Seleccioná un estado válido.';
    }

    $fechaMongo = null;
    if ($fechaVencimiento !== '') {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaVencimiento);
        if (!$fecha || $fecha->format('Y-m-d') !== $fechaVencimiento) {
            $errores[] = 'La fecha de vencimiento no es válida.';
        } else {
            $fechaMongo = new MongoDB\BSON\UTCDateTime(
                $fecha->getTimestamp() * 1000
            );
        }
    }

    if (!$errores) {
        $documento = [
            'contacto_id' => new MongoDB\BSON\ObjectId($contactoId),
            'tipo' => $tipo,
            'descripcion' => $descripcion,
            'estado' => $estado,
            'fecha_creacion' => new MongoDB\BSON\UTCDateTime(),
        ];

        if ($fechaMongo !== null) {
            $documento['fecha_vencimiento'] = $fechaMongo;
        }

        $coleccionSeguimientos->insertOne($documento);

        header('Location: index.php?ok=creado');
        exit;
    }
}

encabezado('Nuevo seguimiento', 'contactos');
tituloPagina(
    'Nuevo seguimiento',
    'Registrá una nota o un recordatorio asociado a un contacto.',
    null,
    'contactos'
);

if ($errores) {
    listaErrores($errores);
}
?>

<div class="adi-form-narrow">
    <form method="POST" class="adi-card adi-card-body">
        <div class="mb-3">
            <label for="contacto_id" class="form-label">Contacto *</label>
            <select class="form-select" id="contacto_id" name="contacto_id" required>
                <option value="">Seleccioná un contacto</option>
                <?php foreach ($contactos as $contacto): ?>
                    <option value="<?= e((string) $contacto['_id']) ?>"
                        <?= $contactoId === (string) $contacto['_id'] ? 'selected' : '' ?>>
                        <?= e($contacto['nombre'] ?? 'Sin nombre') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$contactos): ?>
                <div class="form-text">No hay contactos activos. Primero registrá o activá un contacto.</div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label for="tipo" class="form-label">Tipo *</label>
            <select class="form-select" id="tipo" name="tipo" required>
                <option value="">Seleccioná un tipo</option>
                <?php foreach (TIPOS_SEGUIMIENTO as $valor => $etiqueta): ?>
                    <option value="<?= e($valor) ?>" <?= $tipo === $valor ? 'selected' : '' ?>>
                        <?= e($etiqueta) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="descripcion" class="form-label">Descripción *</label>
            <textarea class="form-control" id="descripcion" name="descripcion"
                      rows="4" required><?= e($descripcion) ?></textarea>
        </div>

        <div class="mb-3">
            <label for="fecha_vencimiento" class="form-label">Fecha de vencimiento</label>
            <input type="date" class="form-control" id="fecha_vencimiento"
                   name="fecha_vencimiento" value="<?= e($fechaVencimiento) ?>">
            <div class="form-text">Este campo es opcional.</div>
        </div>

        <div class="mb-3">
            <label for="estado" class="form-label">Estado *</label>
            <select class="form-select" id="estado" name="estado" required>
                <?php foreach (ESTADOS_SEGUIMIENTO as $valor => $etiqueta): ?>
                    <option value="<?= e($valor) ?>" <?= $estado === $valor ? 'selected' : '' ?>>
                        <?= e($etiqueta) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <p class="adi-sub mb-0">Los campos con * son obligatorios.</p>

        <div class="d-flex gap-2 pt-3 mt-3 border-top">
            <button type="submit" class="btn adi-btn-primary">Guardar seguimiento</button>
            <a href="index.php" class="btn adi-btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php pie(); ?>
