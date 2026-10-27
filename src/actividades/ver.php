<?php
/**
 * Detalle de una actividad (Issues #32, #33 y #34).
 *
 * Turnos y ferias: total recaudado y productos más vendidos con aggregation
 * pipeline, y las ventas registradas.
 * Rifas: cuadrícula de los 100 números con los vendidos marcados y el
 * formulario para vender uno. El índice único {rifa_id, numero} garantiza
 * que un número no se venda dos veces.
 *
 * Vista por rol (Issue #80): administración ve todo y tiene las acciones.
 * La comunidad ve los totales, los productos más vendidos y qué números de
 * la rifa quedan libres, pero no quién compró ni el detalle de cada venta.
 * Desde el Issue #114 la comunidad también aparta números de la rifa, y la
 * junta confirma el pago o los libera (la sección de la rifa está en _rifa.php).
 */
require_once dirname(__DIR__) . '/includes/ventas.php';

const LIMITE_VENTAS = 100;

$actividad = buscarActividad($baseDatos, $_GET['id'] ?? '');
if (!$actividad) {
    http_response_code(404);
    exit('La actividad no existe.');
}

$id      = $actividad['_id'];
$esRifa  = $actividad['tipo'] === 'rifa';
$abierta = aceptaVentas($actividad);
$admin   = esAdmin();

// --- Totales con aggregation pipeline ---------------------------------------

$resumen = totalesPorActividad($baseDatos, [$id])[(string) $id] ?? ['n' => 0, 'total' => 0.0];

if ($esRifa) {
    $coleccionRifas = $baseDatos->selectCollection('rifas');
    $mios = [];   // números de la persona con sesión (comunidad): numero → documento
    if ($admin) {
        // Números vendidos y apartados con su comprador: como mucho 100 documentos, se traen todos.
        $numeros = $coleccionRifas->find(['rifa_id' => $id], ['sort' => ['numero' => 1]])->toArray();
        $contactos  = nombresContactos($baseDatos, $numeros);
        $paraVender = $abierta ? contactosActivos($baseDatos) : [];
    } else {
        // Comunidad: solo qué números están ocupados. Con la proyección {_id: 0, numero: 1}
        // es una consulta CUBIERTA por el índice único {rifa_id, numero}: MongoDB responde
        // desde el índice sin abrir los documentos, así que no lee quién compró cada número.
        $numeros = $coleccionRifas
            ->find(['rifa_id' => $id], ['projection' => ['_id' => 0, 'numero' => 1], 'sort' => ['numero' => 1]])
            ->toArray();
        $contactos  = [];
        $paraVender = [];
        // Los suyos sí con detalle (apartado o pagado y hasta cuándo), filtrados por su usuario_id
        foreach ($coleccionRifas->find(
            ['rifa_id' => $id, 'usuario_id' => miUsuarioId()],
            ['projection' => ['numero' => 1, 'estado' => 1, 'vence' => 1]]
        ) as $m) {
            $mios[(int) $m['numero']] = $m;
        }
    }
    $vendidos = [];
    foreach ($numeros as $n) {
        $vendidos[(int) $n['numero']] = $n;
    }
    $libres = array_values(array_diff(range(0, NUMEROS_RIFA - 1), array_keys($vendidos)));
    $precio = $actividad['precio_numero'] ?? null;
    $apartados = $admin
        ? count(array_filter($numeros, fn ($n) => ($n['estado'] ?? '') === 'apartado'))
        : count($vendidos) - $resumen['n'];   // ocupados que todavía no se pagaron
    $misApartados = count(array_filter($mios, fn ($m) => ($m['estado'] ?? '') === 'apartado'));
} else {
    // Productos más vendidos: se "desenrolla" el arreglo de ítems y se agrupa por producto.
    $productos = $baseDatos->selectCollection('ventas')->aggregate([
        ['$match'  => ['actividad_id' => $id]],
        ['$unwind' => '$items'],
        ['$group'  => [
            '_id'      => '$items.producto',
            'unidades' => ['$sum' => '$items.cantidad'],
            'monto'    => ['$sum' => ['$multiply' => ['$items.cantidad', '$items.precio']]],
        ]],
        ['$sort'  => ['monto' => -1]],
        ['$limit' => 10],
    ])->toArray();

    // Últimas ventas, solo para administración (llevan el nombre de quien compró).
    // Usa el índice {actividad_id, fecha}: filtra y ordena sin ordenar en memoria.
    $ventas = $contactos = [];
    if ($admin) {
        $ventas = $baseDatos->selectCollection('ventas')
            ->find(['actividad_id' => $id], ['sort' => ['fecha' => -1], 'limit' => LIMITE_VENTAS])
            ->toArray();
        $contactos = nombresContactos($baseDatos, $ventas);
    }

    // Encargos de la comunidad (Issue #115). La junta ve los pendientes, del más viejo al
    // más nuevo, con el índice {actividad_id, estado, creado}; la persona ve solo los suyos.
    $menu = $actividad['menu'] ?? [];
    $encargosPendientes = $misEncargos = [];
    $coleccionEncargos = $baseDatos->selectCollection('encargos');
    if ($admin) {
        $encargosPendientes = $coleccionEncargos
            ->find(['actividad_id' => $id, 'estado' => 'pendiente'], ['sort' => ['creado' => 1], 'limit' => 100])
            ->toArray();
    } else {
        $misEncargos = $coleccionEncargos
            ->find(['actividad_id' => $id, 'usuario_id' => miUsuarioId()], ['sort' => ['creado' => -1], 'limit' => 10])
            ->toArray();
    }
}

encabezado($actividad['nombre'], 'actividades');
?>
<a href="index.php" class="adi-sub text-decoration-none d-inline-block mb-3">← Actividades</a>

<div class="adi-page-head">
    <div>
        <div class="adi-eyebrow">
            <span class="adi-dot adi-dot--ventas"></span><?= e(MODULOS['ventas']) ?>
        </div>
        <h1 class="adi-title"><?= e($actividad['nombre']) ?></h1>
        <p class="adi-lead d-flex flex-wrap align-items-center gap-2">
            <span class="adi-tag"><?= e(TIPOS_ACTIVIDAD[$actividad['tipo']] ?? $actividad['tipo']) ?></span>
            <span><?= e(ucfirst(diaSemana($actividad['fecha']))) ?> <?= diaLargo($actividad['fecha']) ?></span>
            <?= pillActividad($actividad['estado']) ?>
        </p>
    </div>
    <?php if ($admin): ?>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        <?php if ($abierta && !$esRifa): ?>
            <a href="../ventas/form.php?actividad=<?= e($id) ?>" class="btn adi-btn-primary text-nowrap">Registrar venta</a>
        <?php endif; ?>
        <a href="form.php?id=<?= e($id) ?>" class="btn adi-btn-ghost">Editar</a>
        <form action="estado.php" method="POST"
              onsubmit="return confirm('<?= $abierta
                  ? '¿Finalizar la actividad? Ya no se podrán registrar ni cambiar ventas.'
                  : '¿Reabrir la actividad? Volverá a aceptar ventas.' ?>');">
            <?= campoCsrf() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="estado" value="<?= $abierta ? 'finalizada' : 'programada' ?>">
            <button type="submit" class="btn <?= $abierta ? 'adi-btn-quiet-danger' : 'adi-btn-soft' ?>">
                <?= $abierta ? 'Finalizar' : 'Reabrir' ?>
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php
avisoExito([
    'creada'         => 'La actividad se registró correctamente.',
    'editada'        => 'Los cambios se guardaron.',
    'finalizada'     => 'La actividad se finalizó. Su total recaudado quedó cerrado.',
    'reabierta'      => 'La actividad volvió a quedar programada y acepta ventas.',
    'venta'          => 'La venta se registró.',
    'venta-editada'  => 'La venta se actualizó.',
    'venta-anulada'  => 'La venta se anuló y ya no suma al total.',
    'numero'         => 'Número ' . numeroRifa(texto($_GET['numero'] ?? '0')) . ' vendido.',
    'numero-anulado' => 'El número quedó libre otra vez.',
    'apartado'       => 'Apartaste el número ' . numeroRifa(texto($_GET['numero'] ?? '0'))
        . '. Pagáselo a la junta en las próximas ' . HORAS_APARTADO_RIFA . ' horas; si no, se libera solo.',
    'pagado'         => 'Pago confirmado: el número ' . numeroRifa(texto($_GET['numero'] ?? '0')) . ' quedó vendido.',
    'encargo'        => 'Tu encargo quedó registrado. La junta lo prepara y te lo entrega en la actividad; lo pagás al recibirlo.',
    'encargo-entregado' => 'Encargo entregado: quedó registrado como venta.',
    'encargo-cancelado' => 'El encargo se canceló.',
]);

$error = texto($_GET['error'] ?? '');
$mensajesError = [
    'repetido' => 'El número ' . numeroRifa(texto($_GET['numero'] ?? '0'))
        . ' ya está vendido o apartado. La base de datos no permite tenerlo dos veces: elegí otro.',
    'ocupado'  => 'El número ' . numeroRifa(texto($_GET['numero'] ?? '0'))
        . ' ya no está disponible: alguien lo apartó o lo compró hace un momento. Elegí otro.',
    'limite'   => 'Ya tenés ' . MAX_APARTADOS_RIFA . ' números apartados sin pagar en esta rifa. Pagalos o liberá alguno en Mis compras.',
    'vencido'  => 'Ese apartado ya no existe: venció o alguien lo liberó.',
    'sin-encargos'     => 'Esta actividad no recibe encargos.',
    'vacio'            => 'Elegí al menos un producto para encargar.',
    'cantidad'         => 'Cada producto se puede encargar hasta ' . MAX_UNIDADES_ENCARGO . ' unidades.',
    'limite-encargos'  => 'Ya tenés ' . MAX_ENCARGOS . ' encargos pendientes en esta actividad. Esperá a que la junta los entregue o retirá alguno en Mis compras.',
    'encargo-resuelto' => 'Ese encargo ya no está pendiente: alguien lo entregó o lo canceló.',
    'cerrada'  => 'La actividad está finalizada: no acepta ventas ni cambios. Reabrila si necesitás corregir algo.',
    'datos'    => 'Revisá el número y el contacto: alguno no es válido.',
    'precio'   => 'Esta rifa no tiene precio por número. Editá la actividad para indicarlo.',
    'esquema'  => 'La base de datos rechazó la venta porque no cumple el esquema.',
    'general'  => 'No se pudo completar la operación. Intentá de nuevo.',
];
if (isset($mensajesError[$error])) {
    aviso(in_array($error, ['repetido', 'ocupado', 'cerrada', 'limite', 'vencido', 'vacio', 'cantidad', 'limite-encargos', 'encargo-resuelto', 'sin-encargos'], true) ? 'warn' : 'danger', $mensajesError[$error]);
}
?>

<section class="adi-stats" aria-label="Resumen de la actividad">
    <div class="adi-stat">
        <div class="adi-stat-label">Total recaudado</div>
        <div class="adi-stat-value"><?= dinero($resumen['total']) ?></div>
    </div>
    <?php if ($esRifa): ?>
        <div class="adi-stat">
            <div class="adi-stat-label">Números vendidos</div>
            <div class="adi-stat-value"><?= $resumen['n'] ?> <span class="adi-sub">de <?= NUMEROS_RIFA ?></span></div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Disponibles</div>
            <div class="adi-stat-value"><?= count($libres) ?>
                <?php if ($apartados > 0): ?><span class="adi-sub">· <?= $apartados ?> apartados</span><?php endif; ?>
            </div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Precio por número</div>
            <div class="adi-stat-value"><?= dinero($precio) ?></div>
        </div>
    <?php else: ?>
        <div class="adi-stat">
            <div class="adi-stat-label">Ventas</div>
            <div class="adi-stat-value"><?= number_format($resumen['n'], 0, ',', '.') ?></div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Venta promedio</div>
            <div class="adi-stat-value"><?= $resumen['n'] ? dinero($resumen['total'] / $resumen['n']) : '—' ?></div>
        </div>
        <div class="adi-stat">
            <div class="adi-stat-label">Producto más vendido</div>
            <div class="adi-stat-value adi-stat-value--text"><?= $productos ? e($productos[0]['_id']) : '—' ?></div>
        </div>
    <?php endif; ?>
</section>

<?php if ($esRifa): ?>
    <?php require __DIR__ . '/_rifa.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/_turno.php'; ?>
<?php endif; ?>
<?php pie();
