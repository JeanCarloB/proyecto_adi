/**
 * ADIministra · Módulo de alquiler de espacios
 * Crea las colecciones espacios y reservas con su validación JSON Schema
 * y sus índices.
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
 * Se ejecuta con el usuario personal de Atlas (readWrite + dbAdmin).
 * El usuario "servidor" de la aplicación solo tiene readWrite y no puede
 * crear validadores ni índices: esa separación es intencional.
 *
 * Uso:
 *   mongosh "<URI personal de Atlas>" --file scripts/crear_colecciones.js
 *   o pegando el contenido en la consola mongosh de MongoDB Compass.
 *
 * Es idempotente: se puede correr las veces que haga falta.
 *
 * Issues: #7 (espacios), #8 (reservas), #9 (índice único), #10 (índice TTL).
 */

const adi = db.getSiblingDB("adi");

/** Franjas horarias válidas. Deben coincidir con scripts/cargar_datos.php. */
const FRANJAS = ["mañana", "tarde", "noche"];

/** Horas que dura un apartado antes de vencer. El valor lo usa la aplicación
 *  al calcular "vence"; el índice TTL solo lee la fecha ya calculada. */
const HORAS_APARTADO = 48;

// --- espacios -------------------------------------------------------------

const esquemaEspacios = {
  bsonType: "object",
  title: "Espacio alquilable de la ADI",
  required: ["nombre", "tipo", "tarifas", "activo"],
  additionalProperties: false,
  properties: {
    _id: { bsonType: "objectId" },
    nombre: {
      bsonType: "string",
      minLength: 3,
      description: 'Nombre visible del espacio, por ejemplo "Salón comunal"',
    },
    tipo: {
      enum: ["salon", "cancha"],
      description: "Solo se alquilan salones y canchas",
    },
    tarifas: {
      bsonType: "array",
      minItems: 1,
      description:
        "Tarifa por franja. Van embebidas porque siempre se leen junto al espacio y son pocas",
      items: {
        bsonType: "object",
        required: ["franja", "monto"],
        additionalProperties: false,
        properties: {
          franja: { enum: FRANJAS },
          monto: {
            bsonType: "decimal",
            description: "Monto en colones. Decimal128, nunca double, porque es dinero",
          },
        },
      },
    },
    activo: {
      bsonType: "bool",
      description: "Un espacio no se borra: se marca inactivo y deja de ofrecerse",
    },
  },
};

aplicarValidador("espacios", { $jsonSchema: esquemaEspacios });

// --- reservas -------------------------------------------------------------

const esquemaReservas = {
  bsonType: "object",
  title: "Apartado o alquiler confirmado de un espacio",
  required: ["espacio_id", "contacto_id", "fecha", "franja", "estado", "monto"],
  additionalProperties: false,
  properties: {
    _id: { bsonType: "objectId" },
    espacio_id: {
      bsonType: "objectId",
      description:
        "Referencia a espacios. Va por referencia porque un espacio tiene miles de reservas",
    },
    contacto_id: {
      bsonType: "objectId",
      description: "Referencia a contactos, para el historial con $lookup",
    },
    fecha: {
      bsonType: "date",
      description: "Día del alquiler, a medianoche UTC",
    },
    franja: { enum: FRANJAS },
    estado: {
      enum: ["apartado", "confirmado"],
      description: "Apartado vence solo; confirmado es permanente",
    },
    monto: {
      bsonType: "decimal",
      description: "Monto cobrado, tomado de la tarifa del espacio al reservar",
    },
    vence: {
      bsonType: "date",
      description: "Solo en apartados. El índice TTL borra el documento al pasar esta fecha",
    },
  },
};

// Además del esquema: "vence" existe si y solo si el estado es "apartado".
// JSON Schema no expresa condicionales, así que se combina con un $or.
// Esto garantiza que el TTL jamás borre una reserva ya pagada.
const validadorReservas = {
  $and: [
    { $jsonSchema: esquemaReservas },
    {
      $or: [
        { estado: "apartado", vence: { $exists: true } },
        { estado: "confirmado", vence: { $exists: false } },
      ],
    },
  ],
};

aplicarValidador("reservas", validadorReservas);

// --- Índices --------------------------------------------------------------
//
// Sin nombre propio a propósito: así coinciden con los que crea
// scripts/cargar_datos.php --indices y MongoDB los reconoce como el mismo.

print("\nÍndices de reservas:");

adi.reservas.createIndex({ espacio_id: 1, fecha: 1, franja: 1 }, { unique: true });
print("  único {espacio_id, fecha, franja}: dos reservas del mismo horario no pueden existir");

adi.reservas.createIndex({ vence: 1 }, { expireAfterSeconds: 0 });
print("  TTL sobre vence: el apartado sin confirmar se elimina solo (el monitor corre cada ~60 s)");

print("\nListo. Apartado configurado a " + HORAS_APARTADO + " horas.");

verificar();

// --- Funciones ------------------------------------------------------------

/**
 * Crea la colección con su validador, o se lo aplica si ya existe.
 * strict + error: el documento inválido se rechaza, no se guarda con una
 * advertencia. Los documentos que ya estaban no se revalidan.
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

  ["espacios", "reservas"].forEach(function (coleccion) {
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
      const extra = [];
      if (indice.unique) extra.push("único");
      if (indice.expireAfterSeconds !== undefined) extra.push("TTL");
      const sufijo = extra.length ? " [" + extra.join(", ") + "]" : "";
      print("    índice " + indice.name + ": {" + claves + "}" + sufijo);
    });
  });
}
