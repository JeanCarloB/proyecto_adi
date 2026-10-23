/**
 * Prueba del índice TTL del módulo de alquileres (Issue #16).
 *
 * 1. Crea un apartado de prueba que vence dentro de 60 segundos.
 * 2. Revisa cada 10 segundos si sigue existiendo y anota cuándo desaparece.
 * 3. Comprueba que el mismo horario se puede volver a reservar sin error 11000.
 * 4. Limpia lo que creó.
 *
 * El monitor TTL de MongoDB corre más o menos cada 60 segundos, así que el
 * apartado desaparece entre 0 y ~60 s DESPUÉS de vencer (no en el segundo exacto).
 *
 * Usa una fecha de prueba (2099-12-30, franja noche) que nadie reserva de verdad.
 *
 * Uso:
 *   mongosh "<URI personal de Atlas>" --file scripts/prueba_ttl.js
 */

const adi = db.getSiblingDB("adi");
const SEGUNDOS_PARA_VENCER = 60;
const REVISAR_CADA = 10;          // segundos
const MAXIMO = 5 * 60;            // no esperar más de 5 minutos

const espacio = adi.espacios.findOne({ activo: true }, { _id: 1, nombre: 1 });
const contacto = adi.contactos.findOne({}, { _id: 1 });
if (!espacio || !contacto) {
  print("Faltan espacios activos o contactos en la base.");
  quit(1);
}

const llave = { espacio_id: espacio._id, fecha: ISODate("2099-12-30T00:00:00Z"), franja: "noche" };
adi.reservas.deleteMany(llave);

const creada = new Date();
const vence = new Date(creada.getTime() + SEGUNDOS_PARA_VENCER * 1000);
const id = adi.reservas.insertOne(Object.assign({}, llave, {
  contacto_id: contacto._id,
  estado: "apartado",
  monto: NumberDecimal("1.00"),
  vence: vence,
})).insertedId;

print("Apartado de prueba en " + espacio.nombre + " (" + id + ")");
print("  creado: " + creada.toISOString());
print("  vence:  " + vence.toISOString() + "  (en " + SEGUNDOS_PARA_VENCER + " s)\n");

let desaparecio = null;
for (let t = 0; t <= MAXIMO; t += REVISAR_CADA) {
  const existe = adi.reservas.countDocuments({ _id: id }) === 1;
  print("  +" + String(t).padStart(3) + " s  " + (existe ? "sigue apartada" : "YA NO EXISTE"));
  if (!existe) {
    desaparecio = new Date();
    break;
  }
  sleep(REVISAR_CADA * 1000);
}

if (!desaparecio) {
  print("\nNo desapareció en " + MAXIMO + " s. ¿Existe el índice TTL sobre vence? Corra scripts/crear_colecciones.js.");
  adi.reservas.deleteMany(llave);
  quit(1);
}

const despuesDeVencer = Math.round((desaparecio - vence) / 1000);
print("\nResultado:");
print("  desapareció a los " + Math.round((desaparecio - creada) / 1000) + " s de crearse");
print("  es decir, " + despuesDeVencer + " s después de su fecha de vencimiento (monitor TTL ~60 s)");

// ¿Se puede volver a reservar el mismo horario? Si el TTL no lo hubiera liberado,
// este insert fallaría con 11000 por el índice único {espacio_id, fecha, franja}.
try {
  adi.reservas.insertOne(Object.assign({}, llave, {
    contacto_id: contacto._id,
    estado: "confirmado",
    monto: NumberDecimal("1.00"),
  }));
  print("  el mismo horario se volvió a reservar sin error: el TTL lo liberó ✔");
} catch (e) {
  print("  ERROR al volver a reservar: " + e.message);
}

adi.reservas.deleteMany(llave);
print("\nLimpieza hecha.");
