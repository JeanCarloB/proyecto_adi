<?php
/**
 * Portada de ADIministra: resumen de cada módulo y acceso a sus pantallas.
 * La verificación del entorno (PHP, extensión, Composer, .env, Atlas) está en diagnostico.php.
 */
require_once __DIR__ . '/src/includes/layout.php';
requiereLogin();

$resumen = null;
$error = '';
try {
    require_once __DIR__ . '/src/includes/conexion.php';
    $hoy = new MongoDB\BSON\UTCDateTime((new DateTimeImmutable('today'))->getTimestamp() * 1000);
    $activo = ['activo' => ['$ne' => false]];
    $resumen = [
        'espacios'     => $baseDatos->selectCollection('espacios')->countDocuments($activo),
        'apartadas'    => $baseDatos->selectCollection('reservas')->countDocuments(['estado' => 'apartado']),
        'proximas'     => $baseDatos->selectCollection('reservas')->countDocuments(['fecha' => ['$gte' => $hoy]]),
        'actividades'  => $baseDatos->selectCollection('actividades')->countDocuments(['fecha' => ['$gte' => $hoy]]),
        'ventas'       => $baseDatos->selectCollection('ventas')->estimatedDocumentCount(),
        'contactos'    => $baseDatos->selectCollection('contactos')->countDocuments($activo),
        'pendientes'   => $baseDatos->selectCollection('seguimientos')->countDocuments(['estado' => 'pendiente']),
    ];
} catch (Throwable $e) {
    $error = 'No se pudo conectar con la base de datos. Revise la verificación del entorno.';
}

$numero = fn (int $n): string => number_format($n, 0, ',', '.');

$modulos = [
    [
        'modulo' => 'alquileres',
        'texto'  => 'Salón y cancha: disponibilidad, apartados que vencen solos y alquileres confirmados.',
        'datos'  => $resumen ? [
            'Espacios activos'  => $resumen['espacios'],
            'Reservas próximas' => $resumen['proximas'],
            'Apartadas'         => $resumen['apartadas'],
        ] : [],
        'enlaces' => ['Reservas' => 'src/reservas/index.php', 'Espacios' => 'src/espacios/index.php'],
    ],
    [
        'modulo' => 'ventas',
        'texto'  => 'Turnos, ferias y rifas: ventas por actividad, números de rifa y total recaudado.',
        'datos'  => $resumen ? [
            'Actividades próximas' => $resumen['actividades'],
            'Ventas registradas'   => $resumen['ventas'],
        ] : [],
        'enlaces' => ['Actividades' => 'src/actividades/index.php', 'Ventas' => 'src/ventas/index.php'],
    ],
    [
        'modulo' => 'contactos',
        'texto'  => 'Vecinos, comercios e instituciones, con su historial y recordatorios.',
        'datos'  => $resumen ? [
            'Contactos activos'        => $resumen['contactos'],
            'Seguimientos pendientes'  => $resumen['pendientes'],
        ] : [],
        'enlaces' => ['Contactos' => 'src/contactos/index.php', 'Seguimientos' => 'src/seguimientos/index.php'],
    ],
];

encabezado('Inicio');
?>

<div class="adi-page-head">
    <div>
        <h1 class="adi-title">ADIministra</h1>
        <p class="adi-lead">Sistema de gestión comunal: alquileres, ventas en actividades y contactos en un solo lugar.</p>
    </div>
</div>

<?php if ($error): aviso('danger', $error); endif; ?>

<section class="adi-stats adi-stats--3" aria-label="Módulos">
    <?php foreach ($modulos as $m): ?>
        <div class="adi-stat">
            <div class="adi-eyebrow">
                <span class="adi-dot adi-dot--<?= e($m['modulo']) ?>"></span><?= e(MODULOS[$m['modulo']]) ?>
            </div>
            <p class="adi-lead"><?= e($m['texto']) ?></p>
            <?php foreach ($m['datos'] as $etiqueta => $valor): ?>
                <div class="d-flex justify-content-between">
                    <span class="adi-stat-label"><?= e($etiqueta) ?></span>
                    <strong><?= $numero($valor) ?></strong>
                </div>
            <?php endforeach; ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php foreach ($m['enlaces'] as $texto => $ruta): ?>
                    <a href="<?= e($ruta) ?>" class="btn adi-btn-soft"><?= e($texto) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<p class="adi-sub"><a class="adi-link-quiet" href="diagnostico.php">Verificación del entorno</a></p>

<?php pie(); ?>
