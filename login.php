<?php
/**
 * Inicio de sesión: busca el correo en la colección usuarios y compara la
 * contraseña con password_verify contra el hash guardado (password_hash).
 */
require_once __DIR__ . '/src/includes/layout.php';   // incluye sesion.php

$volver = destinoSeguro($_GET['volver'] ?? $_POST['volver'] ?? '');

if (usuarioActual()) {
    header('Location: ' . $volver);
    exit;
}

$errores = [];
$correo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
    $contrasena = (string) ($_POST['contrasena'] ?? '');

    if (!csrfValido()) {
        $errores[] = 'La página venció. Vuelva a intentarlo.';
    } elseif ($correo === '' || $contrasena === '') {
        $errores[] = 'Escriba su correo y su contraseña.';
    } else {
        try {
            require_once __DIR__ . '/src/includes/conexion.php';
            $usuario = $baseDatos->selectCollection('usuarios')->findOne(
                ['correo' => $correo],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );

            // Si el correo no existe se verifica igual contra un hash cualquiera,
            // para que la respuesta tarde lo mismo y no revele qué correos existen.
            $hash = $usuario['contrasena'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
            $correcta = password_verify($contrasena, $hash);

            if ($usuario && $correcta && isset(ROLES[$usuario['rol'] ?? ''])) {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $baseDatos->selectCollection('usuarios')->updateOne(
                        ['_id' => $usuario['_id']],
                        ['$set' => ['contrasena' => password_hash($contrasena, PASSWORD_DEFAULT)]]
                    );
                }
                guardarUsuarioEnSesion($usuario);
                header('Location: ' . $volver);
                exit;
            }

            sleep(1);   // frena los intentos repetidos
            $errores[] = 'Correo o contraseña incorrectos.';
        } catch (Throwable $e) {
            $errores[] = 'No se pudo conectar con la base de datos. Intente de nuevo en un momento.';
        }
    }
}

encabezado('Iniciar sesión');
?>
<div class="adi-form-narrow" style="max-width: 420px">
    <div class="adi-page-head">
        <div>
            <h1 class="adi-title">Iniciar sesión</h1>
            <p class="adi-lead">Entre con el correo y la contraseña que le dio la administración.</p>
        </div>
    </div>

    <?php if (($_GET['salio'] ?? '') === '1'): aviso('ok', 'Cerró la sesión.'); endif; ?>
    <?php listaErrores($errores); ?>

    <form method="POST" class="adi-card adi-card-body" novalidate>
        <?= campoCsrf() ?>
        <input type="hidden" name="volver" value="<?= e($volver) ?>">
        <div class="mb-3">
            <label for="correo" class="form-label">Correo</label>
            <input type="email" class="form-control" id="correo" name="correo"
                   autocomplete="username" required autofocus value="<?= e($correo) ?>">
        </div>
        <div class="mb-4">
            <label for="contrasena" class="form-label">Contraseña</label>
            <input type="password" class="form-control" id="contrasena" name="contrasena"
                   autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn adi-btn-primary w-100">Entrar</button>
    </form>
</div>
<?php pie();
