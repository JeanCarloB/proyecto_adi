<?php
/**
 * Listado de espacios alquilables (Issue #11).
 */
require_once dirname(__DIR__) . '/includes/alquileres.php';

$espacios = $baseDatos->selectCollection('espacios')
    ->find([], ['sort' => ['activo' => -1, 'nombre' => 1]])
    ->toArray();

encabezado('Espacios', 'espacios');

tituloPagina(
    'Espacios',
    'Lo que la ADI alquila y cuánto cobra por cada franja del día.',
    ['texto' => 'Nuevo espacio', 'url' => 'form.php']
);

avisoExito([
    'creado'      => 'El espacio se registró correctamente.',
    'editado'     => 'Los cambios se guardaron.',
    'activado'    => 'El espacio vuelve a estar disponible para reservas.',
    'desactivado' => 'El espacio quedó inactivo y ya no se ofrece al reservar.',
]);
?>

<?php if (!$espacios): ?>
    <div class="adi-card adi-empty">
        <strong>Todavía no hay espacios</strong>
        Registrá el salón comunal o la cancha para empezar a recibir reservas.
    </div>
<?php else: ?>
    <div class="adi-grid">
        <?php foreach ($espacios as $espacio):
            // Las tarifas vienen embebidas como [{franja, monto}, ...]
            $tarifas = [];
            foreach ($espacio['tarifas'] ?? [] as $t) {
                $tarifas[$t['franja']] = $t['monto'];
            }
            $activo = ($espacio['activo'] ?? true) === true;
        ?>
            <article class="adi-space <?= $activo ? '' : 'is-off' ?>">
                <div class="adi-space-top">
                    <div>
                        <h2 class="adi-space-name"><?= e($espacio['nombre']) ?></h2>
                        <span class="adi-tag"><?= e(TIPOS_ESPACIO[$espacio['tipo']] ?? $espacio['tipo']) ?></span>
                    </div>
                    <span class="adi-pill <?= $activo ? 'adi-pill--ok' : 'adi-pill--neutral' ?>">
                        <?= $activo ? 'Disponible' : 'Inactivo' ?>
                    </span>
                </div>

                <ul class="adi-rates">
                    <?php foreach (FRANJAS as $clave => $etiqueta):
                        $tiene = isset($tarifas[$clave]);
                    ?>
                        <li class="<?= $tiene ? '' : 'is-empty' ?>">
                            <span><?= e($etiqueta) ?></span>
                            <strong><?= $tiene ? dinero($tarifas[$clave]) : 'No se ofrece' ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="adi-space-actions">
                    <a href="form.php?id=<?= e($espacio['_id']) ?>" class="btn btn-sm adi-btn-ghost">Editar</a>
                    <?php if ($activo): ?>
                        <a href="../reservas/crear.php?espacio=<?= e($espacio['_id']) ?>"
                           class="btn btn-sm adi-btn-soft">Reservar</a>
                    <?php endif; ?>
                    <form action="estado.php" method="POST" class="ms-auto"
                          onsubmit="return confirm('<?= $activo
                              ? '¿Desactivar este espacio? Ya no se podrá reservar.'
                              : '¿Volver a activar este espacio?' ?>');">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="id" value="<?= e($espacio['_id']) ?>">
                        <input type="hidden" name="activo" value="<?= $activo ? '0' : '1' ?>">
                        <button type="submit" class="btn btn-sm <?= $activo ? 'adi-btn-quiet-danger' : 'adi-btn-soft' ?>">
                            <?= $activo ? 'Desactivar' : 'Activar' ?>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php pie();
