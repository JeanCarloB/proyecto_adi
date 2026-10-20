<?php
require_once dirname(__DIR__) . '/includes/seguimientos.php';

$coleccionSeguimientos = $baseDatos->selectCollection('seguimientos');
$coleccionContactos = $baseDatos->selectCollection('contactos');

$filtroEstado = $_GET['estado'] ?? 'todos';

if (!in_array($filtroEstado, ['todos', 'pendiente', 'completado'], true)) {
    $filtroEstado = 'todos';
}

$filtroMongo = [];

if ($filtroEstado !== 'todos') {
    if ($filtroEstado === 'pendiente') {
        $filtroMongo['$or'] = [
            ['estado' => 'pendiente'],
            ['estado' => ['$exists' => false]],
        ];
    } else {
        $filtroMongo['estado'] = $filtroEstado;
    }
}

$seguimientos = $coleccionSeguimientos
    ->find($filtroMongo, ['sort' => ['fecha_creacion' => -1]])
    ->toArray();

$contactos = $coleccionContactos
    ->find([], ['sort' => ['nombre' => 1]])
    ->toArray();

$nombresContactos = [];
foreach ($contactos as $contacto) {
    $nombresContactos[(string) $contacto['_id']] = $contacto['nombre'] ?? 'Sin nombre';
}

encabezado('Seguimientos', 'seguimientos');

tituloPagina(
    'Seguimientos',
    'Notas y recordatorios relacionados con los contactos de la ADI.',
    ['texto' => 'Nuevo seguimiento', 'url' => 'crear.php'],
    'contactos'
);

avisoExito([
    'creado' => 'El seguimiento se registró correctamente.',
    'editado' => 'Los cambios se guardaron correctamente.',
    'eliminado' => 'El seguimiento se eliminó correctamente.',
]);

if (($_GET['ok'] ?? '') === 'error') {
    aviso('danger', 'No se pudo eliminar el seguimiento. Verificá el registro e intentá nuevamente.');
}
?>

<section class="adi-stats adi-stats--3" aria-label="Resumen de seguimientos">
    <div class="adi-stat">
        <div class="adi-stat-label">Total de seguimientos</div>
        <div class="adi-stat-value"><?= number_format(count($seguimientos), 0, ',', '.') ?></div>
    </div>
    <?php foreach (ESTADOS_SEGUIMIENTO as $estado => $etiqueta): ?>
        <?php
        $cantidad = count(array_filter(
            $seguimientos,
            fn($s) => ($s['estado'] ?? 'pendiente') === $estado
        ));
        ?>
        <div class="adi-stat">
            <div class="adi-stat-label"><?= e($etiqueta) ?></div>
            <div class="adi-stat-value"><?= number_format($cantidad, 0, ',', '.') ?></div>
        </div>
    <?php endforeach; ?>
</section>

<?php if (empty($seguimientos)): ?>
    <div class="adi-card adi-empty">
        <strong>Todavía no hay seguimientos registrados</strong>
        Cuando registres una nota o un recordatorio, aparecerá aquí.
    </div>
<?php else: ?>
    <div class="adi-card">
        <div class="adi-table-meta">
    <span>
        <?= count($seguimientos) ?>
        <?= count($seguimientos) === 1 ? 'seguimiento' : 'seguimientos' ?>
        <?= $filtroEstado !== 'todos'
            ? ' · ' . e(ESTADOS_SEGUIMIENTO[$filtroEstado])
            : '' ?>
    </span>
    <span>Ordenados por fecha de creación</span>
</div>

<form method="GET" action="index.php" class="row g-2 align-items-end mb-3">
    <div class="col-sm-6 col-md-4">
        <label for="estado" class="form-label">Filtrar por estado</label>
        <select name="estado" id="estado" class="form-select">
            <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>
                Todos los seguimientos
            </option>
            <option value="pendiente" <?= $filtroEstado === 'pendiente' ? 'selected' : '' ?>>
                Solo pendientes
            </option>
            <option value="completado" <?= $filtroEstado === 'completado' ? 'selected' : '' ?>>
                Solo completados
            </option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn adi-btn-primary">Filtrar</button>
        <a href="index.php" class="btn adi-btn-ghost">Limpiar</a>
    </div>
</form>

        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr>
                        <th>Contacto</th>
                        <th>Tipo</th>
                        <th>Descripción</th>
                        <th>Vencimiento</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($seguimientos as $seguimiento): ?>
                    <?php
                    $contactoId = isset($seguimiento['contacto_id'])
                        ? (string) $seguimiento['contacto_id']
                        : '';

                    $nombreContacto = $nombresContactos[$contactoId] ?? 'Contacto no encontrado';
                    $tipo = $seguimiento['tipo'] ?? '';
                    $estado = $seguimiento['estado'] ?? 'pendiente';
                    $descripcion = $seguimiento['descripcion'] ?? '';

                    $vencimiento = $seguimiento['fecha_vencimiento'] ?? null;
                    $fechaVencimiento = $vencimiento instanceof MongoDB\BSON\UTCDateTime
                        ? $vencimiento->toDateTime()->format('d/m/Y')
                        : '—';

                    $claseEstado = match ($estado) {
                        'completado' => 'adi-pill--ok',
                        'pendiente' => 'adi-pill--warn',
                        default => 'adi-pill--neutral',
                    };
                    ?>
                    <tr>
                        <td class="adi-contact-name"><?= e($nombreContacto) ?></td>
                        <td><span class="adi-tag"><?= e(TIPOS_SEGUIMIENTO[$tipo] ?? $tipo) ?></span></td>
                        <td><?= e($descripcion) ?></td>
                        <td class="text-nowrap"><?= e($fechaVencimiento) ?></td>
                        <td>
                            <span class="adi-pill <?= e($claseEstado) ?>">
                                <?= e(ESTADOS_SEGUIMIENTO[$estado] ?? $estado) ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="editar.php?id=<?= urlencode((string) $seguimiento['_id']) ?>"
                               class="btn btn-sm adi-btn-ghost">Editar</a>
                            <form action="eliminar.php" method="POST" class="d-inline"
                                  onsubmit="return confirm('¿Deseás eliminar este seguimiento?');">
                                <input type="hidden" name="id"
                                       value="<?= e((string) $seguimiento['_id']) ?>">
                                <button type="submit" class="btn btn-sm adi-btn-quiet-danger">
                                    Eliminar
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php pie(); ?>
