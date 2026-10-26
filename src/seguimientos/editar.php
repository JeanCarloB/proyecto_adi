
<?php
require_once dirname(__DIR__) . '/includes/seguimientos.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario

$coleccionSeguimientos = $baseDatos->selectCollection('seguimientos');
$coleccionContactos = $baseDatos->selectCollection('contactos');

$id = $_GET['id'] ?? '';

try {
    if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) {
        throw new InvalidArgumentException('El identificador no es válido.');
    }

    $objectId = new MongoDB\BSON\ObjectId($id);
    $seguimiento = $coleccionSeguimientos->findOne(['_id' => $objectId]);

    if ($seguimiento === null) {
        throw new RuntimeException('El seguimiento no existe.');
    }
} catch (Throwable $e) {
    encabezado('Seguimiento no encontrado', 'contactos');
    tituloPagina('Seguimiento no encontrado', '', null, 'contactos');
    aviso('danger', $e instanceof InvalidArgumentException
        ? 'El identificador del seguimiento no es válido.'
        : 'No se encontró el seguimiento solicitado.');
    echo '<a href="index.php" class="btn adi-btn-ghost">Volver al listado</a>';
    pie();
    exit;
}

$contactos = $coleccionContactos
    ->find(
        ['$or' => [
            ['activo' => true],
            ['_id' => $seguimiento['contacto_id'] ?? null],
        ]],
        ['sort' => ['nombre' => 1]]
    )
    ->toArray();

$contactoId = isset($seguimiento['contacto_id'])
    ? (string) $seguimiento['contacto_id']
    : '';
$tipo = (string) ($seguimiento['tipo'] ?? '');
$descripcion = (string) ($seguimiento['descripcion'] ?? '');
$estado = (string) ($seguimiento['estado'] ?? 'pendiente');

$vencimiento = $seguimiento['fecha_vencimiento'] ?? null;
$fechaVencimiento = $vencimiento instanceof MongoDB\BSON\UTCDateTime
    ? $vencimiento->toDateTime()->format('Y-m-d')
    : '';

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contactoId = trim($_POST['contacto_id'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fechaVencimiento = trim($_POST['fecha_vencimiento'] ?? '');
    $estado = trim($_POST['estado'] ?? '');

    $contactoValido = null;

    if ($contactoId === '') {
        $errores[] = 'Seleccioná un contacto.';
    } else {
        try {
            $contactoValido = $coleccionContactos->findOne([
                '_id' => new MongoDB\BSON\ObjectId($contactoId),
            ]);

            if ($contactoValido === null) {
                $errores[] = 'El contacto seleccionado no existe.';
            } elseif (($contactoValido['activo'] ?? true) !== true
                && $contactoId !== (string) ($seguimiento['contacto_id'] ?? '')) {
                $errores[] = 'No podés asociar el seguimiento a un contacto inactivo.';
            }
        } catch (Throwable $e) {
            $errores[] = 'El contacto seleccionado no es válido.';
        }
    }

    if (!isset(TIPOS_SEGUIMIENTO[$tipo])) {
        $errores[] = 'Seleccioná un tipo válido.';
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
        $actualizacion = [
            '$set' => [
                'contacto_id' => new MongoDB\BSON\ObjectId($contactoId),
                'tipo' => $tipo,
                'descripcion' => $descripcion,
                'estado' => $estado,
            ],
        ];

        // $set y $unset no pueden tocar el mismo campo en una actualización
        if ($fechaMongo !== null) {
            $actualizacion['$set']['fecha_vencimiento'] = $fechaMongo;
        } else {
            $actualizacion['$unset'] = ['fecha_vencimiento' => ''];
        }

        $coleccionSeguimientos->updateOne(
            ['_id' => $objectId],
            $actualizacion
        );

        header('Location: index.php?ok=editado');
        exit;
    }
}

encabezado('Editar seguimiento', 'contactos');
tituloPagina(
    'Editar seguimiento',
    'Modificá los datos del seguimiento seleccionado.',
    null,
    'contactos'
);

if ($errores) {
    listaErrores($errores);
}
?>

<div class="adi-form-narrow">
    <form method="POST" class="adi-card adi-card-body">
        <?= campoCsrf() ?>
        <div class="mb-3">
            <label for="contacto_id" class="form-label">Contacto *</label>
            <select class="form-select" id="contacto_id" name="contacto_id" required>
                <?php foreach ($contactos as $contacto): ?>
                    <option value="<?= e((string) $contacto['_id']) ?>"
                        <?= $contactoId === (string) $contacto['_id'] ? 'selected' : '' ?>>
                        <?= e($contacto['nombre'] ?? 'Sin nombre') ?>
                        <?= ($contacto['activo'] ?? true) !== true ? ' (Inactivo)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="tipo" class="form-label">Tipo *</label>
            <select class="form-select" id="tipo" name="tipo" required>
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
            <button type="submit" class="btn adi-btn-primary">Guardar cambios</button>
            <a href="index.php" class="btn adi-btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php pie(); ?>
