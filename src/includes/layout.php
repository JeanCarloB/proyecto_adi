<?php
/**
 * ADIministra · Diseño compartido de toda la aplicación.
 *
 * Cabecera, pie, títulos y avisos que usan todos los módulos, para que
 * la aplicación se vea como una sola. Los estilos viven en assets/css/adi.css.
 *
 * Uso en cualquier página:
 *   require_once dirname(__DIR__) . '/includes/layout.php';
 *   encabezado('Título', 'contactos');
 *   tituloPagina('Título', 'Descripción', ['texto' => 'Nuevo', 'url' => 'crear.php'], 'contactos');
 *   ...
 *   pie();
 */

/**
 * Ruta relativa desde la página que se está mostrando hasta la raíz del
 * proyecto ('', '../', '../../'...). Se calcula sola, así los enlaces de la
 * barra funcionan desde cualquier carpeta, en local y en el servidor.
 */
function calcularRaiz(): string
{
    $proyecto = realpath(dirname(__DIR__, 2));
    $pagina   = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));

    if ($proyecto === false || $pagina === false || !str_starts_with($pagina, $proyecto)) {
        return '';
    }

    $relativo = trim(str_replace('\\', '/', substr($pagina, strlen($proyecto))), '/');
    return $relativo === '' ? '' : str_repeat('../', substr_count($relativo, '/') + 1);
}

define('RAIZ', calcularRaiz());

/** Módulos del sistema: nombre para mostrar y color (sale del logo). */
const MODULOS = [
    'alquileres' => 'Alquiler de espacios',
    'ventas'     => 'Ventas en actividades',
    'contactos'  => 'Contactos y relaciones',
];

function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

/**
 * Cabecera común de todas las páginas.
 * $seccion marca el enlace activo: 'espacios', 'reservas' o 'contactos'.
 */
function encabezado(string $titulo, string $seccion = ''): void
{
    $enlaces = [
        'espacios'  => ['Espacios',  'src/espacios/index.php',  'alquileres'],
        'reservas'  => ['Reservas',  'src/reservas/index.php',  'alquileres'],
        'contactos' => ['Contactos', 'src/contactos/index.php', 'contactos'],
    ];
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · ADIministra</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= RAIZ ?>assets/css/adi.css" rel="stylesheet">
</head>
<body>

<header class="adi-topbar">
    <div class="container">
        <a class="adi-brand" href="<?= RAIZ ?>index.php">
            <span class="adi-wordmark"><b>ADI</b>ministra</span>
            <span class="adi-tagline">Gestión comunal</span>
        </a>
        <nav class="adi-nav" aria-label="Módulos">
            <?php foreach ($enlaces as $clave => [$texto, $ruta, $modulo]): ?>
                <a href="<?= RAIZ . $ruta ?>" class="<?= $clave === $seccion ? 'is-active' : '' ?>"
                   <?= $clave === $seccion ? 'aria-current="page"' : '' ?>>
                    <span class="adi-dot adi-dot--<?= $modulo ?>"></span><?= e($texto) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>

<main class="container adi-page">
    <?php
}

function pie(): void
{
    ?>
</main>
</body>
</html>
    <?php
}

/**
 * Encabezado de cada página: módulo, título, descripción y una acción opcional.
 * $accion = ['texto' => '...', 'url' => '...']
 */
function tituloPagina(string $titulo, string $descripcion = '', ?array $accion = null, string $modulo = 'alquileres'): void
{
    ?>
    <div class="adi-page-head">
        <div>
            <div class="adi-eyebrow">
                <span class="adi-dot adi-dot--<?= e($modulo) ?>"></span><?= e(MODULOS[$modulo] ?? '') ?>
            </div>
            <h1 class="adi-title"><?= e($titulo) ?></h1>
            <?php if ($descripcion !== ''): ?>
                <p class="adi-lead"><?= e($descripcion) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($accion): ?>
            <a href="<?= e($accion['url']) ?>" class="btn adi-btn-primary text-nowrap"><?= e($accion['texto']) ?></a>
        <?php endif; ?>
    </div>
    <?php
}

/** Aviso de una línea. $tipo: 'ok', 'warn' o 'danger'. */
function aviso(string $tipo, string $mensaje): void
{
    $icono = ['ok' => '✓', 'warn' => '!', 'danger' => '!'][$tipo] ?? 'i';
    echo '<div class="adi-alert adi-alert--' . e($tipo) . '" role="status">'
        . '<span class="adi-alert-icon"><span>' . $icono . '</span></span>'
        . '<div>' . e($mensaje) . '</div></div>';
}

/** Muestra un aviso de éxito según el parámetro ?ok= de la URL. */
function avisoExito(array $mensajes): void
{
    $clave = $_GET['ok'] ?? '';
    if (is_string($clave) && isset($mensajes[$clave])) {
        aviso('ok', $mensajes[$clave]);
    }
}

function listaErrores(array $errores): void
{
    if (!$errores) {
        return;
    }
    echo '<div class="adi-alert adi-alert--danger" role="alert">'
        . '<span class="adi-alert-icon"><span>!</span></span><div>';
    if (count($errores) === 1) {
        echo e($errores[0]);
    } else {
        echo '<ul>';
        foreach ($errores as $error) {
            echo '<li>' . e($error) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div></div>';
}
