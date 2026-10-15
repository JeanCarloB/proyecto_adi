
<?php
require_once dirname(__DIR__) . '/includes/conexion.php';

$errores = [];
$nombre = '';
$tipo = '';
$telefono = '';
$correo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo = trim($_POST['correo'] ?? '');

    // Validaciones básicas
    if (mb_strlen($nombre) < 2) {
        $errores[] = 'El nombre debe tener al menos 2 caracteres.';
    }

    if (!in_array($tipo, ['vecino', 'comercio', 'institucion'], true)) {
        $errores[] = 'Seleccioná un tipo de contacto válido.';
    }

    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingresá un correo electrónico válido.';
    }

    if (!$errores) {
        try {
            $coleccion = $baseDatos->selectCollection('contactos');

            $coleccion->insertOne([
                'nombre' => $nombre,
                'tipo' => $tipo,
                'telefono' => $telefono,
                'correo' => $correo,
                'activo' => true,
                'fecha_registro' => new MongoDB\BSON\UTCDateTime()
            ]);

            header('Location: index.php?creado=1');
            exit;
        } catch (Throwable $e) {
            $errores[] = 'No se pudo registrar el contacto. Revisá los datos e intentá nuevamente.';
        }
    }
}

function escaparFormulario(string $valor): string {
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo contacto | Sistema de gestión comunal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-success">
    <div class="container">
        <a class="navbar-brand" href="../index.php">Sistema de gestión comunal</a>
    </div>
</nav>

<main class="container py-4" style="max-width: 760px">
    <h1 class="h3 mb-1">Registrar contacto</h1>
    <p class="text-muted mb-4">Completá la información del contacto.</p>

    <?php if ($errores): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errores as $error): ?>
                    <li><?= escaparFormulario($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST">
                <div class="mb-3">
                    <label for="nombre" class="form-label">Nombre completo o entidad *</label>
                    <input type="text" class="form-control" id="nombre"
                           name="nombre" required minlength="2"
                           value="<?= escaparFormulario($nombre) ?>">
                </div>

                <div class="mb-3">
                    <label for="tipo" class="form-label">Tipo de contacto *</label>
                    <select class="form-select" id="tipo" name="tipo" required>
                        <option value="">Seleccioná una opción</option>
                        <option value="vecino" <?= $tipo === 'vecino' ? 'selected' : '' ?>>Vecino</option>
                        <option value="comercio" <?= $tipo === 'comercio' ? 'selected' : '' ?>>Comercio</option>
                        <option value="institucion" <?= $tipo === 'institucion' ? 'selected' : '' ?>>Institución</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="telefono" class="form-label">Teléfono</label>
                    <input type="tel" class="form-control" id="telefono"
                           name="telefono"
                           value="<?= escaparFormulario($telefono) ?>">
                </div>

                <div class="mb-3">
                    <label for="correo" class="form-label">Correo electrónico</label>
                    <input type="email" class="form-control" id="correo"
                           name="correo"
                           value="<?= escaparFormulario($correo) ?>">
                </div>

                <p class="text-muted small">Los campos con * son obligatorios.</p>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">Guardar contacto</button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</main>

</body>
</html>
