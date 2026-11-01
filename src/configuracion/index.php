<?php
/**
 * Nombre de la asociación (parámetro global de la instalación).
 *
 * Un solo documento en la colección "configuracion": {_id: "asociacion", nombre}.
 * Se muestra debajo del logo en todas las pantallas. Solo administración lo edita.
 */
require_once dirname(__DIR__) . '/includes/layout.php';
requiereRol('admin');
exigirCsrf();   // el POST debe traer el token de su formulario
require_once dirname(__DIR__) . '/includes/conexion.php';

use MongoDB\BSON\UTCDateTime;

$coleccion = $baseDatos->selectCollection('configuracion');
$errores   = [];

$actual = $coleccion->findOne(['_id' => 'asociacion']);
$nombre = (string) ($actual['nombre'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')));

    // Las mismas reglas las vuelve a exigir el JSON Schema de scripts/crear_colecciones.js
    if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 120) {
        $errores[] = 'El nombre debe tener entre 3 y 120 caracteres.';
    }

    if (!$errores) {
        try {
            $coleccion->updateOne(
                ['_id' => 'asociacion'],
                ['$set' => [
                    'nombre'          => $nombre,
                    'actualizado'     => new UTCDateTime(),
                    'actualizado_por' => usuarioActual()['correo'],
                ]],
                ['upsert' => true]
            );
            guardarNombreAsociacionEnSesion($nombre);   // se ve de inmediato en esta sesión
            header('Location: index.php?ok=guardado');
            exit;
        } catch (Throwable $e) {
            error_log('ADIministra: no se pudo guardar el nombre de la asociación: ' . $e->getMessage());
            $errores[] = 'No se pudo guardar el nombre. Intentá de nuevo.';
        }
    }
}

encabezado('Asociación');
?>
<div class="adi-form-narrow">
    <div class="adi-page-head">
        <div>
            <div class="adi-eyebrow">Configuración</div>
            <h1 class="adi-title">Nombre de la asociación</h1>
            <p class="adi-lead">Aparece debajo del logo en todas las pantallas, también en las públicas.</p>
        </div>
    </div>

    <?php avisoExito(['guardado' => 'Nombre guardado. Las demás sesiones lo ven en unos minutos.']); ?>
    <?php listaErrores($errores); ?>

    <form method="POST" class="adi-card adi-card-body">
        <?= campoCsrf() ?>
        <div class="mb-3">
            <label for="nombre" class="form-label">Nombre completo</label>
            <input type="text" class="form-control" id="nombre" name="nombre" required minlength="3" maxlength="120"
                   value="<?= e($nombre) ?>" placeholder="Asociación de Desarrollo Integral de Barrio El Carmen de Goicoechea">
            <div class="form-text">
                Mientras no se configure se muestra "<?= e(ASOCIACION_POR_DEFECTO) ?>".
                <?php if (isset($actual['actualizado'])): ?>
                    Último cambio: <?= e($actual['actualizado']->toDateTime()->setTimezone(new DateTimeZone('America/Costa_Rica'))->format('d/m/Y H:i')) ?>
                    por <?= e($actual['actualizado_por'] ?? '—') ?>.
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn adi-btn-primary">Guardar</button>
            <a href="<?= RAIZ ?>index.php" class="btn adi-btn-ghost">Volver</a>
        </div>
    </form>
</div>
<?php pie();
