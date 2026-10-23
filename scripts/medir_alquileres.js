/**
 * Mediciones del módulo de alquileres con explain("executionStats") (Issue #17).
 *
 * Mide cuatro consultas reales de la aplicación, cada una CON su índice y SIN
 * índice (forzando un recorrido completo con hint({$natural: 1})), 3 corridas
 * por caso, y reporta la mediana. No borra ni crea índices: es seguro en la
 * base compartida.
 *
 *   1. Calendario público (src/publico/calendario.php): reservas de un mes con
 *      proyección {_id: 0, espacio_id, fecha, franja}. Con el índice
 *      {espacio_id, fecha, franja} es una consulta CUBIERTA: 0 documentos leídos.
 *   2. Disponibilidad de un horario (antes de apartar o de pedir una reserva).
 *   3. Calendario de la junta (src/reservas/calendario.php): aggregation con
 *      $match + $lookup a contactos + $unwind + $project.
 *   4. Bandeja de solicitudes pendientes (src/reservas/solicitudes.php),
 *      con el índice {estado, creada}.
 *
 * Uso:
 *   mongosh "<URI personal de Atlas>" --file scripts/medir_alquileres.js
 *
 * Imprime una tabla en Markdown lista para pegar en docs/mediciones.md.
 */

const adi = db.getSiblingDB("adi");
const CORRIDAS = 3;
const SIN_INDICE = { $natural: 1 };

// --- Datos de las consultas --------------------------------------------------

const espacios = adi.espacios.find({ activo: true }, { _id: 1 }).toArray().map(function (e) { return e._id; });
if (espacios.length === 0) {
  print("No hay espacios activos en la base.");
  quit(1);
}

// El mes con más reservas, para que la consulta tenga algo que devolver
const mesMasLleno = adi.reservas.aggregate([
  { $group: { _id: { $dateToString: { format: "%Y-%m", date: "$fecha" } }, n: { $sum: 1 } } },
  { $sort: { n: -1 } },
  { $limit: 1 },
]).toArray()[0];
const mes = mesMasLleno ? mesMasLleno._id : new Date().toISOString().slice(0, 7);
const desde = new Date(mes + "-01T00:00:00Z");
const hasta = new Date(desde); hasta.setUTCMonth(hasta.getUTCMonth() + 1);

const unaReserva = adi.reservas.findOne({}, { espacio_id: 1, fecha: 1, franja: 1 });
if (!unaReserva) {
  print("No hay reservas en la base: cargue datos con scripts/cargar_datos.js antes de medir.");
  quit(1);
}

const filtroMes = { espacio_id: { $in: espacios }, fecha: { $gte: desde, $lt: hasta } };
const proyeccionPublica = { _id: 0, espacio_id: 1, fecha: 1, franja: 1 };
const pipelineJunta = [
  { $match: filtroMes },
  { $lookup: { from: "contactos", localField: "contacto_id", foreignField: "_id", as: "contacto" } },
  { $unwind: { path: "$contacto", preserveNullAndEmptyArrays: true } },
  { $project: { espacio_id: 1, fecha: 1, franja: 1, estado: 1, monto: 1, vence: 1, "contacto.nombre": 1 } },
];

const consultas = [
  {
    nombre: "Calendario público del mes (find con proyección)",
    coleccion: "reservas",
    indice: "{espacio_id, fecha, franja} · cubierta",
    correr: function (hint) {
      let c = adi.reservas.find(filtroMes, proyeccionPublica);
      if (hint) c = c.hint(hint);
      return c.explain("executionStats");
    },
  },
  {
    // Se pregunta por un horario LIBRE (lo normal antes de apartar): sin índice,
    // MongoDB tiene que revisar todas las reservas para confirmar que no está.
    nombre: "¿Está libre este espacio, día y franja?",
    coleccion: "reservas",
    indice: "único {espacio_id, fecha, franja}",
    correr: function (hint) {
      let c = adi.reservas.find({ espacio_id: unaReserva.espacio_id, fecha: ISODate("2099-12-29T00:00:00Z"), franja: "noche" }).limit(1);
      if (hint) c = c.hint(hint);
      return c.explain("executionStats");
    },
  },
  {
    nombre: "Calendario de la junta ($match + $lookup + $unwind)",
    coleccion: "reservas",
    indice: "{espacio_id, fecha, franja} en el $match",
    correr: function (hint) {
      const opciones = hint ? { hint: hint } : {};
      return adi.reservas.explain("executionStats").aggregate(pipelineJunta, opciones);
    },
  },
  {
    nombre: "Bandeja de solicitudes pendientes (find + sort)",
    coleccion: "solicitudes",
    indice: "{estado, creada}",
    correr: function (hint) {
      let c = adi.solicitudes.find({ estado: "pendiente" }).sort({ creada: 1 });
      if (hint) c = c.hint(hint);
      return c.explain("executionStats");
    },
  },
];

// --- Utilidades para leer explain() -----------------------------------------
// La forma de explain() cambia entre find y aggregate (y entre motores de
// ejecución), así que se buscan los campos donde estén.

function buscar(obj, clave) {
  if (obj === null || typeof obj !== "object") return undefined;
  if (Object.prototype.hasOwnProperty.call(obj, clave)) return obj[clave];
  for (const k of Object.keys(obj)) {
    const v = buscar(obj[k], clave);
    if (v !== undefined) return v;
  }
  return undefined;
}

/** Etapas del plan ganador, de la de afuera hacia adentro: "PROJECTION_COVERED → IXSCAN". */
function etapas(explain) {
  const plan = buscar(explain, "winningPlan");
  const nombres = [];
  (function recorrer(nodo) {
    if (nodo === null || typeof nodo !== "object") return;
    if (typeof nodo.stage === "string") nombres.push(nodo.stage);
    for (const k of Object.keys(nodo)) recorrer(nodo[k]);
  })(plan);
  // En aggregate, el $lookup aparece como etapa del pipeline o como EQ_LOOKUP del plan
  if (!nombres.includes("EQ_LOOKUP") && JSON.stringify(explain).includes("$lookup")) {
    nombres.unshift("$lookup");
  }
  return nombres.filter(function (n, i) { return nombres.indexOf(n) === i; }).join(" → ") || "?";
}

function sumar(explain, clave) {
  // En aggregate puede haber varias etapas con estadísticas: se toma la mayor
  let mayor = 0;
  (function recorrer(nodo) {
    if (nodo === null || typeof nodo !== "object") return;
    if (typeof nodo[clave] === "number" && nodo[clave] > mayor) mayor = nodo[clave];
    for (const k of Object.keys(nodo)) recorrer(nodo[k]);
  })(explain);
  return mayor;
}

function mediana(valores) {
  const v = valores.slice().sort(function (a, b) { return a - b; });
  return v[Math.floor(v.length / 2)];
}

function medir(consulta, hint) {
  const tiempos = [];
  let ultimo = null;
  for (let i = 0; i < CORRIDAS; i++) {
    ultimo = consulta.correr(hint);
    tiempos.push(sumar(ultimo, "executionTimeMillis"));
  }
  return {
    tiempos: tiempos,
    mediana: mediana(tiempos),
    docs: sumar(ultimo, "totalDocsExamined"),
    claves: sumar(ultimo, "totalKeysExamined"),
    devueltos: sumar(ultimo, "nReturned"),
    plan: etapas(ultimo),
  };
}

// --- Medir --------------------------------------------------------------------

print("Mediciones del módulo de alquileres · " + new Date().toISOString().slice(0, 10));
print("Datos: " + adi.reservas.estimatedDocumentCount() + " reservas, "
  + adi.solicitudes.estimatedDocumentCount() + " solicitudes, "
  + adi.contactos.estimatedDocumentCount() + " contactos · mes medido: " + mes
  + " · " + CORRIDAS + " corridas por caso, se reporta la mediana\n");

const filas = consultas.map(function (c) {
  const con = medir(c, null);
  const sin = medir(c, SIN_INDICE);
  print(c.nombre);
  print("  con índice: " + con.tiempos.join(", ") + " ms · " + con.docs + " docs, " + con.claves + " claves · " + con.plan);
  print("  sin índice: " + sin.tiempos.join(", ") + " ms · " + sin.docs + " docs, " + sin.claves + " claves · " + sin.plan + "\n");
  return { c: c, con: con, sin: sin };
});

print("--- Tabla para docs/mediciones.md ---\n");
print("| Consulta | Índice | Con índice: mediana ms | Docs leídos | Claves | Plan | Sin índice: mediana ms | Docs leídos | Plan |");
print("| --- | --- | ---: | ---: | ---: | --- | ---: | ---: | --- |");
filas.forEach(function (f) {
  print("| " + f.c.nombre + " | " + f.c.indice
    + " | " + f.con.mediana + " | " + f.con.docs + " | " + f.con.claves + " | " + f.con.plan
    + " | " + f.sin.mediana + " | " + f.sin.docs + " | " + f.sin.plan + " |");
});
