<?php
/**
 * Registrar un contacto nuevo.
 */
require_once dirname(__DIR__) . '/includes/contactos.php';

$errores = [];
$nombre = '';
$tipo = '';
$telefono = '';
$correo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo = trim($_POST['correo'] ?? '');

    // Validaciones básicas
    if (mb_strlen($nombre) < 2) {
        $errores[] = 'El nombre debe tener al menos 2 caracteres.';
    }

    if (!in_array($tipo, ['vecino', 'comercio', 'institucion'], true)) {
        $errores[] = 'Seleccioná un tipo de contacto válido.';
    }

    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingresá un correo electrónico válido.';
    }

    if (!$errores) {
        try {
            $coleccion = $baseDatos->selectCollection('contactos');

            $coleccion->insertOne([
                'nombre' => $nombre,
                'tipo' => $tipo,
                'telefono' => $telefono,
                'correo' => $correo,
                'activo' => true,
                'fecha_registro' => new MongoDB\BSON\UTCDateTime()
            ]);

            header('Location: index.php?creado=1');
            exit;
        } catch (Throwable $e) {
            $errores[] = 'No se pudo registrar el contacto. Revisá los datos e intentá nuevamente.';
        }
    }
}

encabezado('Nuevo contacto', 'contactos');
?>
<div class="adi-form-narrow">
    <a href="index.php" class="adi-sub text-decoration-none d-inline-block mb-3">← Contactos</a>
    <?php tituloPagina('Nuevo contacto', 'Completá la información del contacto.', null, 'contactos'); ?>

    <?php listaErrores($errores); ?>

    <?php require __DIR__ . '/_campos.php'; ?>
</div>
<?php pie();
