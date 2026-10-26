<?php

// Si la base no responde (Atlas caído, IP no autorizada, contraseña mal escrita
// en el .env), la página muestra un aviso en vez de una pantalla en blanco.
// El detalle del error, que puede traer el host del clúster, solo va al log
// del servidor. Los scripts de consola (php scripts/...) siguen mostrando el error.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e): void {
        error_log('ADIministra: ' . $e);

        // ConnectionException cubre también el tiempo de espera agotado y la autenticación fallida
        $sinBase = $e instanceof MongoDB\Driver\Exception\ConnectionException
            || ($e instanceof RuntimeException && str_ends_with($e->getFile(), 'conexion.php'));

        $mensaje = $sinBase
            ? 'No se pudo conectar con la base de datos, así que no se guardó ni se mostró nada. Intente de nuevo en unos minutos; si sigue igual, avise a administración.'
            : 'Ocurrió un error inesperado y no se completó la acción. Intente de nuevo; si sigue igual, avise a administración.';

        if (!headers_sent()) {
            http_response_code($sinBase ? 503 : 500);
        }
        if (function_exists('encabezado') && function_exists('aviso')) {
            if (!headers_sent()) {
                encabezado('Servicio no disponible');
            }
            aviso('danger', $mensaje);
            pie();
            return;
        }
        echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    });
}

// Cargar las variables de entorno del archivo .env
$env = [];
// Desde src/includes/ la raíz del proyecto está dos niveles arriba
$envFile = dirname(__DIR__, 2) . '/.env';

if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\"'");
    }
}

// Verificar las variables necesarias
if (empty($env['MONGODB_URI'])) {
    throw new RuntimeException('No se encontró MONGODB_URI en el archivo .env');
}

// Cargar las dependencias de Composer
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    throw new RuntimeException('No se encontró vendor/autoload.php. Ejecutá composer install.');
}

require_once $autoload;

// Conectar con MongoDB Atlas
$nombreBase = $env['MONGODB_DB'] ?? 'adi';

$clienteMongo = new MongoDB\Client(
    $env['MONGODB_URI'],
    ['serverSelectionTimeoutMS' => 5000]
);

$baseDatos = $clienteMongo->selectDatabase($nombreBase);
