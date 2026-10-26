<?php
// Verificación del entorno: PHP, extensión mongodb, Composer, .env, conexión y escritura en Atlas.
//
// Cualquiera puede ver si cada verificación sale OK o Falla (sirve aunque Atlas
// no responda y nadie pueda iniciar sesión). La prueba de escritura, las
// colecciones, las últimas conexiones y el texto del error solo se muestran
// con sesión de admin: así una visita anónima no escribe en la base ni ve
// cómo está armada.
require_once __DIR__ . '/src/includes/layout.php';   // sesion.php: esAdmin() no necesita la base
$admin = esAdmin();

$checks = [];

// 1. PHP y extensión
$checks[] = ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')];
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

        if ($admin) {
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
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$checks[] = ['Conexión a MongoDB Atlas', $conexion];
// null: sin sesión de admin no se prueba (se muestra "Solo admin")
$checks[] = ['Escritura en la base', $admin ? $escritura : null];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verificación del entorno · ADIministra</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <main class="container py-5" style="max-width: 640px">
    <h1 class="h3 mb-1">Sistema de gestión comunal</h1>
    <p class="text-muted">ADIministra· verificación del entorno · <a href="index.php">ir al sistema</a></p>

    <ul class="list-group mb-4">
      <?php foreach ($checks as [$label, $ok]): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <?= htmlspecialchars($label) ?>
          <?php if ($ok === null): ?>
            <span class="badge bg-secondary">Solo admin</span>
          <?php else: ?>
            <span class="badge <?= $ok ? 'bg-success' : 'bg-danger' ?>"><?= $ok ? 'OK' : 'Falla' ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if (!$admin): ?>
      <p class="text-muted small">
        La prueba de escritura y los detalles de la base solo se muestran a administración.
        <a href="login.php?volver=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>">Iniciar sesión</a>
      </p>
    <?php endif; ?>

    <?php if ($conexion && $admin): ?>
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
    <?php if ($error && $admin): ?>
      <div class="alert alert-danger"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
    <?php elseif ($error): ?>
      <div class="alert alert-danger">No se pudo conectar con la base de datos.</div>
    <?php endif; ?>
  </main>
</body>
</html>
