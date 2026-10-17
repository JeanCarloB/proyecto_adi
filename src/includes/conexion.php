<?php

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
