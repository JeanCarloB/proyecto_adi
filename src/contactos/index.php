<?php
/**
 * Listado de contactos: vecinos, comercios e instituciones.
 */
require_once dirname(__DIR__) . '/includes/contactos.php';

// Obtener los contactos de MongoDB
// Obtener los contactos junto con su historial de seguimientos.
$contactos = $baseDatos->selectCollection('contactos')
    ->aggregate([
        [
            '$lookup' => [
                'from' => 'seguimientos',
                'localField' => '_id',
                'foreignField' => 'contacto_id',
                'as' => 'historial',
            ],
        ],
        [
            '$sort' => ['nombre' => 1],
        ],
    ])
    ->toArray();

// Indicadores del encabezado (se calculan sobre la lista ya cargada)
$porTipo = array_fill_keys(array_keys(TIPOS_CONTACTO), 0);
foreach ($contactos as $contacto) {
    if (isset($porTipo[$contacto['tipo'] ?? ''])) {
        $porTipo[$contacto['tipo']]++;
    }
}

encabezado('Contactos', 'contactos');

tituloPagina(
    'Contactos',
    'Vecinos, comercios e instituciones con los que trabaja la ADI.',
    ['texto' => 'Nuevo contacto', 'url' => 'crear.php'],
    'contactos'
);

if (($_GET['creado'] ?? '') === '1') {
    aviso('ok', 'El contacto se registró correctamente.');
}
if (($_GET['editado'] ?? '') === '1') {
    aviso('ok', 'Los cambios se guardaron.');
}
if (($_GET['desactivado'] ?? '') === '1') {
    aviso('ok', 'El contacto quedó inactivo.');
} elseif (($_GET['desactivado'] ?? '') === '0') {
    aviso('warn', 'No se desactivó: el contacto no existe o ya estaba inactivo.');
}
?>

<section class="adi-stats" aria-label="Resumen de contactos">
    <div class="adi-stat">
        <div class="adi-stat-label">Contactos</div>
        <div class="adi-stat-value"><?= number_format(count($contactos), 0, ',', '.') ?></div>
    </div>
    <?php foreach (TIPOS_CONTACTO as $clave => $etiqueta): ?>
        <div class="adi-stat">
            <div class="adi-stat-label"><?= e($etiqueta === 'Institución' ? 'Instituciones' : $etiqueta . 's') ?></div>
            <div class="adi-stat-value"><?= number_format($porTipo[$clave], 0, ',', '.') ?></div>
        </div>
    <?php endforeach; ?>
</section>

<?php if (empty($contactos)): ?>
    <div class="adi-card adi-empty">
        <strong>Todavía no hay contactos registrados</strong>
        <a href="crear.php">Registrá el primero</a> para poder asociarlo a reservas y ventas.
    </div>
<?php else: ?>
    <div class="adi-card adi-filters adi-filters--search mb-4" role="search">
        <div>
            <label for="buscar" class="form-label">Buscar</label>
            <input type="search" class="form-control" id="buscar" placeholder="Nombre, teléfono o correo">
        </div>
        <div>
            <label for="filtro-tipo" class="form-label">Tipo</label>
            <select class="form-select" id="filtro-tipo">
                <option value="">Todos</option>
                <?php foreach (TIPOS_CONTACTO as $clave => $etiqueta): ?>
                    <option value="<?= e($clave) ?>"><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="filtro-estado" class="form-label">Estado</label>
            <select class="form-select" id="filtro-estado">
                <option value="">Todos</option>
                <option value="activo">Activos</option>
                <option value="inactivo">Inactivos</option>
            </select>
        </div>
    </div>

    <div class="adi-card">
        <div class="adi-table-meta">
            <span id="conteo"><?= count($contactos) ?> <?= count($contactos) === 1 ? 'contacto' : 'contactos' ?></span>
            <span>Ordenados por nombre</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover adi-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Teléfono</th>
                        <th>Correo</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="lista-contactos">
                <?php foreach ($contactos as $contacto):
                    $activo   = ($contacto['activo'] ?? true) === true;
                    $tipo     = $contacto['tipo'] ?? '';
                    $telefono = (string) ($contacto['telefono'] ?? '');
                    $correo   = (string) ($contacto['correo'] ?? '');
                    $busqueda = mb_strtolower(($contacto['nombre'] ?? '') . ' ' . $telefono . ' ' . $correo);
                ?>
                    <tr data-busqueda="<?= e($busqueda) ?>" data-tipo="<?= e($tipo) ?>"
                        data-estado="<?= $activo ? 'activo' : 'inactivo' ?>">
                        <td class="adi-contact-name"><?= e($contacto['nombre'] ?? '') ?></td>
                        <td><span class="adi-tag"><?= e(TIPOS_CONTACTO[$tipo] ?? $tipo) ?></span></td>
                        <td class="text-nowrap"><?= $telefono !== '' ? e($telefono) : '<span class="adi-sub">—</span>' ?></td>
                        <td>
                            <?php if ($correo !== ''): ?>
                                <a class="adi-link-quiet" href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a>
                            <?php else: ?>
                                <span class="adi-sub">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="adi-pill <?= $activo ? 'adi-pill--ok' : 'adi-pill--neutral' ?>">
                                <?= $activo ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="historial.php?id=<?= urlencode((string) $contacto['_id']) ?>"
   							   class="btn btn-sm adi-btn-ghost">Historial</a>
                            <a href="editar.php?id=<?= urlencode((string) $contacto['_id']) ?>"
                               class="btn btn-sm adi-btn-ghost">Editar</a>
                            <?php if ($activo): ?>
                                <form action="desactivar.php" method="POST" class="d-inline"
                                      onsubmit="return confirm('¿Deseás desactivar este contacto?');">
                                    <input type="hidden" name="id" value="<?= e((string) $contacto['_id']) ?>">
                                    <button type="submit" class="btn btn-sm adi-btn-quiet-danger">Desactivar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="sin-resultados" class="adi-empty" hidden>
                <strong>Ningún contacto coincide</strong>
                Probá con otra búsqueda o quitá los filtros.
            </div>
        </div>
    </div>

    <script>
    // Filtra la tabla en el navegador; no hace consultas nuevas a la base.
    (function () {
        const buscar = document.getElementById('buscar');
        const tipo   = document.getElementById('filtro-tipo');
        const estado = document.getElementById('filtro-estado');
        const filas  = document.querySelectorAll('#lista-contactos tr');
        const conteo = document.getElementById('conteo');
        const vacio  = document.getElementById('sin-resultados');

        function filtrar() {
            const texto = buscar.value.trim().toLowerCase();
            let visibles = 0;
            filas.forEach(function (fila) {
                const ok = (!texto || fila.dataset.busqueda.includes(texto))
                    && (!tipo.value || fila.dataset.tipo === tipo.value)
                    && (!estado.value || fila.dataset.estado === estado.value);
                fila.hidden = !ok;
                if (ok) visibles++;
            });
            conteo.textContent = visibles + (visibles === 1 ? ' contacto' : ' contactos');
            vacio.hidden = visibles !== 0;
        }

        [buscar, tipo, estado].forEach(function (control) {
            control.addEventListener('input', filtrar);
        });
    })();
    </script>
<?php endif; ?>
<?php pie();
