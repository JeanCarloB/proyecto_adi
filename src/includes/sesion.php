<?php
/**
 * ADIministra · Sesión de usuario y permisos por rol.
 *
 * Después de iniciar sesión, $_SESSION['usuario'] tiene:
 *   ['id' => '...', 'correo' => '...', 'nombre' => '...', 'rol' => 'admin' | 'comunidad']
 *
 * Uso en cualquier página (antes de mostrar nada):
 *   require_once dirname(__DIR__) . '/includes/layout.php';   // ya incluye sesion.php
 *   requiereLogin();            // cualquier usuario con sesión
 *   requiereRol('admin');       // solo administradores
 *
 * Las utilidades de cada módulo (alquileres.php, contactos.php, ...) ya
 * llaman a requiereLogin(), así que las páginas de módulo solo agregan
 * requiereRol() cuando la acción es de administradores.
 */

// layout.php incluye este archivo; RAIZ, e(), encabezado() y aviso() vienen de ahí.

/** Roles válidos y cómo se muestran. */
const ROLES = [
    'admin'     => 'Administración',
    'comunidad' => 'Comunidad',
];

/** Minutos sin actividad antes de cerrar la sesión sola. */
const SESION_MINUTOS = 60;

/**
 * Abre la sesión de PHP con una cookie que no se puede leer desde
 * JavaScript ni se manda desde otros sitios. Se puede llamar varias veces.
 */
function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';

    session_name('adi_sesion');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    // Cierre por inactividad
    $ahora = time();
    if (isset($_SESSION['usuario'], $_SESSION['ultima_actividad'])
        && $ahora - $_SESSION['ultima_actividad'] > SESION_MINUTOS * 60) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultima_actividad'] = $ahora;
}

/** Usuario con sesión iniciada, o null si nadie entró. */
function usuarioActual(): ?array
{
    iniciarSesion();
    return $_SESSION['usuario'] ?? null;
}

function esAdmin(): bool
{
    return (usuarioActual()['rol'] ?? '') === 'admin';
}

/**
 * Guarda al usuario en la sesión después de verificar la contraseña.
 * Cambia el id de sesión para que no se pueda reutilizar uno anterior.
 */
function guardarUsuarioEnSesion(array $usuario): void
{
    iniciarSesion();
    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'     => (string) $usuario['_id'],
        'correo' => $usuario['correo'],
        'nombre' => $usuario['nombre'] ?? $usuario['correo'],
        'rol'    => $usuario['rol'],
    ];
}

function cerrarSesion(): void
{
    iniciarSesion();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'],
    ]);
    session_destroy();
}

/**
 * Si no hay sesión, manda a login.php y recuerda la página pedida para
 * volver a ella después de entrar.
 */
function requiereLogin(): array
{
    $usuario = usuarioActual();
    if ($usuario) {
        return $usuario;
    }

    $volver = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '';
    $destino = RAIZ . 'login.php' . ($volver !== '' ? '?volver=' . urlencode($volver) : '');
    header('Location: ' . $destino);
    exit;
}

/**
 * Exige sesión y uno de los roles indicados. Si el rol no alcanza,
 * responde 403 con una página que explica por qué.
 * Uso: requiereRol('admin');
 */
function requiereRol(string ...$roles): array
{
    $usuario = requiereLogin();
    if (in_array($usuario['rol'], $roles, true)) {
        return $usuario;
    }

    http_response_code(403);
    encabezado('Sin permiso');
    ?>
    <div class="adi-form-narrow">
        <?php aviso('danger', 'Su usuario (' . (ROLES[$usuario['rol']] ?? $usuario['rol']) . ') no tiene permiso para esta acción. Pídale a una persona de administración que la haga.'); ?>
        <a href="<?= RAIZ ?>index.php" class="btn adi-btn-soft">Volver al inicio</a>
    </div>
    <?php
    pie();
    exit;
}

/**
 * Destino seguro después del login: solo rutas del mismo sitio
 * ("/proyecto_adi/src/..."), nunca otra página web.
 */
function destinoSeguro(mixed $volver): string
{
    if (is_string($volver) && preg_match('#^/(?![/\\\\])[^\s]*$#', $volver)) {
        return $volver;
    }
    return RAIZ . 'index.php';
}

/** Token contra envíos desde otros sitios (CSRF) para formularios POST. */
function tokenCsrf(): string
{
    iniciarSesion();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(tokenCsrf()) . '">';
}

function csrfValido(): bool
{
    $token = $_POST['csrf'] ?? '';
    return is_string($token) && hash_equals(tokenCsrf(), $token);
}

/**
 * Corta un POST que no trae el token CSRF de su formulario: así una página
 * de otro sitio no puede hacer que el navegador de alguien de la junta
 * guarde, cancele o borre algo sin que lo sepa. En un GET no hace nada.
 * Uso, después de requiereRol(): exigirCsrf();
 * El formulario debe llevar <?= campoCsrf() ?>.
 */
function exigirCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || csrfValido()) {
        return;
    }

    http_response_code(400);
    encabezado('Formulario vencido');
    ?>
    <div class="adi-form-narrow">
        <?php aviso('warn', 'El formulario venció o no vino de esta página, así que no se hizo ningún cambio. Vuelva a la página, recárguela e intente de nuevo.'); ?>
        <a href="<?= RAIZ ?>index.php" class="btn adi-btn-soft">Volver al inicio</a>
    </div>
    <?php
    pie();
    exit;
}
