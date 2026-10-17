
<?php
require_once dirname(__DIR__) . '/includes/conexion.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$errores = [];
$coleccion = $baseDatos->selectCollection('contactos');
$id = $_GET['id'] ?? '';

if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) {
    http_response_code(400);
    exit('El identificador del contacto no es válido.');
}

$objectId = new ObjectId($id);
$contacto = $coleccion->findOne(['_id' => $objectId]);

if (!$contacto) {
    http_response_code(404);
    exit('No se encontró el contacto solicitado.');
}

$nombre = $contacto['nombre'] ?? '';
$tipo = $contacto['tipo'] ?? '';
$telefono = $contacto['telefono'] ?? '';
$correo = $contacto['correo'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo = trim($_POST['correo'] ?? '');

    if (mb_strlen($nombre) < 2) {
        $errores[] = 'El nombre debe tener al menos 2 caracteres.';
    }

    if (!in_array($tipo, ['vecino', 'comercio', 'institucion'], true)) {
        $errores[] = 'Seleccioná un tipo válido.';
    }

    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingresá un correo electrónico válido.';
    }

    if (!$errores) {
        try {
            $coleccion->updateOne(
                ['_id' => $objectId],
                ['$set' => [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'telefono' => $telefono,
                    'correo' => $correo
                ]]
            );

            header('Location: index.php?editado=1');
            exit;
        } catch (Throwable $e) {
            $errores[] = 'No se pudo actualizar el contacto. Revisá los datos e intentá nuevamente.';
        }
    }
}

function escaparEdicion(string $valor): string {
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar contacto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4" style="max-width: 760px">
    <h1 class="h3 mb-3">Editar contacto</h1>

    <?php if ($errores): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errores as $error): ?>
                    <li><?= escaparEdicion($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST">
                <div class="mb-3">
                    <label for="nombre" class="form-label">Nombre *</label>
                    <input class="form-control" id="nombre" name="nombre"
                           required minlength="2"
                           value="<?= escaparEdicion($nombre) ?>">
                </div>

                <div class="mb-3">
                    <label for="tipo" class="form-label">Tipo *</label>
                    <select class="form-select" id="tipo" name="tipo" required>
                        <option value="vecino" <?= $tipo === 'vecino' ? 'selected' : '' ?>>Vecino</option>
                        <option value="comercio" <?= $tipo === 'comercio' ? 'selected' : '' ?>>Comercio</option>
                        <option value="institucion" <?= $tipo === 'institucion' ? 'selected' : '' ?>>Institución</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="telefono" class="form-label">Teléfono</label>
                    <input type="tel" class="form-control" id="telefono" name="telefono"
                           value="<?= escaparEdicion($telefono) ?>">
                </div>

                <div class="mb-3">
                    <label for="correo" class="form-label">Correo electrónico</label>
                    <input type="email" class="form-control" id="correo" name="correo"
                           value="<?= escaparEdicion($correo) ?>">
                </div>

                <button class="btn btn-primary" type="submit">Guardar cambios</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
            </form>
        </div>
    </div>
</main>
</body>
</html>
