
<?php
/**
 * Registrar o editar una venta de un turno o una feria (Issue #32).
 * Sin ?id= registra una nueva (?actividad= la precarga); con ?id= edita.
 *
 * La venta guarda sus productos embebidos {producto, cantidad, precio} y el
 * total. El total lo calcula el servidor a partir de los productos; el que
 * se ve en pantalla es solo una vista previa.
 */
require_once dirname(__DIR__) . '/includes/ventas.php';
requiereRol('admin');

use MongoDB\BSON\UTCDateTime;

$coleccion = $baseDatos->selectCollection('ventas');

$id      = idValido($_GET['id'] ?? $_POST['id'] ?? '');
$editar  = $id !== null;
$errores = [];

// Valores del formulario
$actividadId = texto($_POST['actividad'] ?? $_GET['actividad'] ?? '');
$contactoId  = texto($_POST['contacto'] ?? '');
$filas       = [['producto' => '', 'cantidad' => '1', 'precio' => '']];

if ($editar) {
    $venta = $coleccion->findOne(['_id' => $id]);
    if (!$venta) {
        http_response_code(404);
        exit('La venta no existe.');
    }
    $actual = buscarActividad($baseDatos, $venta['actividad_id']);
    if (!aceptaVentas($actual)) {
        header('Location: ../actividades/ver.php?id=' . $venta['actividad_id'] . '&error=cerrada');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $actividadId = (string) $venta['actividad_id'];
        $contactoId  = isset($venta['contacto_id']) ? (string) $venta['contacto_id'] : '';
        $filas = [];
        foreach ($venta['items'] as $item) {
            $filas[] = [
                'producto' => $item['producto'],
                'cantidad' => (string) (int) $item['cantidad'],
                'precio'   => (string) (float) (string) $item['precio'],
            ];
        }
    }
}

// Solo turnos y ferias programados: las rifas venden números, no productos
$actividades = $baseDatos->selectCollection('actividades')
    ->find(['tipo' => ['$in' => ['turno', 'feria']], 'estado' => 'programada'], ['sort' => ['fecha' => 1]])
    ->toArray();
$contactos = contactosActivos($baseDatos);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        rechazarCsrf();   // no vino de este formulario: no se guarda nada
    }
    [$items, $total, $errores, $filas] = leerItems($_POST);
    if (!$filas) {
        $filas = [['producto' => '', 'cantidad' => '1', 'precio' => '']];
    }

    $actividad = buscarActividad($baseDatos, $actividadId);
    if (!$actividad || $actividad['tipo'] === 'rifa') {
        array_unshift($errores, 'Seleccioná el turno o la feria de la venta.');
    } elseif (!aceptaVentas($actividad)) {
        array_unshift($errores, 'Esa actividad está finalizada y ya no acepta ventas.');
    }

    // El contacto es opcional: la mayoría de las ventas de un turno son anónimas
    $idContacto = null;
    if ($contactoId !== '') {
        $idContacto = idValido($contactoId);
        if (!$idContacto
            || !$baseDatos->selectCollection('contactos')->countDocuments(['_id' => $idContacto], ['limit' => 1])) {
            $errores[] = 'El contacto elegido no existe.';
        }
    }

    if (!$errores) {
        $datos = [
            'actividad_id' => $actividad['_id'],
            'items'        => $items,
            'total'        => decimal((string) $total),
        ];
        try {
            if ($editar) {
                $cambio = ['$set' => $datos];   // la fecha original de la venta se conserva
                if ($idContacto) {
                    $cambio['$set']['contacto_id'] = $idContacto;
                } else {
                    $cambio['$unset'] = ['contacto_id' => ''];
                }
                $coleccion->updateOne(['_id' => $id], $cambio);
                $ok = 'venta-editada';
            } else {
                $datos['fecha'] = new UTCDateTime();
                if ($idContacto) {
                    $datos['contacto_id'] = $idContacto;
                }
                $coleccion->insertOne($datos);
                $ok = 'venta';
            }

            // Invalidar la caché después de guardar correctamente.
            require_once dirname(__DIR__) . '/includes/redis.php';
            invalidarCacheVentas();

            header('Location: ../actividades/ver.php?id=' . $actividad['_id'] . '&ok=' . $ok);
            exit;
        } catch (Throwable $e) {
            $errores[] = codigoError($e) === 121
                ? 'La base de datos rechazó la venta porque no cumple el esquema.'
                : 'No se pudo guardar la venta. Intentá de nuevo.';
        }
    }
}

$titulo = $editar ? 'Editar venta' : 'Registrar venta';
encabezado($titulo, 'ventas');

$volver = idValido($actividadId) ? '../actividades/ver.php?id=' . $actividadId : 'index.php';
?>
<div class="adi-form-narrow adi-form-wide">
    <a href="<?= e($volver) ?>" class="adi-sub text-decoration-none d-inline-block mb-3">← Volver</a>
    <?php tituloPagina(
        $titulo,
        'Anotá cada producto con su cantidad y precio. El total lo calcula el sistema.',
        null,
        'ventas'
    ); ?>

    <?php listaErrores($errores); ?>

    <?php if (!$actividades): ?>
        <div class="adi-card adi-empty">
            <strong>No hay turnos ni ferias programados</strong>
            <a href="../actividades/form.php">Registrá una actividad</a> para poder vender.
        </div>
    <?php else: ?>
    <form method="POST" class="adi-card adi-card-body" id="form-venta">
        <?= campoCsrf() ?>
        <?php if ($editar): ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-sm-7">
                <label for="actividad" class="form-label">Actividad</label>
                <select class="form-select" id="actividad" name="actividad" required>
                    <option value="">Elegí el turno o la feria</option>
                    <?php foreach ($actividades as $a): ?>
                        <option value="<?= e($a['_id']) ?>" <?= $actividadId === (string) $a['_id'] ? 'selected' : '' ?>>
                            <?= e($a['nombre']) ?> · <?= diaLargo($a['fecha']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-5">
                <label for="contacto" class="form-label">Contacto <span class="adi-sub">(opcional)</span></label>
                <select class="form-select" id="contacto" name="contacto">
                    <option value="">Venta sin contacto</option>
                    <?php foreach ($contactos as $c): ?>
                        <option value="<?= e($c['_id']) ?>" <?= $contactoId === (string) $c['_id'] ? 'selected' : '' ?>>
                            <?= e($c['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h2 class="adi-section-title">Productos</h2>
        <div class="adi-items">
            <div class="adi-items-head" aria-hidden="true">
                <span>Producto</span><span>Cantidad</span><span>Precio unitario</span><span class="adi-num">Subtotal</span><span></span>
            </div>
            <div id="filas">
                <?php foreach ($filas as $f): ?>
                    <div class="adi-item">
                        <input type="text" class="form-control" name="producto[]" list="productos" required
                               maxlength="60" placeholder="Tamal" aria-label="Producto" value="<?= e($f['producto']) ?>">
                        <input type="number" class="form-control" name="cantidad[]" min="1" max="999" step="1" required
                               aria-label="Cantidad" value="<?= e($f['cantidad']) ?>">
                        <div class="input-group">
                            <span class="input-group-text">₡</span>
                            <input type="number" class="form-control" name="precio[]" min="1" step="1" required
                                   aria-label="Precio unitario" value="<?= e($f['precio']) ?>">
                        </div>
                        <span class="adi-num adi-item-subtotal">—</span>
                        <button type="button" class="btn btn-sm adi-btn-quiet-danger adi-item-quitar" aria-label="Quitar producto">✕</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm adi-btn-soft mt-2" id="agregar">+ Agregar producto</button>
        </div>

        <datalist id="productos">
            <?php foreach (PRODUCTOS_SUGERIDOS as $nombre => $precioSugerido): ?>
                <option value="<?= e($nombre) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <div class="adi-total">
            <span>Total de la venta</span>
            <strong id="total">—</strong>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn adi-btn-primary"><?= $editar ? 'Guardar cambios' : 'Registrar venta' ?></button>
            <a href="<?= e($volver) ?>" class="btn adi-btn-ghost">Cancelar</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
// Vista previa del total y filas dinámicas. El servidor vuelve a calcular todo.
(function () {
    const form = document.getElementById('form-venta');
    if (!form) return;

    const sugeridos = <?= json_encode(PRODUCTOS_SUGERIDOS, JSON_UNESCAPED_UNICODE) ?>;
    const filas = document.getElementById('filas');
    const total = document.getElementById('total');
    const maximo = <?= MAX_ITEMS ?>;
    // Mismo formato que dinero() en PHP: ₡1.500
    const colones = n => '₡' + String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    function recalcular() {
        let suma = 0;
        filas.querySelectorAll('.adi-item').forEach(function (fila) {
            const cantidad = parseInt(fila.querySelector('[name="cantidad[]"]').value, 10) || 0;
            const precio = parseFloat(fila.querySelector('[name="precio[]"]').value) || 0;
            const sub = cantidad * precio;
            suma += sub;
            fila.querySelector('.adi-item-subtotal').textContent = sub ? colones(sub) : '—';
        });
        total.textContent = suma ? colones(suma) : '—';
        const quitar = filas.querySelectorAll('.adi-item-quitar');
        quitar.forEach(b => b.disabled = quitar.length === 1);
        document.getElementById('agregar').disabled = quitar.length >= maximo;
    }

    filas.addEventListener('input', function (ev) {
        // Al elegir un producto sugerido, se propone su precio habitual
        if (ev.target.name === 'producto[]' && ev.target.value in sugeridos) {
            const precio = ev.target.closest('.adi-item').querySelector('[name="precio[]"]');
            if (!precio.value) precio.value = sugeridos[ev.target.value];
        }
        recalcular();
    });

    filas.addEventListener('click', function (ev) {
        const boton = ev.target.closest('.adi-item-quitar');
        if (boton && filas.children.length > 1) {
            boton.closest('.adi-item').remove();
            recalcular();
        }
    });

    document.getElementById('agregar').addEventListener('click', function () {
        const nueva = filas.firstElementChild.cloneNode(true);
        nueva.querySelectorAll('input').forEach(i => i.value = i.name === 'cantidad[]' ? '1' : '');
        filas.appendChild(nueva);
        nueva.querySelector('[name="producto[]"]').focus();
        recalcular();
    });

    recalcular();
})();
</script>
<?php pie();
