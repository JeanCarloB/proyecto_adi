/**
 * ADIministra · Módulo de ventas en actividades
 * Crea las colecciones actividades, ventas y rifas con su validación
 * JSON Schema y sus índices.
 *
 * Reglas que quedan garantizadas por la base de datos, no por el código:
 *   - Una actividad solo puede ser "turno", "feria" o "rifa", y solo puede
 *     estar "programada" o "finalizada".
 *   - Una venta tiene al menos un producto, cada uno con cantidad ≥ 1 y un
 *     precio en Decimal128. El total también es Decimal128.
 *   - Un número de rifa va del 0 al 99 y NO se puede vender dos veces en la
 *     misma rifa (índice único compuesto {rifa_id, numero}).
 *
 * Se ejecuta con el usuario personal de Atlas (readWrite + dbAdmin), igual
 * que scripts/crear_colecciones.js. El usuario "servidor" de la aplicación
 * no puede crear validadores ni índices.
 *
 * Uso:
 *   mongosh "<URI personal de Atlas>" --file scripts/crear_colecciones_ventas.js
 *   o pegando el contenido en la consola mongosh de MongoDB Compass.
 *
 * Es idempotente: se puede correr las veces que haga falta. Los documentos
 * que ya existen no se revalidan; solo los que se inserten o modifiquen.
 *
 * Compatible con los datos de scripts/cargar_datos.php y scripts/cargar_datos.js.
 * Por eso cantidad y numero aceptan int, long o double: mongosh guarda los
 * números de JavaScript como double y PHP los guarda como int.
 *
 * Issues: #27 (actividades), #28 (ventas y rifas), #29 (índice único de rifas),
 *         #30 (índice de ventas por actividad y fecha).
 */

const adi = db.getSiblingDB("adi");

/** Cantidad de números de cada rifa: del 00 al 99. Debe coincidir con src/includes/ventas.php. */
const NUMEROS_RIFA = 100;

const ENTERO = ["int", "long", "double"];

// --- actividades ----------------------------------------------------------

const esquemaActividades = {
  bsonType: "object",
  title: "Turno, feria o rifa de la ADI",
  required: ["nombre", "tipo", "fecha", "estado"],
  additionalProperties: false,
  properties: {
    _id: { bsonType: "objectId" },
    nombre: {
      bsonType: "string",
      minLength: 3,
      description: 'Nombre visible, por ejemplo "Turno navideño 2026"',
    },
    tipo: {
      enum: ["turno", "feria", "rifa"],
      description: "Turnos y ferias registran ventas; las rifas venden números",
    },
    fecha: {
      bsonType: "date",
      description: "Día de la actividad (en las rifas, el día del sorteo)",
    },
    estado: {
      enum: ["programada", "finalizada"],
      description: "Una actividad finalizada ya no acepta ventas: su total queda cerrado",
    },
    precio_numero: {
      bsonType: "decimal",
      description: "Solo en rifas: precio de cada número, en colones",
    },
  },
};

aplicarValidador("actividades", { $jsonSchema: esquemaActividades });

// --- ventas ---------------------------------------------------------------

const esquemaVentas = {
  bsonType: "object",
  title: "Venta registrada en un turno o una feria",
  required: ["actividad_id", "items", "total", "fecha"],
  additionalProperties: false,
  properties: {
    _id: { bsonType: "objectId" },
    actividad_id: {
      bsonType: "objectId",
      description:
        "Referencia a actividades. Va por referencia porque una actividad tiene miles de ventas",
    },
    items: {
      bsonType: "array",
      minItems: 1,
      description:
        "Productos vendidos. Van embebidos: siempre se leen junto a la venta y no cambian después",
      items: {
        bsonType: "object",
        required: ["producto", "cantidad", "precio"],
        additionalProperties: false,
        properties: {
          producto: { bsonType: "string", minLength: 1 },
          cantidad: { bsonType: ENTERO, minimum: 1 },
          precio: {
            bsonType: "decimal",
            description: "Precio unitario en colones al momento de la venta",
          },
        },
      },
    },
    total: {
      bsonType: "decimal",
      description: "Suma de cantidad × precio. La calcula la aplicación, nunca el formulario",
    },
    fecha: { bsonType: "date", description: "Momento de la venta" },
    contacto_id: {
      bsonType: "objectId",
      description: "Opcional: quién compró, para el historial del contacto",
    },
  },
};

aplicarValidador("ventas", { $jsonSchema: esquemaVentas });

// --- rifas ----------------------------------------------------------------

const esquemaRifas = {
  bsonType: "object",
  title: "Número vendido de una rifa",
  required: ["rifa_id", "numero", "contacto_id", "monto", "fecha"],
  additionalProperties: false,
  properties: {
    _id: { bsonType: "objectId" },
    rifa_id: {
      bsonType: "objectId",
      description: "Referencia a la actividad de tipo rifa",
    },
    numero: {
      bsonType: ENTERO,
      minimum: 0,
      maximum: NUMEROS_RIFA - 1,
      description: "Número vendido, del 00 al 99",
    },
    contacto_id: {
      bsonType: "objectId",
      description: "Quién compró el número (para avisarle si gana)",
    },
    monto: { bsonType: "decimal", description: "Lo que pagó por el número" },
    fecha: { bsonType: "date", description: "Momento de la venta" },
  },
};

aplicarValidador("rifas", { $jsonSchema: esquemaRifas });

// --- Índices --------------------------------------------------------------
//
// Sin nombre propio a propósito: así coinciden con los que crea
// scripts/cargar_datos.php --indices y MongoDB los reconoce como el mismo.

print("\nÍndices del módulo de ventas:");

adi.rifas.createIndex({ rifa_id: 1, numero: 1 }, { unique: true });
print("  único {rifa_id, numero}: un número de rifa no se puede vender dos veces");

adi.ventas.createIndex({ actividad_id: 1, fecha: 1 });
print("  compuesto {actividad_id, fecha}: ventas de una actividad y su total recaudado");

verificar();

// --- Funciones ------------------------------------------------------------

/**
 * Crea la colección con su validador, o se lo aplica si ya existe.
 * strict + error: el documento inválido se rechaza, no se guarda con una
 * advertencia.
 */
function aplicarValidador(coleccion, validador) {
  const opciones = {
    validator: validador,
    validationLevel: "strict",
    validationAction: "error",
  };

  if (adi.getCollectionNames().includes(coleccion)) {
    adi.runCommand(Object.assign({ collMod: coleccion }, opciones));
    print("Colección " + coleccion + ": validador JSON Schema actualizado");
  } else {
    adi.createCollection(coleccion, opciones);
    print("Colección " + coleccion + ": creada con validador JSON Schema");
  }
}

/** Muestra qué colecciones tienen validador y qué índices existen. */
function verificar() {
  print("\n--- Estado actual ---");

  ["actividades", "ventas", "rifas"].forEach(function (coleccion) {
    const info = adi.getCollectionInfos({ name: coleccion });
    if (info.length === 0) {
      print(coleccion + ": NO EXISTE");
      return;
    }

    const opciones = info[0].options || {};
    const tiene = opciones.validator ? "sí" : "NO";
    const nivel = opciones.validationLevel || "-";
    const docs = adi.getCollection(coleccion).estimatedDocumentCount();

    print(coleccion + ": validador " + tiene + " (nivel " + nivel + "), " + docs + " documentos");

    adi.getCollection(coleccion).getIndexes().forEach(function (indice) {
      const claves = Object.keys(indice.key)
        .map(function (k) { return k + ":" + indice.key[k]; })
        .join(", ");
      const sufijo = indice.unique ? " [único]" : "";
      print("    índice " + indice.name + ": {" + claves + "}" + sufijo);
    });
  });
}
