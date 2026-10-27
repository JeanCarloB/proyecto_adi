<?php
/**
 * ADIministra · Utilidades compartidas del módulo de ventas en actividades.
 *
 * Carga la conexión ($baseDatos), el diseño compartido (layout.php) y las
 * funciones generales que ya define alquileres.php (dinero(), texto(),
 * decimal(), idValido(), diaUtc(), hoyLocal(), codigoError(), diaLargo()...),
 * para no repetirlas. Aquí solo vive lo propio de actividades, ventas y rifas.
 *
 * Control de acceso del módulo (Seguimiento 3, Issue #80):
 *   - Cualquier usuario con sesión entra a las páginas de consulta.
 *   - Administración ve y hace todo. La comunidad ve las actividades, sus
 *     totales y qué números de rifa quedan libres, pero no los nombres de
 *     quienes compraron ni el listado de ventas individuales.
 *   - Las páginas que cambian datos agregan requiereRol('admin'): el permiso
 *     se valida en el servidor, no solo escondiendo botones.
 */

// alquileres.php manda a la comunidad a su calendario; este módulo tiene su propio control.
define('ACCESO_PROPIO', true);

require_once __DIR__ . '/alquileres.php';   // conexión, layout y funciones generales

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

requiereLogin();

/** Deben coincidir con el enum del JSON Schema (scripts/crear_colecciones_ventas.js). */
const TIPOS_ACTIVIDAD   = ['turno' => 'Turno', 'feria' => 'Feria', 'rifa' => 'Rifa'];
const ESTADOS_ACTIVIDAD = ['programada' => 'Programada', 'finalizada' => 'Finalizada'];

/** Cada rifa tiene los números del 00 al 99. */
const NUMEROS_RIFA = 100;

/** Horas que un número apartado por la comunidad espera el pago antes de que el TTL lo libere (Issue #114). */
const HORAS_APARTADO_RIFA = 48;

/** Cuántos números puede tener apartados (sin pagar) una persona en una misma rifa. */
const MAX_APARTADOS_RIFA = 5;

/** Máximo de productos distintos en una sola venta. */
const MAX_ITEMS = 15;

/**
 * Productos que se ofrecen como sugerencia al registrar una venta, con su
 * precio habitual. Son los mismos de scripts/cargar_datos.php. El usuario
 * puede escribir cualquier otro producto y cambiar el precio.
 */
const PRODUCTOS_SUGERIDOS = [
    'Tamal' => 1500, 'Empanada' => 800, 'Café' => 600, 'Gallo de picadillo' => 1000,
    'Arroz con leche' => 1000, 'Refresco natural' => 700, 'Chicharrón' => 2500,
    'Arroz con pollo' => 3000, 'Copo' => 1000, 'Elote' => 800, 'Queque' => 1200,
];

// --- Permisos ---------------------------------------------------------------

/**
 * Para las páginas que solo cambian datos (finalizar, vender, anular).
 * Exige rol admin, método POST y el token CSRF del formulario (Issue #81).
 * Así, una página de otro sitio no puede hacer que el navegador de alguien
 * de la junta venda o anule algo sin que lo sepa.
 */
function exigirAccionAdmin(): void
{
    requiereRol('admin');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Método no permitido.');
    }
    if (!csrfValido()) {
        rechazarCsrf();
    }
}

/** Respuesta cuando el token CSRF falta o no coincide: no se cambia nada. */
function rechazarCsrf(): never
{
    http_response_code(400);
    encabezado('Formulario vencido');
    ?>
    <div class="adi-form-narrow">
        <?php aviso('warn', 'El formulario venció o no vino de esta página, así que no se hizo ningún cambio. Volvé a la página, recargala e intentá de nuevo.'); ?>
        <a href="<?= RAIZ ?>src/actividades/index.php" class="btn adi-btn-soft">Ir a Actividades</a>
    </div>
    <?php
    pie();
    exit;
}

// --- Vista ------------------------------------------------------------------

/** 7 → "07". Así se leen los números en una rifa. */
function numeroRifa(int|float|string $numero): string
{
    return sprintf('%02d', (int) $numero);
}

/** "2026-10-20" en el formato del input type="date" a partir de una fecha guardada a medianoche UTC. */
function fechaInput(UTCDateTime $fecha): string
{
    return $fecha->toDateTime()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
}

/** Resumen corto de los productos de una venta: "2 × Tamal, 1 × Café". */
function resumenItems(iterable $items): string
{
    $partes = [];
    foreach ($items as $item) {
        $partes[] = (int) $item['cantidad'] . ' × ' . $item['producto'];
    }
    return implode(', ', $partes);
}

function pillActividad(string $estado): string
{
    $clase = $estado === 'programada' ? 'adi-pill--ok' : 'adi-pill--neutral';
    return '<span class="adi-pill ' . $clase . '">' . e(ESTADOS_ACTIVIDAD[$estado] ?? $estado) . '</span>';
}

// --- Datos ------------------------------------------------------------------

/** Busca una actividad por id (texto o ObjectId). Devuelve null si no existe. */
function buscarActividad(MongoDB\Database $baseDatos, mixed $id): ?object
{
    $id = $id instanceof ObjectId ? $id : idValido($id);
    if ($id === null) {
        return null;
    }
    return $baseDatos->selectCollection('actividades')->findOne(['_id' => $id]);
}

/** Solo una actividad programada acepta ventas nuevas, cambios o anulaciones. */
function aceptaVentas(?object $actividad): bool
{
    return $actividad !== null && $actividad['estado'] === 'programada';
}

/**
 * Total recaudado por actividad, calculado en la base con aggregation pipeline.
 *
 * Turnos y ferias suman la colección ventas; las rifas suman los números
 * vendidos en la colección rifas. Los números apartados por la comunidad que
 * todavía no se pagaron no cuentan (Issue #114). El $match por actividad_id usa el índice
 * {actividad_id, fecha} (y {rifa_id, numero} en rifas) como prefijo.
 *
 * @param ObjectId[] $ids
 * @return array<string, array{n: int, total: float}> id de la actividad → cantidad y total
 */
function totalesPorActividad(MongoDB\Database $baseDatos, array $ids): array
{
    $totales = [];
    if (!$ids) {
        return $totales;
    }

    $consultas = [
        'ventas' => 'actividad_id',   // n = cantidad de ventas
        'rifas'  => 'rifa_id',        // n = números vendidos
    ];
    foreach ($consultas as $coleccion => $campo) {
        $total = $coleccion === 'ventas' ? '$total' : '$monto';
        $filtro = [$campo => ['$in' => array_values($ids)]];
        if ($coleccion === 'rifas') {
            $filtro['estado'] = ['$ne' => 'apartado'];   // los números sin "estado" (cargas viejas) son vendidos
        }
        $pipeline = [
            ['$match' => $filtro],
            ['$group' => ['_id' => '$' . $campo, 'n' => ['$sum' => 1], 'total' => ['$sum' => $total]]],
        ];
        foreach ($baseDatos->selectCollection($coleccion)->aggregate($pipeline) as $g) {
            $clave = (string) $g['_id'];
            $totales[$clave] = [
                'n'     => ($totales[$clave]['n'] ?? 0) + (int) $g['n'],
                'total' => ($totales[$clave]['total'] ?? 0.0) + (float) (string) $g['total'],
            ];
        }
    }
    return $totales;
}

/**
 * Lee los productos del formulario de venta (producto[], cantidad[], precio[]).
 *
 * Devuelve los ítems en el formato del JSON Schema, el total calculado aquí
 * en el servidor y los errores encontrados. Las filas vacías se ignoran.
 *
 * @return array{0: array, 1: float, 2: string[], 3: array} [items, total, errores, filas para volver a mostrar]
 */
function leerItems(array $datos): array
{
    $productos  = is_array($datos['producto'] ?? null) ? $datos['producto'] : [];
    $cantidades = is_array($datos['cantidad'] ?? null) ? $datos['cantidad'] : [];
    $precios    = is_array($datos['precio'] ?? null) ? $datos['precio'] : [];

    $items   = [];
    $filas   = [];
    $errores = [];
    $total   = 0.0;

    foreach (array_keys($productos) as $i) {
        $producto = texto($productos[$i] ?? '');
        $cantidad = texto($cantidades[$i] ?? '');
        $precio   = texto($precios[$i] ?? '');

        if ($producto === '' && $cantidad === '' && $precio === '') {
            continue; // fila vacía
        }
        $filas[] = ['producto' => $producto, 'cantidad' => $cantidad, 'precio' => $precio];
        $n = count($filas);

        if ($producto === '') {
            $errores[] = "Falta el nombre del producto en la fila $n.";
        } elseif (mb_strlen($producto) > 60) {
            $errores[] = "El nombre del producto de la fila $n es demasiado largo.";
        }
        if (!ctype_digit($cantidad) || (int) $cantidad < 1 || (int) $cantidad > 999) {
            $errores[] = "La cantidad de la fila $n debe ser un número entero entre 1 y 999.";
        }
        if (!is_numeric($precio) || (float) $precio <= 0) {
            $errores[] = "El precio de la fila $n debe ser un monto mayor que cero.";
        }

        if (!$errores) {
            $items[] = [
                'producto' => $producto,
                'cantidad' => (int) $cantidad,
                'precio'   => decimal($precio),
            ];
            $total += (int) $cantidad * round((float) $precio, 2);
        }
    }

    if (!$filas) {
        $errores[] = 'Agregá al menos un producto.';
    } elseif (count($filas) > MAX_ITEMS) {
        $errores[] = 'Una venta puede tener como máximo ' . MAX_ITEMS . ' productos.';
    }

    return [$items, $total, $errores, $filas];
}

/**
 * Rango de un día en hora de Costa Rica expresado en UTC, para filtrar
 * ventas (que guardan el instante exacto) por día local.
 *   desde "2026-10-20" → 20 oct 00:00 CR = 06:00 UTC
 *   hasta "2026-10-20" → 21 oct 00:00 CR (exclusivo)
 */
function inicioDiaLocal(string $fecha, bool $siguiente = false): ?UTCDateTime
{
    $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone(ZONA_LOCAL));
    if (!$dia || $dia->format('Y-m-d') !== $fecha) {
        return null;
    }
    return new UTCDateTime($siguiente ? $dia->modify('+1 day') : $dia);
}

/** ObjectId del usuario con sesión, para filtrar "lo mío" (apartados, encargos). */
function miUsuarioId(): ObjectId
{
    return new ObjectId(requiereLogin()['id']);
}

/**
 * Busca el contacto de una persona por su correo, sin distinguir mayúsculas,
 * y si no existe lo registra como vecino. Mismo criterio que la aprobación de
 * solicitudes de alquiler. Se llama dentro de una transacción ($sesion).
 */
function contactoPorCorreo(MongoDB\Database $baseDatos, string $nombre, string $correo, MongoDB\Driver\Session $sesion): ObjectId
{
    $contactos = $baseDatos->selectCollection('contactos');
    $contacto  = $contactos->findOne(
        ['correo' => ['$regex' => '^' . preg_quote($correo, '/') . '$', '$options' => 'i']],
        ['session' => $sesion, 'projection' => ['_id' => 1]]
    );
    if ($contacto) {
        return $contacto['_id'];
    }
    return $contactos->insertOne([
        'nombre'         => $nombre,
        'tipo'           => 'vecino',
        'correo'         => $correo,
        'telefono'       => '',
        'activo'         => true,
        'fecha_registro' => new UTCDateTime(),
    ], ['session' => $sesion])->getInsertedId();
}

/** Contactos activos para los selects (los que no tienen el campo "activo" cuentan como activos). */
function contactosActivos(MongoDB\Database $baseDatos): array
{
    return $baseDatos->selectCollection('contactos')
        ->find(['activo' => ['$ne' => false]], [
            'sort'       => ['nombre' => 1],
            'projection' => ['nombre' => 1, 'tipo' => 1],
        ])
        ->toArray();
}

/**
 * Nombres de los contactos referenciados en una lista de documentos.
 * Una sola consulta con $in en vez de una por fila.
 *
 * @return array<string, string> id → nombre
 */
function nombresContactos(MongoDB\Database $baseDatos, iterable $documentos): array
{
    $ids = [];
    foreach ($documentos as $d) {
        if (isset($d['contacto_id'])) {
            $ids[(string) $d['contacto_id']] = $d['contacto_id'];
        }
    }
    $nombres = [];
    if ($ids) {
        $cursor = $baseDatos->selectCollection('contactos')->find(
            ['_id' => ['$in' => array_values($ids)]],
            ['projection' => ['nombre' => 1]]
        );
        foreach ($cursor as $c) {
            $nombres[(string) $c['_id']] = $c['nombre'];
        }
    }
    return $nombres;
}
