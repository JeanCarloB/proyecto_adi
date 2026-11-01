<?php
/**
 * Usuarios del sistema: quién puede entrar y con qué rol. Solo administración.
 */
require_once dirname(__DIR__) . '/includes/usuarios.php';
requiereRol('admin');

$coleccion = coleccionUsuarios($baseDatos);

$porPagina = leerPorPagina(15);
$total     = $coleccion->countDocuments();
$paginas   = max(1, (int) ceil($total / $porPagina));
$pagina    = min(max((int) ($_GET['pagina'] ?? 1), 1), $paginas);

// Nunca se lee el hash de la contraseña para mostrar la lista
$usuarios = $coleccion->find([], [
    'projection' => ['contrasena' => 0],
    'sort'       => ['correo' => 1],
    'skip'       => ($pagina - 1) * $porPagina,
    'limit'      => $porPagina,
])->toArray();

encabezado('Usuarios', 'usuarios');

tituloPagina(
    'Usuarios',
    'Quién puede entrar al sistema y con qué rol.',
    ['texto' => 'Nuevo usuario', 'url' => 'crear.php'],
    'usuarios'
);

avisoExito([
    'creado'      => 'El usuario se registró. Ya puede iniciar sesión con su correo y contraseña.',
    'rol'         => 'El rol se cambió. Se aplica la próxima vez que la persona inicie sesión.',
    'activado'    => 'El usuario puede volver a iniciar sesión.',
    'desactivado' => 'El usuario quedó inactivo y ya no puede iniciar sesión.',
]);
if (($_GET['ok'] ?? '') === 'propio') {
    aviso('warn', 'No puede cambiar su propio rol ni desactivar su propia cuenta. Pídaselo a otra persona de administración.');
}
?>

<div class="adi-card">
    <?php if (!$usuarios): ?>
        <div class="adi-empty">
            <strong>Todavía no hay usuarios</strong>
            <a href="crear.php">Registre el primero</a>.
        </div>
    <?php else: ?>
        <div class="adi-table-meta">
            <span><?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'usuario' : 'usuarios' ?></span>
            <span>Ordenados por correo</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Registrado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $u):
                    $activo = ($u['activo'] ?? true) !== false;
                    $propio = esUsuarioActual($u['_id']);
                    $otroRol = $u['rol'] === 'admin' ? 'comunidad' : 'admin';
                ?>
                    <tr>
                        <td>
                            <strong><?= e($u['nombre'] ?? $u['correo']) ?></strong>
                            <?php if (isset($u['nombre'])): ?>
                                <div class="adi-sub"><?= e($u['correo']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e(ROLES[$u['rol']] ?? $u['rol']) ?></td>
                        <td>
                            <span class="adi-pill <?= $activo ? 'adi-pill--ok' : 'adi-pill--neutral' ?>">
                                <?= $activo ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <?= isset($u['creado']) ? e($u['creado']->toDateTime()->setTimezone(new DateTimeZone('America/Costa_Rica'))->format('d/m/Y')) : '—' ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($propio): ?>
                                <span class="adi-sub">Su cuenta</span>
                            <?php else: ?>
                                <form action="rol.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="Cambiar rol"
                                      data-confirmar="<?= e($u['correo'] . ' pasa a ' . ROLES[$otroRol] . '. Se aplica la próxima vez que inicie sesión.') ?>"
                                      data-confirmar-boton="Cambiar rol">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($u['_id']) ?>">
                                    <input type="hidden" name="rol" value="<?= e($otroRol) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-ghost">Hacer <?= e(mb_strtolower(ROLES[$otroRol])) ?></button>
                                </form>
                                <form action="estado.php" method="POST" class="d-inline"
                                      data-confirmar-titulo="<?= $activo ? 'Desactivar usuario' : 'Activar usuario' ?>"
                                      data-confirmar="<?= e($u['correo'] . ($activo
                                          ? ': ya no podrá iniciar sesión. Sus datos se conservan.'
                                          : ': podrá volver a iniciar sesión.')) ?>"
                                      data-confirmar-boton="<?= $activo ? 'Desactivar' : 'Activar' ?>"
                                      <?= $activo ? 'data-confirmar-peligro="1"' : '' ?>>
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="id" value="<?= e($u['_id']) ?>">
                                    <input type="hidden" name="activo" value="<?= $activo ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm <?= $activo ? 'adi-btn-quiet-danger' : 'adi-btn-soft' ?>">
                                        <?= $activo ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php paginador($pagina, $paginas, $total, $porPagina); ?>
    <?php endif; ?>
</div>
<?php pie();
