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
 * También crea "solicitudes": los pedidos de reserva que hace la comunidad
 * desde la página pública (protegidos con captcha). La junta los aprueba o
 * los rechaza; los que nadie atiende vencen solos a los DIAS_SOLICITUD días.
 *
 * Issues: #7 (espacios), #8 (reservas), #9 (índice único), #10 (índice TTL).
 */

const adi = db.getSiblingDB("adi");

/** Franjas horarias válidas. Deben coincidir con scripts/cargar_datos.php. */
const FRANJAS = ["mañana", "tarde", "noche"];

/** Horas que dura un apartado antes de vencer. El valor lo usa la aplicación
 *  al calcular "vence"; el índice TTL solo lee la fecha ya calculada. */
const HORAS_APARTADO = 48;

/** Días que una solicitud de la comunidad espera respuesta antes de vencer. */
const DIAS_SOLICITUD = 7;

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

// --- solicitudes -----------------------------------------------------------
//
// Pedidos de la comunidad desde src/publico/solicitar.php. No son reservas:
// no ocupan el horario hasta que la junta las aprueba. Así un bot o una
// persona no puede bloquear la cancha con pedidos falsos.

const esquemaSolicitudes = {
  bsonType: "object",
  title: "Solicitud de reserva hecha por la comunidad",
  required: ["espacio_id", "fecha", "franja", "nombre", "correo", "estado", "creada", "captcha"],
  additionalProperties: false,
  properties: {
    _id: { bsonType: "objectId" },
    espacio_id: { bsonType: "objectId", description: "Referencia a espacios" },
    fecha: { bsonType: "date", description: "Día pedido, a medianoche UTC" },
    franja: { enum: FRANJAS },
    nombre: { bsonType: "string", minLength: 3, maxLength: 80 },
    telefono: { bsonType: "string", maxLength: 20 },
    correo: {
      bsonType: "string",
      maxLength: 120,
      pattern: "^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$",
      description: "Se guarda en minúsculas para comparar con contactos",
    },
    mensaje: { bsonType: "string", maxLength: 300 },
    estado: { enum: ["pendiente", "aprobada", "rechazada"] },
    creada: { bsonType: "date" },
    expira: {
      bsonType: "date",
      description: "Solo en pendientes. El índice TTL borra la solicitud que nadie atendió",
    },
    captcha: {
      bsonType: "string",
      minLength: 64,
      maxLength: 64,
      description: "Reto ALTCHA resuelto (SHA-256). Índice único: un captcha sirve una sola vez",
    },
    resuelta: { bsonType: "date" },
    reserva_id: { bsonType: "objectId", description: "Reserva creada al aprobar" },
    motivo: { bsonType: "string", maxLength: 200, description: "Motivo del rechazo" },
  },
};

// Igual que en reservas: "expira" existe si y solo si la solicitud sigue pendiente.
// Al aprobar o rechazar se hace $unset de "expira" y el TTL ya no la borra.
aplicarValidador("solicitudes", {
  $and: [
    { $jsonSchema: esquemaSolicitudes },
    {
      $or: [
        { estado: "pendiente", expira: { $exists: true } },
        { estado: { $in: ["aprobada", "rechazada"] }, expira: { $exists: false } },
      ],
    },
  ],
});

print("\nÍndices de solicitudes:");

adi.solicitudes.createIndex({ expira: 1 }, { expireAfterSeconds: 0 });
print("  TTL sobre expira: la solicitud sin respuesta se borra sola a los " + DIAS_SOLICITUD + " días");

adi.solicitudes.createIndex({ captcha: 1 }, { unique: true });
print("  único {captcha}: el mismo captcha resuelto no se puede usar dos veces");

adi.solicitudes.createIndex(
  { espacio_id: 1, fecha: 1, franja: 1, correo: 1 },
  { unique: true, partialFilterExpression: { estado: "pendiente" } }
);
print("  único parcial {espacio_id, fecha, franja, correo} solo en pendientes: una persona no repite el mismo pedido");

adi.solicitudes.createIndex({ estado: 1, creada: 1 });
print("  {estado, creada}: bandeja de pendientes de la junta, de la más antigua a la más nueva");

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

  ["espacios", "reservas", "solicitudes"].forEach(function (coleccion) {
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
