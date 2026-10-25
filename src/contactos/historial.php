
<?php
/**
 * Historial de seguimientos de un contacto.
 */
require_once dirname(__DIR__) . '/includes/contactos.php';
require_once dirname(__DIR__) . '/includes/seguimientos.php';
$id = $_GET['id'] ?? '';

try {
    $objectId = new MongoDB\BSON\ObjectId($id);
} catch (Throwable $e) {
    http_response_code(400);
    encabezado('Contacto no válido', 'contactos');
    aviso('danger', 'El identificador del contacto no es válido.');
    pie();
    exit;
}

$coleccionContactos = $baseDatos->selectCollection('contactos');

$contacto = $coleccionContactos->findOne([
    '_id' => $objectId,
]);

if (!$contacto) {
    http_response_code(404);
    encabezado('Contacto no encontrado', 'contactos');
    aviso('danger', 'No se encontró el contacto solicitado.');
    pie();
    exit;
}

// Buscar el contacto y sus seguimientos relacionados mediante $lookup.
$resultado = $coleccionContactos->aggregate([
    [
        '$match' => [
            '_id' => $objectId,
        ],
    ],
    [
        '$lookup' => [
            'from' => 'seguimientos',
            'localField' => '_id',
            'foreignField' => 'contacto_id',
            'as' => 'historial',
        ],
    ],
])->toArray();

$seguimientos = [];

if (!empty($resultado)) {
    $seguimientos = isset($resultado[0]['historial'])
    ? iterator_to_array($resultado[0]['historial'])
    : [];

    // Ordenar del seguimiento más reciente al más antiguo.
    usort($seguimientos, static function ($a, $b) {
        $fechaA = $a['fecha_creacion'] ?? null;
        $fechaB = $b['fecha_creacion'] ?? null;

        $tiempoA = $fechaA instanceof MongoDB\BSON\UTCDateTime
            ? $fechaA->toDateTime()->getTimestamp()
            : 0;

        $tiempoB = $fechaB instanceof MongoDB\BSON\UTCDateTime
            ? $fechaB->toDateTime()->getTimestamp()
            : 0;

        return $tiempoB <=> $tiempoA;
    });
}

$formatearFecha = static function ($fecha): string {
    if ($fecha instanceof MongoDB\BSON\UTCDateTime) {
        return $fecha->toDateTime()->format('d/m/Y H:i');
    }

    return '—';
};

encabezado('Historial del contacto', 'contactos');

tituloPagina(
    'Historial de ' . ($contacto['nombre'] ?? 'Contacto'),
    'Notas y recordatorios asociados a este contacto.',
    ['texto' => 'Volver a contactos', 'url' => 'index.php'],
    'contactos'
);
?>

<div class="adi-card">
    <?php if (empty($seguimientos)): ?>
        <div class="adi-empty">
            <strong>Este contacto todavía no tiene seguimientos</strong>
            <p>
                Cuando registres una nota o un recordatorio asociado,
                aparecerá aquí.
            </p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Descripción</th>
                        <th>Fecha de creación</th>
                        <th>Vencimiento</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($seguimientos as $s): ?>
                    <?php
                    $tipo = $s['tipo'] ?? '';
                    $estado = $s['estado'] ?? 'pendiente';

                    $fechaCreacion = $s['fecha_creacion'] ?? null;
                    $fechaVencimiento = $s['fecha_vencimiento'] ?? null;

                    $claseEstado = match ($estado) {
                        'completado' => 'adi-pill--ok',
                        'pendiente' => 'adi-pill--warn',
                        default => 'adi-pill--neutral',
                    };
                    ?>
                    <tr>
                        <td>
                            <span class="adi-tag">
                                <?= e(TIPOS_SEGUIMIENTO[$tipo] ?? $tipo) ?>
                            </span>
                        </td>
                        <td><?= e($s['descripcion'] ?? '') ?></td>
                        <td><?= e($formatearFecha($fechaCreacion)) ?></td>
                        <td><?= e($formatearFecha($fechaVencimiento)) ?></td>
                        <td>
                            <span class="adi-pill <?= e($claseEstado) ?>">
                                <?= e(ESTADOS_SEGUIMIENTO[$estado] ?? $estado) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php pie(); ?>
