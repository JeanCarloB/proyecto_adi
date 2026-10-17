
<?php
require_once dirname(__DIR__) . '/includes/conexion.php';

// Obtener los contactos de MongoDB
$contactos = $baseDatos->selectCollection('contactos')
    ->find([], ['sort' => ['nombre' => 1]])
    ->toArray();

function escapar($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contactos | Sistema de gestión comunal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-success">
    <div class="container">
        <a class="navbar-brand" href="../index.php">
            Sistema de gestión comunal
        </a>
    </div>
</nav>

<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Contactos</h1>
            <p class="text-muted mb-0">
                Administración de vecinos, comercios e instituciones.
            </p>
        </div>

        <a href="crear.php" class="btn btn-success">
            + Nuevo contacto
        </a>
    </div>
<?php if (isset($_GET['creado']) && $_GET['creado'] === '1'): ?>
    <div class="alert alert-success">
        El contacto se registró correctamente.
    </div>
<?php endif; ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($contactos)): ?>
                <div class="alert alert-info mb-0">
                    Todavía no hay contactos registrados.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Teléfono</th>
                                <th>Correo</th>
                                <th>Estado</th>
            					<th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contactos as $contacto): ?>
                                <tr>
                                    <td>
                                        <?= escapar($contacto['nombre'] ?? '') ?>
                                    </td>
                                    <td>
                                        <?= escapar($contacto['tipo'] ?? '') ?>
                                    </td>
                                    <td>
                                        <?= escapar($contacto['telefono'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <?= escapar($contacto['correo'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <?php if (($contacto['activo'] ?? true) === true): ?>
                                            <span class="badge bg-success">Activo</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
    									<a href="editar.php?id=<?= urlencode((string) $contacto['_id']) ?>"
       									class="btn btn-sm btn-outline-primary">
       									 Editar
   										 </a>
                                        
<?php if (($contacto['activo'] ?? true) === true): ?>
    <form action="desactivar.php" method="POST" class="d-inline"
          onsubmit="return confirm('¿Deseás desactivar este contacto?');">
        <input type="hidden" name="id"
               value="<?= escapar((string) $contacto['_id']) ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">
            Desactivar
        </button>
    </form>
<?php endif; ?>

									</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <a href="../index.php" class="btn btn-outline-secondary mt-3">
        Volver al inicio
    </a>
</main>

</body>
</html>
