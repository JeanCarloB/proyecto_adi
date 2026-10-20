/**
 * ADIministra · Módulo de contactos y relaciones
 * Crea las colecciones contactos y seguimientos con su validación
 * JSON Schema y sus índices.
 *
 * Reglas que quedan garantizadas por la base de datos, no por el código:
 *   - Un contacto tiene nombre (al menos 2 caracteres) y solo puede ser
 *     "vecino", "comercio" o "institucion".
 *   - Un seguimiento siempre apunta a un contacto (contacto_id), es una
 *     "nota" o un "recordatorio", tiene descripción y solo puede estar
 *     "pendiente", "completado" o "cancelado".
 *   - Las fechas son de tipo date, nunca texto.
 *
 * Nivel de validación "moderate": los documentos nuevos y los que ya cumplen
 * el esquema se validan siempre; los que se cargaron antes con otra forma
 * (por ejemplo, seguimientos con "nota" y "fecha_recordatorio") se pueden
 * seguir editando desde la aplicación sin que la base los rechace.
 *
 * Se ejecuta con el usuario personal de Atlas (readWrite + dbAdmin), igual
 * que scripts/crear_colecciones.js. El usuario "servidor" de la aplicación
 * no puede crear validadores ni índices.
 *
 * Uso:
 *   mongosh "<URI personal de Atlas>" --file scripts/crear_colecciones_contactos.js
 *   o pegando el contenido en la consola mongosh de MongoDB Compass.
 *
 * Es idempotente: se puede correr las veces que haga falta.
 *
 * Issues: #2 (JSON Schema de contactos y seguimientos), #3, #4.
 */

const adi = db.getSiblingDB("adi");

/** Deben coincidir con src/contactos/crear.php y src/includes/seguimientos.php. */
const TIPOS_CONTACTO = ["vecino", "comercio", "institucion"];
const TIPOS_SEGUIMIENTO = ["nota", "recordatorio"];
const ESTADOS_SEGUIMIENTO = ["pendiente", "completado", "cancelado"];

// --- contactos ------------------------------------------------------------

const esquemaContactos = {
  bsonType: "object",
  title: "Contacto de la ADI: vecino, comercio o institución",
  required: ["nombre", "tipo"],
  additionalProperties: false,
  properties: {
    _id: { bsonType: "objectId" },
    nombre: { bsonType: "string", minLength: 2 },
    tipo: { enum: TIPOS_CONTACTO },
    telefono: { bsonType: "string", description: "Puede ir vacío" },
    correo: { bsonType: "string", description: "Puede ir vacío; el formato lo revisa la aplicación" },
    notas: { bsonType: "string" },
    activo: {
      bsonType: "bool",
      description: "Un contacto no se borra: se desactiva. Sin el campo cuenta como activo",
    },
    fecha_registro: { bsonType: "date" },
  },
};

aplicarValidador("contactos", { $jsonSchema: esquemaContactos });

// --- seguimientos ---------------------------------------------------------

const esquemaSeguimientos = {
  bsonType: "object",
  title: "Nota o recordatorio sobre un contacto",
  required: ["contacto_id", "tipo", "descripcion", "estado"],
  properties: {
    _id: { bsonType: "objectId" },
    contacto_id: {
      bsonType: "objectId",
      description: "Referencia a contactos: el historial se arma con $lookup",
    },
    tipo: { enum: TIPOS_SEGUIMIENTO },
    descripcion: { bsonType: "string", minLength: 1 },
    estado: { enum: ESTADOS_SEGUIMIENTO },
    fecha_creacion: { bsonType: "date" },
    fecha_vencimiento: {
      bsonType: "date",
      description: "Opcional: solo los recordatorios con fecha la llevan",
    },
  },
};

aplicarValidador("seguimientos", { $jsonSchema: esquemaSeguimientos });

// --- Índices --------------------------------------------------------------

print("\nÍndices:");

adi.seguimientos.createIndex({ contacto_id: 1, fecha_creacion: -1 });
print("  seguimientos {contacto_id, fecha_creacion}: historial de un contacto, del más reciente al más viejo");

adi.seguimientos.createIndex({ estado: 1, fecha_vencimiento: 1 });
print("  seguimientos {estado, fecha_vencimiento}: recordatorios pendientes por vencer");

verificar();

// --- Funciones ------------------------------------------------------------

/**
 * Crea la colección con su validador, o se lo aplica si ya existe.
 * moderate + error: un documento nuevo o válido que no cumpla se rechaza;
 * los documentos viejos con otra forma no bloquean las ediciones.
 */
function aplicarValidador(coleccion, validador) {
  const opciones = {
    validator: validador,
    validationLevel: "moderate",
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

  ["contactos", "seguimientos"].forEach(function (coleccion) {
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
      print("  índice " + indice.name);
    });
  });
}
