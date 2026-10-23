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

require_once __DIR__ . '/sesion.php';   // usuarioActual(), esAdmin(), requiereLogin(), requiereRol()

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
 * $seccion marca el enlace activo: 'espacios', 'reservas', 'calendario',
 * 'actividades', 'ventas' o 'contactos'.
 */
function encabezado(string $titulo, string $seccion = ''): void
{
    $enlaces = [
        'espacios'    => ['Espacios',    'src/espacios/index.php',      'alquileres'],
        'reservas'    => ['Reservas',    'src/reservas/index.php',      'alquileres'],
        'calendario'  => ['Calendario',  'src/reservas/calendario.php', 'alquileres'],
        'actividades' => ['Actividades', 'src/actividades/index.php',   'ventas'],
        'ventas'      => ['Ventas',      'src/ventas/index.php',        'ventas'],
        'contactos'   => ['Contactos',   'src/contactos/index.php',     'contactos'],
        'seguimientos' => ['Seguimientos', 'src/seguimientos/index.php', 'contactos'],
    ];
    $usuario = usuarioActual();
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
        <?php if ($usuario): ?>
        <nav class="adi-nav" aria-label="Módulos">
            <?php foreach ($enlaces as $clave => [$texto, $ruta, $modulo]): ?>
                <a href="<?= RAIZ . $ruta ?>" class="<?= $clave === $seccion ? 'is-active' : '' ?>"
                   <?= $clave === $seccion ? 'aria-current="page"' : '' ?>>
                    <span class="adi-dot adi-dot--<?= $modulo ?>"></span><?= e($texto) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form action="<?= RAIZ ?>logout.php" method="POST" class="adi-user">
            <?= campoCsrf() ?>
            <span class="adi-user-name" title="<?= e($usuario['correo']) ?>">
                <?= e($usuario['nombre']) ?>
                <small><?= e(ROLES[$usuario['rol']] ?? $usuario['rol']) ?></small>
            </span>
            <button type="submit" class="btn btn-sm adi-btn-ghost">Salir</button>
        </form>
        <?php endif; ?>
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

/** Mínimo y máximo de filas por página que se pueden pedir con ?por= */
const POR_PAGINA_MIN = 5;
const POR_PAGINA_MAX = 100;

/**
 * Cantidad de filas por página que pidió el usuario (?por=), dentro de los límites.
 * Uso: $porPagina = leerPorPagina(15);
 */
function leerPorPagina(int $defecto = 15): int
{
    $por = (int) ($_GET['por'] ?? $defecto);
    return min(max($por, POR_PAGINA_MIN), POR_PAGINA_MAX);
}

/**
 * Paginación debajo de una lista: Anterior / Siguiente, ir a una página exacta
 * y elegir cuántas filas mostrar. Conserva los filtros de la URL.
 *
 * Uso: paginador($pagina, $paginas, $total, $porPagina);
 */
function paginador(int $pagina, int $paginas, int $total, int $porPagina): void
{
    if ($total <= POR_PAGINA_MIN) {
        return;   // cabe todo en una página mínima: no hace falta paginar
    }

    // Filtros actuales de la URL (sin la página, la cantidad ni los avisos)
    $otros = $_GET;
    unset($otros['pagina'], $otros['por'], $otros['ok'], $otros['error']);

    $url = fn (int $n): string => '?' . http_build_query($otros + ['por' => $porPagina, 'pagina' => $n]);

    $desde = ($pagina - 1) * $porPagina + 1;
    $hasta = min($pagina * $porPagina, $total);
    ?>
    <form method="GET" class="adi-pager" aria-label="Paginación">
        <?php foreach ($otros as $clave => $valor): if (is_string($valor)): ?>
            <input type="hidden" name="<?= e($clave) ?>" value="<?= e($valor) ?>">
        <?php endif; endforeach; ?>

        <div class="adi-pager-info">
            <span><?= number_format($desde, 0, ',', '.') ?>–<?= number_format($hasta, 0, ',', '.') ?>
                de <?= number_format($total, 0, ',', '.') ?></span>
            <label class="adi-pager-field">
                Mostrar
                <input type="number" name="por" value="<?= $porPagina ?>"
                       min="<?= POR_PAGINA_MIN ?>" max="<?= POR_PAGINA_MAX ?>"
                       class="form-control form-control-sm"
                       onchange="this.form.pagina.value = 1; this.form.submit();"
                       aria-label="Filas por página">
                por página
            </label>
        </div>

        <div class="adi-pager-nav">
            <?php if ($pagina > 1): ?>
                <a class="btn btn-sm adi-btn-ghost" href="<?= e($url($pagina - 1)) ?>" rel="prev">‹ Anterior</a>
            <?php else: ?>
                <span class="btn btn-sm adi-btn-ghost disabled" aria-disabled="true">‹ Anterior</span>
            <?php endif; ?>

            <label class="adi-pager-field">
                Página
                <input type="number" name="pagina" value="<?= $pagina ?>" min="1" max="<?= $paginas ?>"
                       class="form-control form-control-sm" aria-label="Ir a la página">
                de <?= $paginas ?>
            </label>
            <button type="submit" class="btn btn-sm adi-btn-soft">Ir</button>

            <?php if ($pagina < $paginas): ?>
                <a class="btn btn-sm adi-btn-ghost" href="<?= e($url($pagina + 1)) ?>" rel="next">Siguiente ›</a>
            <?php else: ?>
                <span class="btn btn-sm adi-btn-ghost disabled" aria-disabled="true">Siguiente ›</span>
            <?php endif; ?>
        </div>
    </form>
    <?php
}
