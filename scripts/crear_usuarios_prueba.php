<?php
/**
 * Crea dos usuarios ficticios para probar el login y los permisos:
 * uno con rol admin y otro con rol comunidad.
 *
 * Las contraseñas se guardan con password_hash (bcrypt), así que el login
 * solo tiene que hacer password_verify($contrasena, $usuario['contrasena']).
 * Los correos usan el dominio example.com: no son personas reales.
 *
 * Es idempotente: si el correo ya existe, actualiza el hash y el rol en vez
 * de duplicarlo. Crea además el índice único sobre correo.
 *
 * Uso (desde la raíz del proyecto):
 *   php scripts/crear_usuarios_prueba.php
 *
 * Lee MONGODB_URI y MONGODB_DB del archivo .env (o de variables de entorno).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__) . '/vendor/autoload.php';

// Credenciales de prueba: públicas a propósito, documentadas en el README.
const USUARIOS = [
    ['correo' => 'admin@example.com',     'contrasena' => 'Admin2026!',     'rol' => 'admin'],
    ['correo' => 'comunidad@example.com', 'contrasena' => 'Comunidad2026!', 'rol' => 'comunidad'],
];

$env = leerEnv(dirname(__DIR__) . '/.env');
$uri = getenv('MONGODB_URI') ?: ($env['MONGODB_URI'] ?? '');
$dbName = getenv('MONGODB_DB') ?: ($env['MONGODB_DB'] ?? 'adi');
if ($uri === '') {
    fwrite(STDERR, "Falta MONGODB_URI: copie .env.example a .env y complételo.\n");
    exit(1);
}

$db = (new MongoDB\Client($uri, ['serverSelectionTimeoutMS' => 5000]))->selectDatabase($dbName);
$db->command(['ping' => 1]);
echo "Conectado a la base {$dbName}.\n";

$usuarios = $db->selectCollection('usuarios');
$usuarios->createIndex(['correo' => 1], ['unique' => true, 'name' => 'correo_unico']);

foreach (USUARIOS as $u) {
    $r = $usuarios->updateOne(
        ['correo' => $u['correo']],
        [
            '$set' => [
                'contrasena' => password_hash($u['contrasena'], PASSWORD_DEFAULT),
                'rol'        => $u['rol'],
            ],
            '$setOnInsert' => ['creado' => new UTCDateTime()],
        ],
        ['upsert' => true]
    );
    $accion = $r->getUpsertedCount() ? 'creado' : 'actualizado';
    echo "  {$u['correo']} ({$u['rol']}): {$accion}\n";
}

function leerEnv(string $archivo): array
{
    $env = [];
    if (!is_readable($archivo)) {
        return $env;
    }
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;
        [$k, $v] = explode('=', $linea, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
    return $env;
}
