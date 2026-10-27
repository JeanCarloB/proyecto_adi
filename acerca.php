<?php
/**
 * Acerca de ADIministra: qué es, quién lo hizo y dónde está el código.
 * Página pública (no pide sesión). Se enlaza desde el pie de página de todas las pantallas.
 */
require_once __DIR__ . '/src/includes/layout.php';

$equipo = [
    ['Hazel Molina',        'Alquiler de espacios',                     'hazelmolina'],
    ['Kendall Ortega',      'Ventas en actividades',                    'kort97'],
    ['María Rodríguez',     'Contactos y relaciones',                   'vibeswithmajo'],
    ['Jean Carlo Bermúdez', 'Infraestructura, integración y seguridad', 'JeanCarloB'],
];

$tecnologias = ['MongoDB Atlas', 'PHP 8.3', 'Bootstrap 5', 'Composer', 'mongosh', 'ALTCHA (captcha open source)', 'Faker (datos sintéticos)'];

$documentos = [
    'README e instalación'      => 'blob/main/README.md',
    'Guía de instalación'       => 'blob/main/docs/instalacion.md',
    'Decisiones técnicas'       => 'blob/main/docs/decisiones.md',
    'Pruebas'                   => 'blob/main/docs/pruebas.md',
    'Mediciones con explain()'  => 'blob/main/docs/mediciones.md',
    'Cambios por versión'       => 'blob/main/CHANGELOG.md',
];

if (esAdmin()) {
    encabezado('Acerca de');
} else {
    encabezadoPublico('Acerca de');
}

?>
<div class="adi-page-head">
    <div>
        <div class="adi-eyebrow">Proyecto open source</div>
        <h1 class="adi-title">Acerca de ADIministra</h1>
        <p class="adi-lead">Sistema de gestión comunal para una Asociación de Desarrollo Integral (ADI): alquiler de espacios, ventas en actividades y contactos, en un solo lugar.</p>
    </div>
</div>

<div class="adi-acerca">
    <p>
        Las ADI llevan sus alquileres, ventas y rifas en cuadernos, mensajes de WhatsApp y hojas de Excel. De ahí salen
        dobles alquileres, apartados que nadie libera, ventas que no cuadran y un historial que se pierde cada vez que
        cambia la junta. ADIministra pone esas reglas en la base de datos: un mismo horario no se puede alquilar dos
        veces, un apartado sin pagar vence solo y un número de rifa no se vende dos veces.
    </p>
    <p>
        La junta directiva administra todo con su cuenta; la comunidad consulta el calendario de espacios sin iniciar
        sesión y, con su cuenta, pide una reserva que la junta aprueba o rechaza.
    </p>

    <h2>Equipo · Grupo 4</h2>
    <div class="adi-equipo">
        <?php foreach ($equipo as [$nombre, $rol, $github]): ?>
            <div class="adi-card">
                <strong><?= e($nombre) ?></strong>
                <span><?= e($rol) ?></span>
                <a href="https://github.com/<?= e($github) ?>" target="_blank" rel="noopener">@<?= e($github) ?></a>
            </div>
        <?php endforeach; ?>
    </div>

    <h2>Proyecto académico</h2>
    <p>
        Proyecto final del curso <strong>Tópicos Avanzados de Bases de Datos</strong>, Universidad Nacional (UNA),
        II ciclo 2026, con el profesor Manuel Espinoza Guerrero. Es open source con licencia MIT: cualquiera puede
        instalarlo, usarlo y continuarlo.
    </p>

    <h2>Tecnologías</h2>
    <ul class="adi-chips-tec">
        <?php foreach ($tecnologias as $t): ?><li><?= e($t) ?></li><?php endforeach; ?>
    </ul>

    <h2>Código y documentación</h2>
    <p>
        <a href="<?= REPO_URL ?>" target="_blank" rel="noopener" class="btn adi-btn-primary">Ver el repositorio en GitHub</a>
    </p>
    <ul>
        <?php foreach ($documentos as $texto => $ruta): ?>
            <li><a href="<?= REPO_URL . '/' . $ruta ?>" target="_blank" rel="noopener"><?= e($texto) ?></a></li>
        <?php endforeach; ?>
    </ul>
</div>

<?php pie();
