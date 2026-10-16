<?php
/**
 * Crea las colecciones del módulo de alquiler de espacios con su validación
 * JSON Schema y sus índices.
 *
 * Colecciones: espacios y reservas.
 *
 * Reglas que quedan garantizadas por la base de datos, no por el código:
 *   - Un espacio solo puede ser "salon" o "cancha" y necesita al menos una tarifa.
 *   - Una reserva solo puede estar "apartado" o "confirmado".
 *   - No pueden existir dos reservas del mismo espacio, fecha y franja
 *     (índice único compuesto).
 *   - Un apartado sin confirmar se elimina solo al llegar su fecha de
 *     vencimiento (índice TTL sobre "vence").
 *   - Una reserva "apartado" DEBE tener "vence"; una "confirmado" NO puede
 *     tenerlo. Al confirmar el pago se hace $unset de "vence".
 *
 * Es idempotente: se puede correr las veces que haga falta. Si la colección
 * ya existe le aplica el validador con collMod, y si el índice ya existe
 * MongoDB no hace nada.
 *
 * Uso (desde la raíz del proyecto):
 *   composer install
 *   php scripts/crear_colecciones.php [opciones]
 *
 * Opciones:
 *   --verificar   Solo muestra los validadores e índices actuales, sin cambiar nada
 *   --ayuda       Muestra esta ayuda
 *
 * Lee MONGODB_URI y MONGODB_DB del archivo .env (o de variables de entorno).
 *
 * Issues: #7 (espacios), #8 (reservas), #9 (índice único), #10 (índice TTL).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

/** Franjas horarias válidas. Deben coincidir con scripts/cargar_datos.php. */
const FRANJAS = ['mañana', 'tarde', 'noche'];

/** Horas que dura un apartado antes de vencer. Documentado aquí a propósito:
 *  el valor lo usa la aplicación al crear la reserva, no el índice TTL. */
const HORAS_APARTADO = 48;

// --- Opciones -------------------------------------------------------------

$opts = getopt('', ['verificar', 'ayuda']);
if (isset($opts['ayuda'])) {
    preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
    echo preg_replace('/^\s*\* ?/m', '', $m[1]), "\n";
    exit(0);
}

// --- Conexión -------------------------------------------------------------

$env    = leerEnv(dirname(__DIR__) . '/.env');
$uri    = getenv('MONGODB_URI') ?: ($env['MONGODB_URI'] ?? '');
$dbName = getenv('MONGODB_DB')  ?: ($env['MONGODB_DB']  ?? 'adi');

if ($uri === '') {
    fwrite(STDERR, "Falta MONGODB_URI: copie .env.example a .env y complételo.\n");
    exit(1);
}

$db = (new MongoDB\Client($uri, ['serverSelectionTimeoutMS' => 5000]))->selectDatabase($dbName);
$db->command(['ping' => 1]);
echo "Conectado a la base {$dbName}.\n";

if (isset($opts['verificar'])) {
    verificar($db);
    exit(0);
}

// --- espacios -------------------------------------------------------------

$esquemaEspacios = [
    'bsonType'             => 'object',
    'title'                => 'Espacio alquilable de la ADI',
    'required'             => ['nombre', 'tipo', 'tarifas', 'activo'],
    'additionalProperties' => false,
    'properties'           => [
        '_id'     => ['bsonType' => 'objectId'],
        'nombre'  => [
            'bsonType'    => 'string',
            'minLength'   => 3,
            'description' => 'Nombre visible del espacio, por ejemplo "Salón comunal"',
        ],
        'tipo'    => [
            'enum'        => ['salon', 'cancha'],
            'description' => 'Solo se alquilan salones y canchas',
        ],
        'tarifas' => [
            'bsonType'    => 'array',
            'minItems'    => 1,
            'description' => 'Tarifa por franja. Van embebidas porque siempre se leen junto al espacio y son pocas',
            'items'       => [
                'bsonType'             => 'object',
                'required'             => ['franja', 'monto'],
                'additionalProperties' => false,
                'properties'           => [
                    'franja' => ['enum' => FRANJAS],
                    'monto'  => [
                        'bsonType'    => 'decimal',
                        'description' => 'Monto en colones. Decimal128, nunca float, porque es dinero',
                    ],
                ],
            ],
        ],
        'activo'  => [
            'bsonType'    => 'bool',
            'description' => 'Un espacio no se borra: se marca inactivo y deja de ofrecerse',
        ],
    ],
];

aplicarEsquema($db, 'espacios', ['$jsonSchema' => $esquemaEspacios]);

// --- reservas -------------------------------------------------------------

$esquemaReservas = [
    'bsonType'             => 'object',
    'title'                => 'Apartado o alquiler confirmado de un espacio',
    'required'             => ['espacio_id', 'contacto_id', 'fecha', 'franja', 'estado', 'monto'],
    'additionalProperties' => false,
    'properties'           => [
        '_id'         => ['bsonType' => 'objectId'],
        'espacio_id'  => [
            'bsonType'    => 'objectId',
            'description' => 'Referencia a espacios. Va por referencia porque un espacio tiene miles de reservas',
        ],
        'contacto_id' => [
            'bsonType'    => 'objectId',
            'description' => 'Referencia a contactos, para el historial con $lookup',
        ],
        'fecha'       => [
            'bsonType'    => 'date',
            'description' => 'Día del alquiler, a medianoche UTC',
        ],
        'franja'      => ['enum' => FRANJAS],
        'estado'      => [
            'enum'        => ['apartado', 'confirmado'],
            'description' => 'Apartado vence solo; confirmado es permanente',
        ],
        'monto'       => [
            'bsonType'    => 'decimal',
            'description' => 'Monto cobrado, tomado de la tarifa del espacio al reservar',
        ],
        'vence'       => [
            'bsonType'    => 'date',
            'description' => 'Solo en apartados. El índice TTL borra el documento al pasar esta fecha',
        ],
    ],
];

// Además del esquema: "vence" existe si y solo si el estado es "apartado".
// JSON Schema no expresa condicionales, así que se combina con un $or.
$validadorReservas = [
    '$and' => [
        ['$jsonSchema' => $esquemaReservas],
        ['$or' => [
            ['estado' => 'apartado',   'vence' => ['$exists' => true]],
            ['estado' => 'confirmado', 'vence' => ['$exists' => false]],
        ]],
    ],
];

aplicarEsquema($db, 'reservas', $validadorReservas);

// --- Índices --------------------------------------------------------------
//
// Sin nombre propio a propósito: así coinciden con los que crea
// scripts/cargar_datos.php --indices y MongoDB los reconoce como el mismo.

echo "Índices de reservas:\n";

$db->reservas->createIndex(
    ['espacio_id' => 1, 'fecha' => 1, 'franja' => 1],
    ['unique' => true]
);
echo "  único {espacio_id, fecha, franja}: dos reservas del mismo horario no pueden existir\n";

$db->reservas->createIndex(
    ['vence' => 1],
    ['expireAfterSeconds' => 0]
);
echo "  TTL sobre vence: el apartado sin confirmar se elimina solo (el monitor corre cada ~60 s)\n";

echo "\nListo. Apartado configurado a " . HORAS_APARTADO . " horas.\n";
verificar($db);

// --- Funciones ------------------------------------------------------------

function leerEnv(string $archivo): array
{
    $env = [];
    if (!is_readable($archivo)) {
        return $env;
    }
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $linea, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
    return $env;
}

/**
 * Crea la colección con su validador, o se lo aplica si ya existe.
 * validationLevel strict + validationAction error: el documento inválido se
 * rechaza, no se guarda con una advertencia.
 */
function aplicarEsquema(MongoDB\Database $db, string $coleccion, array $validador): void
{
    $existe = iterator_to_array($db->listCollectionNames(['filter' => ['name' => $coleccion]])) !== [];

    $opciones = [
        'validator'        => $validador,
        'validationLevel'  => 'strict',
        'validationAction' => 'error',
    ];

    if ($existe) {
        $db->command(array_merge(['collMod' => $coleccion], $opciones));
        echo "Colección {$coleccion}: validador JSON Schema actualizado\n";
    } else {
        $db->createCollection($coleccion, $opciones);
        echo "Colección {$coleccion}: creada con validador JSON Schema\n";
    }
}

/** Muestra el estado actual: qué colecciones tienen validador y qué índices existen. */
function verificar(MongoDB\Database $db): void
{
    echo "\n--- Estado actual ---\n";
    foreach (['espacios', 'reservas'] as $coleccion) {
        $info = iterator_to_array($db->listCollections(['filter' => ['name' => $coleccion]]));
        if ($info === []) {
            echo "{$coleccion}: NO EXISTE\n";
            continue;
        }
        $opciones  = $info[0]->getOptions();
        $validador = isset($opciones['validator']) ? 'sí' : 'NO';
        $nivel     = $opciones['validationLevel'] ?? '-';
        $docs      = $db->selectCollection($coleccion)->estimatedDocumentCount();

        echo "{$coleccion}: validador {$validador} (nivel {$nivel}), {$docs} documentos\n";

        foreach ($db->selectCollection($coleccion)->listIndexes() as $indice) {
            $claves = implode(', ', array_map(
                fn ($k, $v) => "{$k}:{$v}",
                array_keys((array) $indice->getKey()),
                array_values((array) $indice->getKey())
            ));
            $extra = [];
            if ($indice->isUnique()) {
                $extra[] = 'único';
            }
            if (isset(((array) $indice)['expireAfterSeconds'])) {
                $extra[] = 'TTL';
            }
            $sufijo = $extra ? ' [' . implode(', ', $extra) . ']' : '';
            echo "    índice {$indice->getName()}: {{$claves}}{$sufijo}\n";
        }
    }
}
