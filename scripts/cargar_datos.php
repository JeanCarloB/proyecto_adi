<?php
/**
 * Carga reproducible de datos de prueba (sintéticos) en la base adi.
 *
 * Genera espacios, contactos, actividades, reservas, ventas, rifas y
 * seguimientos siguiendo el diagrama de clases. Todos los datos salen de
 * Faker con una semilla fija: misma semilla y mismo día → mismos datos.
 * Ningún nombre, teléfono ni correo corresponde a una persona real
 * (los correos usan dominios example.*).
 *
 * Uso (desde la raíz del proyecto):
 *   composer install
 *   php scripts/cargar_datos.php [opciones]
 *
 * Opciones:
 *   --ventas=N    Cantidad de ventas a generar (por defecto 100000)
 *   --semilla=N   Semilla de Faker (por defecto 2026)
 *   --lote=N      Documentos por insertMany (por defecto 5000)
 *   --limpiar     Vacía las colecciones antes de cargar (deleteMany:
 *                 conserva índices y validadores JSON Schema existentes)
 *   --indices     Crea los índices del diagrama si no existen
 *   --ayuda       Muestra esta ayuda
 *
 * Lee MONGODB_URI y MONGODB_DB del archivo .env (o de variables de entorno).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__) . '/vendor/autoload.php';

const COLECCIONES = ['espacios', 'contactos', 'actividades', 'reservas', 'ventas', 'rifas', 'seguimientos'];

// --- Opciones -------------------------------------------------------------

$opts = getopt('', ['ventas:', 'semilla:', 'lote:', 'limpiar', 'indices', 'ayuda']);
if (isset($opts['ayuda'])) {
    preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
    echo preg_replace('/^\s*\* ?/m', '', $m[1]), "\n";
    exit(0);
}
$totalVentas = (int) ($opts['ventas'] ?? 100000);
$semilla     = (int) ($opts['semilla'] ?? 2026);
$lote        = max(1, (int) ($opts['lote'] ?? 5000));

// --- Conexión -------------------------------------------------------------

$env = leerEnv(dirname(__DIR__) . '/.env');
$uri = getenv('MONGODB_URI') ?: ($env['MONGODB_URI'] ?? '');
$dbName = getenv('MONGODB_DB') ?: ($env['MONGODB_DB'] ?? 'adi');
if ($uri === '') {
    fwrite(STDERR, "Falta MONGODB_URI: copie .env.example a .env y complételo.\n");
    exit(1);
}

$db = (new MongoDB\Client($uri, ['serverSelectionTimeoutMS' => 5000]))->selectDatabase($dbName);
$db->command(['ping' => 1]);
echo "Conectado a la base {$dbName}. Semilla {$semilla}, {$totalVentas} ventas.\n";

if (isset($opts['limpiar'])) {
    foreach (COLECCIONES as $c) {
        $n = $db->selectCollection($c)->deleteMany([])->getDeletedCount();
        echo "  {$c}: {$n} documentos eliminados\n";
    }
} else {
    foreach (COLECCIONES as $c) {
        if ($db->selectCollection($c)->estimatedDocumentCount() > 0) {
            fwrite(STDERR, "La colección {$c} ya tiene datos. Use --limpiar para recargar.\n");
            exit(1);
        }
    }
}

if (isset($opts['indices'])) {
    crearIndices($db);
}

$faker = Faker\Factory::create('es_ES');
$faker->seed($semilla);

$hoy = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$desde = $hoy->modify('-12 months');
$hasta = $hoy->modify('+3 months');
$ahora = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// --- espacios (tarifas embebidas) -----------------------------------------

$espacios = [
    ['nombre' => 'Salón comunal', 'tipo' => 'salon', 'tarifas' => ['mañana' => 25000, 'tarde' => 30000, 'noche' => 40000]],
    ['nombre' => 'Cancha multiuso', 'tipo' => 'cancha', 'tarifas' => ['mañana' => 8000, 'tarde' => 10000, 'noche' => 15000]],
    ['nombre' => 'Salón de reuniones', 'tipo' => 'salon', 'tarifas' => ['mañana' => 10000, 'tarde' => 12000, 'noche' => 15000]],
];
$docs = [];
foreach ($espacios as $i => $e) {
    $espacios[$i]['_id'] = new ObjectId();
    $docs[] = [
        '_id'     => $espacios[$i]['_id'],
        'nombre'  => $e['nombre'],
        'tipo'    => $e['tipo'],
        'tarifas' => array_map(
            fn ($franja, $monto) => ['franja' => $franja, 'monto' => dec($monto)],
            array_keys($e['tarifas']),
            $e['tarifas']
        ),
        'activo'  => true,
    ];
}
insertar($db, 'espacios', $docs);

// --- contactos ------------------------------------------------------------

$instituciones = ['Escuela', 'Colegio', 'EBAIS', 'Parroquia', 'Comité de deportes', 'Asociación de mujeres', 'Grupo scout', 'Comité de caminos'];
$contactos = [];
$docs = [];
for ($i = 0; $i < 500; $i++) {
    $tipo = $faker->randomElement(array_merge(array_fill(0, 7, 'vecino'), ['comercio', 'comercio', 'institucion']));
    $nombre = match ($tipo) {
        'vecino'      => $faker->firstName() . ' ' . $faker->lastName() . ' ' . $faker->lastName(),
        'comercio'    => $faker->randomElement(['Pulpería', 'Soda', 'Ferretería', 'Panadería', 'Verdulería', 'Taller']) . ' ' . $faker->lastName(),
        'institucion' => $faker->randomElement($instituciones) . ' ' . $faker->city(),
    };
    $id = new ObjectId();
    $contactos[] = $id;
    $docs[] = [
        '_id'      => $id,
        'nombre'   => $nombre,
        'tipo'     => $tipo,
        'telefono' => $faker->randomElement(['2', '6', '7', '8']) . $faker->numerify('###-####'),
        'correo'   => $faker->unique()->safeEmail(),
        'notas'    => $faker->optional(0.4, '')->sentence(),
        'activo'   => true,
    ];
}
insertar($db, 'contactos', $docs);

// --- actividades ----------------------------------------------------------

$nombresActividad = [
    'turno' => ['Turno de fiestas patronales', 'Turno navideño', 'Turno pro-cancha', 'Turno del Día del Niño'],
    'feria' => ['Feria del agricultor', 'Feria de emprendedores', 'Feria de comidas típicas', 'Feria escolar'],
    'rifa'  => ['Rifa pro-techo del salón', 'Rifa de canasta navideña', 'Rifa pro-uniformes', 'Rifa de electrodomésticos'],
];
$actividades = ['turno' => [], 'feria' => [], 'rifa' => []];
$docs = [];
for ($i = 0; $i < 60; $i++) {
    $tipo = $faker->randomElement(['turno', 'turno', 'feria', 'feria', 'rifa']);
    $fecha = entre($faker, $desde, $hasta)->setTime(8, 0);
    $id = new ObjectId();
    $actividades[$tipo][] = ['_id' => $id, 'fecha' => $fecha];
    $docs[] = [
        '_id'    => $id,
        'nombre' => $faker->randomElement($nombresActividad[$tipo]) . ' ' . $fecha->format('Y'),
        'tipo'   => $tipo,
        'fecha'  => fecha($fecha),
        'estado' => $fecha < $hoy ? 'finalizada' : 'programada',
    ];
}
insertar($db, 'actividades', $docs);

// --- reservas (sin repetir espacio + fecha + franja) ----------------------

$docs = [];
$usadas = [];
while (count($docs) < 400) {
    $e = $faker->randomElement($espacios);
    $franja = $faker->randomElement(array_keys($e['tarifas']));
    $fecha = entre($faker, $desde, $hasta)->setTime(0, 0);
    $clave = $e['_id'] . $fecha->format('Ymd') . $franja;
    if (isset($usadas[$clave])) {
        continue;
    }
    $usadas[$clave] = true;
    $doc = [
        'espacio_id'  => $e['_id'],
        'contacto_id' => $faker->randomElement($contactos),
        'fecha'       => fecha($fecha),
        'franja'      => $franja,
        'estado'      => 'confirmado',
        'monto'       => dec($e['tarifas'][$franja]),
    ];
    // Solo las reservas futuras pueden seguir apartadas; vencen en 1 a 3 días.
    if ($fecha > $hoy && $faker->boolean(30)) {
        $doc['estado'] = 'apartado';
        $doc['vence'] = fecha($ahora->modify('+' . $faker->numberBetween(1, 3) . ' days'));
    }
    $docs[] = $doc;
}
insertar($db, 'reservas', $docs);

// --- ventas (items embebidos) ---------------------------------------------

$productos = [
    'Tamal' => 1500, 'Empanada' => 800, 'Café' => 600, 'Gallo de picadillo' => 1000,
    'Arroz con leche' => 1000, 'Refresco natural' => 700, 'Chicharrón' => 2500,
    'Arroz con pollo' => 3000, 'Copo' => 1000, 'Elote' => 800, 'Queque' => 1200,
];
$conVentas = array_merge($actividades['turno'], $actividades['feria']);
$docs = [];
$insertadas = 0;
$inicio = microtime(true);
for ($i = 0; $i < $totalVentas; $i++) {
    $act = $faker->randomElement($conVentas);
    $items = [];
    $total = 0;
    foreach ($faker->randomElements(array_keys($productos), $faker->numberBetween(1, 4)) as $p) {
        $cantidad = $faker->numberBetween(1, 5);
        $items[] = ['producto' => $p, 'cantidad' => $cantidad, 'precio' => dec($productos[$p])];
        $total += $cantidad * $productos[$p];
    }
    $doc = [
        'actividad_id' => $act['_id'],
        'items'        => $items,
        'total'        => dec($total),
        'fecha'        => fecha($act['fecha']->modify('+' . $faker->numberBetween(0, 10 * 3600) . ' seconds')),
    ];
    if ($faker->boolean(25)) {
        $doc['contacto_id'] = $faker->randomElement($contactos);
    }
    $docs[] = $doc;
    if (count($docs) === $lote) {
        $insertadas += $db->ventas->insertMany($docs)->getInsertedCount();
        $docs = [];
        printf("\r  ventas: %d / %d", $insertadas, $totalVentas);
    }
}
if ($docs) {
    $insertadas += $db->ventas->insertMany($docs)->getInsertedCount();
}
printf("\r  ventas: %d documentos (%.1f s)\n", $insertadas, microtime(true) - $inicio);

// --- rifas (números del 00 al 99 sin repetir por rifa) --------------------

$docs = [];
foreach ($actividades['rifa'] as $act) {
    $precio = $faker->randomElement([500, 1000, 2000]);
    foreach ($faker->randomElements(range(0, 99), $faker->numberBetween(30, 90)) as $numero) {
        $docs[] = [
            'rifa_id'     => $act['_id'],
            'numero'      => $numero,
            'contacto_id' => $faker->randomElement($contactos),
            'monto'       => dec($precio),
            'fecha'       => fecha(entre($faker, $act['fecha']->modify('-30 days'), $act['fecha'])),
        ];
    }
}
insertar($db, 'rifas', $docs);

// --- seguimientos ---------------------------------------------------------

$notas = ['Llamar para confirmar patrocinio', 'Enviar recibo del alquiler', 'Invitar a la próxima feria',
          'Recordar pago pendiente', 'Coordinar donación', 'Agradecer colaboración en el turno'];
$docs = [];
for ($i = 0; $i < 300; $i++) {
    $fecha = entre($faker, $ahora->modify('-6 months'), $ahora->modify('+2 months'));
    $docs[] = [
        'contacto_id'        => $faker->randomElement($contactos),
        'fecha_recordatorio' => fecha($fecha),
        'nota'               => $faker->randomElement($notas),
        'estado'             => $fecha < $ahora && $faker->boolean(80) ? 'hecho' : 'pendiente',
    ];
}
insertar($db, 'seguimientos', $docs);

echo "Listo.\n";

// --- Funciones ------------------------------------------------------------

function leerEnv(string $archivo): array
{
    $env = [];
    if (!is_readable($archivo)) {
        return $env;
    }
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;
        [$k, $v] = explode('=', $linea, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
    return $env;
}

function dec(int|float $monto): Decimal128
{
    return new Decimal128(number_format($monto, 2, '.', ''));
}

function entre(Faker\Generator $faker, DateTimeImmutable $desde, DateTimeImmutable $hasta): DateTimeImmutable
{
    return $desde->setTimestamp($faker->numberBetween($desde->getTimestamp(), $hasta->getTimestamp()));
}

function fecha(DateTimeInterface $f): UTCDateTime
{
    return new UTCDateTime($f);
}

function insertar(MongoDB\Database $db, string $coleccion, array $docs): void
{
    $n = $db->selectCollection($coleccion)->insertMany($docs)->getInsertedCount();
    echo "  {$coleccion}: {$n} documentos\n";
}

function crearIndices(MongoDB\Database $db): void
{
    $db->reservas->createIndex(['espacio_id' => 1, 'fecha' => 1, 'franja' => 1], ['unique' => true]);
    $db->reservas->createIndex(['vence' => 1], ['expireAfterSeconds' => 0]);
    $db->rifas->createIndex(['rifa_id' => 1, 'numero' => 1], ['unique' => true]);
    $db->ventas->createIndex(['actividad_id' => 1, 'fecha' => 1]);
    echo "  índices del diagrama creados (o ya existían)\n";
}
