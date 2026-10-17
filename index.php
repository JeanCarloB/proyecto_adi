<?php
// Hola mundo: verifica PHP, la extensión mongodb, Composer y la conexión a Atlas.
require_once __DIR__ . '/src/includes/conexion.php';

$baseDatos->command(['ping' => 1]);

echo 'Conexión reutilizable con MongoDB correcta.';
exit;
$checks = [];

// 1. PHP y extensión
$checks[] = ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=')];
$checks[] = ['Extensión mongodb', extension_loaded('mongodb')];

// 2. Dependencias de Composer
$autoload = __DIR__ . '/vendor/autoload.php';
$checks[] = ['vendor/autoload.php (composer install)', file_exists($autoload)];

// 3. Leer .env (sin librerías externas)
$env = [];
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}
$checks[] = ['Archivo .env con MONGODB_URI', !empty($env['MONGODB_URI'])];

// 4. Conexión a Atlas
$dbName = $env['MONGODB_DB'] ?? 'adi';
$conexion = false;
$escritura = false;
$ultimas = [];
$colecciones = [];
$error = '';

if (file_exists($autoload) && !empty($env['MONGODB_URI'])) {
    require $autoload;
    try {
        $client = new MongoDB\Client($env['MONGODB_URI'], ['serverSelectionTimeoutMS' => 5000]);
        $client->selectDatabase('admin')->command(['ping' => 1]);
        $conexion = true;

        // Prueba de escritura: registra la visita (también crea la base si no existe)
        $usuario = parse_url($env['MONGODB_URI'], PHP_URL_USER) ?: 'desconocido';
        $estado = $client->selectDatabase($dbName)->selectCollection('estado');
        $estado->insertOne([
            'usuario' => $usuario,
            'origen'  => gethostname(),
            'fecha'   => new MongoDB\BSON\UTCDateTime(),
        ]);
        $escritura = true;
        $ultimas = $estado->find([], ['sort' => ['fecha' => -1], 'limit' => 5])->toArray();

        foreach ($client->selectDatabase($dbName)->listCollections() as $c) {
            $colecciones[] = $c->getName();
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$checks[] = ['Conexión a MongoDB Atlas', $conexion];
$checks[] = ['Escritura en la base', $escritura];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ADI · Hola mundo</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <main class="container py-5" style="max-width: 640px">
    <h1 class="h3 mb-1">Sistema de gestión comunal</h1>
    <p class="text-muted">ADI Santa Elena · prueba de entorno</p>

    <ul class="list-group mb-4">
      <?php foreach ($checks as [$label, $ok]): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <?= htmlspecialchars($label) ?>
          <span class="badge <?= $ok ? 'bg-success' : 'bg-danger' ?>"><?= $ok ? 'OK' : 'Falla' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($conexion): ?>
      <div class="alert alert-success">
        Conectado a la base <strong><?= htmlspecialchars($dbName) ?></strong>.
        Colecciones: <?= $colecciones ? htmlspecialchars(implode(', ', $colecciones)) : 'ninguna todavía' ?>.
      </div>
      <?php if ($ultimas): ?>
        <h2 class="h6 mt-4">Últimas conexiones registradas</h2>
        <table class="table table-sm bg-white">
          <thead><tr><th>Usuario</th><th>Origen</th><th>Fecha (UTC)</th></tr></thead>
          <tbody>
          <?php foreach ($ultimas as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u['usuario'] ?? '') ?></td>
              <td><?= htmlspecialchars($u['origen'] ?? '') ?></td>
              <td><?= $u['fecha']->toDateTime()->format('Y-m-d H:i:s') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
  </main>
</body>
</html>