<?php
/**
 * Registrar un usuario nuevo: correo, nombre, contraseña y rol.
 * La contraseña se guarda con password_hash; login.php la compara con password_verify.
 */
require_once dirname(__DIR__) . '/includes/usuarios.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario

use MongoDB\BSON\UTCDateTime;

$errores = [];
$correo  = '';
$nombre  = '';
$rol     = 'comunidad';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo     = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
    $nombre     = trim((string) ($_POST['nombre'] ?? ''));
    $rol        = (string) ($_POST['rol'] ?? '');
    $contrasena = (string) ($_POST['contrasena'] ?? '');
    $repetir    = (string) ($_POST['repetir'] ?? '');

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Escriba un correo válido.';
    }
    if (mb_strlen($nombre) > 80) {
        $errores[] = 'El nombre puede tener como máximo 80 caracteres.';
    }
    if (!isset(ROLES[$rol])) {
        $errores[] = 'Elija el rol del usuario.';
    }
    if (mb_strlen($contrasena) < CONTRASENA_MIN) {
        $errores[] = 'La contraseña debe tener al menos ' . CONTRASENA_MIN . ' caracteres.';
    } elseif ($contrasena !== $repetir) {
        $errores[] = 'Las dos contraseñas no coinciden.';
    }

    if (!$errores) {
        $usuario = [
            'correo'     => $correo,
            'contrasena' => password_hash($contrasena, PASSWORD_DEFAULT),
            'rol'        => $rol,
            'creado'     => new UTCDateTime(),
        ];
        if ($nombre !== '') {
            $usuario['nombre'] = $nombre;   // sin nombre, la barra muestra el correo
        }

        try {
            coleccionUsuarios($baseDatos)->insertOne($usuario);
            header('Location: index.php?ok=creado');
            exit;
        } catch (Throwable $e) {
            if (!correoRepetido($e)) {
                throw $e;
            }
            $errores[] = 'Ya existe un usuario con ese correo.';
        }
    }
}

encabezado('Nuevo usuario', 'usuarios');
?>
<div class="adi-form-narrow">
    <a href="index.php" class="adi-sub text-decoration-none d-inline-block mb-3">← Usuarios</a>
    <?php tituloPagina(
        'Nuevo usuario',
        'Entregue el correo y la contraseña a la persona; con eso entra al sistema.',
        null,
        'usuarios'
    ); ?>

    <?php listaErrores($errores); ?>

    <form method="POST" class="adi-card adi-card-body" novalidate>
        <?= campoCsrf() ?>
        <div class="row g-3 mb-4">
            <div class="col-sm-6">
                <label for="correo" class="form-label">Correo</label>
                <input type="email" class="form-control" id="correo" name="correo"
                       required autocomplete="off" value="<?= e($correo) ?>">
            </div>
            <div class="col-sm-6">
                <label for="nombre" class="form-label">Nombre <span class="adi-sub">(opcional)</span></label>
                <input type="text" class="form-control" id="nombre" name="nombre"
                       maxlength="80" value="<?= e($nombre) ?>">
            </div>
            <div class="col-sm-6">
                <label for="contrasena" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="contrasena" name="contrasena"
                       required minlength="<?= CONTRASENA_MIN ?>" autocomplete="new-password">
                <div class="adi-sub mt-1">Al menos <?= CONTRASENA_MIN ?> caracteres.</div>
            </div>
            <div class="col-sm-6">
                <label for="repetir" class="form-label">Repita la contraseña</label>
                <input type="password" class="form-control" id="repetir" name="repetir"
                       required autocomplete="new-password">
            </div>
            <div class="col-sm-6">
                <label for="rol" class="form-label">Rol</label>
                <select class="form-select" id="rol" name="rol" required>
                    <?php foreach (ROLES as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $rol === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="d-flex gap-2 pt-2 border-top" style="border-color: var(--adi-border) !important">
            <button type="submit" class="btn adi-btn-primary mt-3">Registrar usuario</button>
            <a href="index.php" class="btn adi-btn-ghost mt-3">Cancelar</a>
        </div>
    </form>
</div>
<?php pie();
