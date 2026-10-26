// Carga reproducible de datos de prueba (sintéticos) en la base adi, desde mongosh.
//
// Versión en JavaScript de scripts/cargar_datos.php: genera espacios,
// contactos, actividades, reservas, ventas, rifas y seguimientos según el
// diagrama de clases. Usa un generador pseudoaleatorio con semilla fija,
// así que la misma semilla da los mismos datos. Ningún nombre, teléfono ni
// correo pertenece a una persona real (los correos usan example.com).
//
// Uso desde la terminal:
//   mongosh "mongodb+srv://USUARIO:CONTRASENA@cluster0.xxxxx.mongodb.net/adi_prueba" --file scripts/cargar_datos.js
//
// Uso dentro de mongosh:
//   use adi_prueba
//   load("scripts/cargar_datos.js")
//
// Opciones: defínalas antes de load(), por ejemplo
//   VENTAS = 1000; LIMPIAR = true; INDICES = true; load("scripts/cargar_datos.js")
//   VENTAS   cantidad de ventas (por defecto 100000)
//   SEMILLA  semilla del generador (por defecto 2026)
//   LIMPIAR  true vacía las colecciones antes de cargar (deleteMany: conserva
//            índices y validadores JSON Schema). Por defecto true.
//   INDICES  true crea los índices del diagrama si no existen. Por defecto true.

(function () {
  const totalVentas = typeof VENTAS !== 'undefined' ? VENTAS : 100000;
  const semilla = typeof SEMILLA !== 'undefined' ? SEMILLA : 2026;
  const limpiar = typeof LIMPIAR !== 'undefined' ? LIMPIAR : true;
  const indices = typeof INDICES !== 'undefined' ? INDICES : true;
  const LOTE = 5000;
  const COLECCIONES = ['espacios', 'contactos', 'actividades', 'reservas', 'ventas', 'rifas', 'seguimientos'];

  print(`Base ${db.getName()}. Semilla ${semilla}, ${totalVentas} ventas.`);

  if (limpiar) {
    COLECCIONES.forEach(c => print(`  ${c}: ${db[c].deleteMany({}).deletedCount} documentos eliminados`));
  } else {
    for (const c of COLECCIONES) {
      if (db[c].estimatedDocumentCount() > 0) {
        print(`La colección ${c} ya tiene datos. Use LIMPIAR = true para recargar.`);
        return;
      }
    }
  }

  if (indices) {
    db.reservas.createIndex({ espacio_id: 1, fecha: 1, franja: 1 }, { unique: true });
    db.reservas.createIndex({ vence: 1 }, { expireAfterSeconds: 0 });
    db.rifas.createIndex({ rifa_id: 1, numero: 1 }, { unique: true });
    db.ventas.createIndex({ actividad_id: 1, fecha: 1 });
    print('  índices del diagrama creados (o ya existían)');
  }

  // --- Generador con semilla (mulberry32) ----------------------------------
  let estado = semilla >>> 0;
  const azar = () => {
    estado = (estado + 0x6D2B79F5) >>> 0;
    let t = estado;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
  const entero = (min, max) => min + Math.floor(azar() * (max - min + 1));
  const elegir = lista => lista[Math.floor(azar() * lista.length)];
  const probabilidad = porcentaje => azar() * 100 < porcentaje;
  const variosDe = (lista, n) => {
    const copia = lista.slice();
    for (let i = copia.length - 1; i > 0; i--) {
      const j = Math.floor(azar() * (i + 1));
      [copia[i], copia[j]] = [copia[j], copia[i]];
    }
    return copia.slice(0, n);
  };
  const dec = monto => NumberDecimal(monto.toFixed(2));
  const DIA = 24 * 3600 * 1000;
  const entre = (desde, hasta) => new Date(entero(desde.getTime(), hasta.getTime()));
  const insertar = (coleccion, docs) =>
    print(`  ${coleccion}: ${Object.keys(db[coleccion].insertMany(docs).insertedIds).length} documentos`);

  const ahora = new Date();
  const hoy = new Date(Date.UTC(ahora.getUTCFullYear(), ahora.getUTCMonth(), ahora.getUTCDate()));
  const desde = new Date(hoy); desde.setUTCMonth(desde.getUTCMonth() - 12);
  const hasta = new Date(hoy); hasta.setUTCMonth(hasta.getUTCMonth() + 3);

  // --- espacios (tarifas embebidas) ----------------------------------------
  const espacios = [
    { nombre: 'Salón comunal', tipo: 'salon', tarifas: { 'mañana': 25000, tarde: 30000, noche: 40000 } },
    { nombre: 'Cancha multiuso', tipo: 'cancha', tarifas: { 'mañana': 8000, tarde: 10000, noche: 15000 } },
    { nombre: 'Salón de reuniones', tipo: 'salon', tarifas: { 'mañana': 10000, tarde: 12000, noche: 15000 } },
  ];
  insertar('espacios', espacios.map(e => {
    e._id = new ObjectId();
    return {
      _id: e._id,
      nombre: e.nombre,
      tipo: e.tipo,
      tarifas: Object.entries(e.tarifas).map(([franja, monto]) => ({ franja, monto: dec(monto) })),
      activo: true,
    };
  }));

  // --- contactos -----------------------------------------------------------
  const nombres = ['Ana', 'Luis', 'María', 'José', 'Carmen', 'Carlos', 'Laura', 'Jorge', 'Sofía', 'Andrés',
    'Daniela', 'Fernando', 'Gabriela', 'Diego', 'Valeria', 'Ricardo', 'Mónica', 'Pablo', 'Natalia', 'Esteban'];
  const apellidos = ['Rodríguez', 'Vargas', 'Jiménez', 'Mora', 'Rojas', 'Alvarado', 'Solano', 'Araya', 'Chaves',
    'Quesada', 'Castro', 'Salazar', 'Herrera', 'Brenes', 'Villalobos', 'Campos', 'Ulate', 'Méndez', 'Fallas', 'Zúñiga'];
  const lugares = ['Santa Elena', 'San Rafael', 'La Esperanza', 'El Carmen', 'San Isidro', 'Los Ángeles'];
  const instituciones = ['Escuela', 'Colegio', 'EBAIS', 'Parroquia', 'Comité de deportes', 'Asociación de mujeres',
    'Grupo scout', 'Comité de caminos'];
  const notasContacto = ['Colabora en turnos', 'Patrocinó la última feria', 'Prefiere contacto por WhatsApp',
    'Alquila el salón para cumpleaños'];
  const contactos = [];
  const docsContactos = [];
  for (let i = 0; i < 500; i++) {
    const tipo = elegir(['vecino', 'vecino', 'vecino', 'vecino', 'vecino', 'vecino', 'vecino', 'comercio', 'comercio', 'institucion']);
    const nombre = tipo === 'vecino'
      ? `${elegir(nombres)} ${elegir(apellidos)} ${elegir(apellidos)}`
      : tipo === 'comercio'
        ? `${elegir(['Pulpería', 'Soda', 'Ferretería', 'Panadería', 'Verdulería', 'Taller'])} ${elegir(apellidos)}`
        : `${elegir(instituciones)} ${elegir(lugares)}`;
    const _id = new ObjectId();
    contactos.push(_id);
    docsContactos.push({
      _id,
      nombre,
      tipo,
      telefono: `${elegir(['2', '6', '7', '8'])}${entero(100, 999)}-${String(entero(0, 9999)).padStart(4, '0')}`,
      correo: `contacto${i + 1}@example.com`,
      notas: probabilidad(40) ? elegir(notasContacto) : '',
      activo: true,
    });
  }
  insertar('contactos', docsContactos);

  // --- actividades ---------------------------------------------------------
  const nombresActividad = {
    turno: ['Turno de fiestas patronales', 'Turno navideño', 'Turno pro-cancha', 'Turno del Día del Niño'],
    feria: ['Feria del agricultor', 'Feria de emprendedores', 'Feria de comidas típicas', 'Feria escolar'],
    rifa: ['Rifa pro-techo del salón', 'Rifa de canasta navideña', 'Rifa pro-uniformes', 'Rifa de electrodomésticos'],
  };
  const actividades = { turno: [], feria: [], rifa: [] };
  const docsActividades = [];
  for (let i = 0; i < 60; i++) {
    const tipo = elegir(['turno', 'turno', 'feria', 'feria', 'rifa']);
    const fecha = entre(desde, hasta);
    fecha.setUTCHours(8, 0, 0, 0);
    const _id = new ObjectId();
    actividades[tipo].push({ _id, fecha });
    docsActividades.push({
      _id,
      nombre: `${elegir(nombresActividad[tipo])} ${fecha.getUTCFullYear()}`,
      tipo,
      fecha,
      estado: fecha < hoy ? 'finalizada' : 'programada',
    });
  }
  insertar('actividades', docsActividades);

  // --- reservas (sin repetir espacio + fecha + franja) ---------------------
  const docsReservas = [];
  const usadas = new Set();
  while (docsReservas.length < 400) {
    const e = elegir(espacios);
    const franja = elegir(Object.keys(e.tarifas));
    const fecha = entre(desde, hasta);
    fecha.setUTCHours(0, 0, 0, 0);
    const clave = `${e._id}${fecha.getTime()}${franja}`;
    if (usadas.has(clave)) continue;
    usadas.add(clave);
    const doc = {
      espacio_id: e._id,
      contacto_id: elegir(contactos),
      fecha,
      franja,
      estado: 'confirmado',
      monto: dec(e.tarifas[franja]),
    };
    // Solo las reservas futuras pueden seguir apartadas; vencen en 1 a 3 días.
    if (fecha > hoy && probabilidad(30)) {
      doc.estado = 'apartado';
      doc.vence = new Date(ahora.getTime() + entero(1, 3) * DIA);
    }
    docsReservas.push(doc);
  }
  insertar('reservas', docsReservas);

  // --- ventas (items embebidos) --------------------------------------------
  const productos = {
    'Tamal': 1500, 'Empanada': 800, 'Café': 600, 'Gallo de picadillo': 1000, 'Arroz con leche': 1000,
    'Refresco natural': 700, 'Chicharrón': 2500, 'Arroz con pollo': 3000, 'Copo': 1000, 'Elote': 800, 'Queque': 1200,
  };
  const conVentas = actividades.turno.concat(actividades.feria);
  let lote = [];
  let insertadas = 0;
  const inicio = Date.now();
  for (let i = 0; i < totalVentas; i++) {
    const act = elegir(conVentas);
    let total = 0;
    const items = variosDe(Object.keys(productos), entero(1, 4)).map(producto => {
      const cantidad = entero(1, 5);
      total += cantidad * productos[producto];
      return { producto, cantidad, precio: dec(productos[producto]) };
    });
    const doc = {
      actividad_id: act._id,
      items,
      total: dec(total),
      fecha: new Date(act.fecha.getTime() + entero(0, 10 * 3600) * 1000),
    };
    if (probabilidad(25)) doc.contacto_id = elegir(contactos);
    lote.push(doc);
    if (lote.length === LOTE) {
      db.ventas.insertMany(lote);
      insertadas += lote.length;
      lote = [];
      print(`  ventas: ${insertadas} / ${totalVentas}`);
    }
  }
  if (lote.length) {
    db.ventas.insertMany(lote);
    insertadas += lote.length;
  }
  print(`  ventas: ${insertadas} documentos (${((Date.now() - inicio) / 1000).toFixed(1)} s)`);

  // --- rifas (números del 00 al 99 sin repetir por rifa) -------------------
  // Cada rifa guarda su precio_numero (Issue #84): sin él, la pantalla de la rifa
  // no deja vender hasta que alguien la edite. Se guarda con un updateOne después
  // de elegir el precio, para no cambiar la secuencia del generador: con la misma
  // semilla, el resto de los datos sale idéntico a las cargas anteriores.
  const docsRifas = [];
  const cien = Array.from({ length: 100 }, (_, n) => n);
  for (const act of actividades.rifa) {
    const precio = elegir([500, 1000, 2000]);
    db.actividades.updateOne({ _id: act._id }, { $set: { precio_numero: dec(precio) } });
    for (const numero of variosDe(cien, entero(30, 90))) {
      docsRifas.push({
        rifa_id: act._id,
        numero,
        contacto_id: elegir(contactos),
        monto: dec(precio),
        fecha: entre(new Date(act.fecha.getTime() - 30 * DIA), act.fecha),
      });
    }
  }
  if (docsRifas.length) insertar('rifas', docsRifas);

  // --- seguimientos --------------------------------------------------------
  const notas = ['Llamar para confirmar patrocinio', 'Enviar recibo del alquiler', 'Invitar a la próxima feria',
    'Recordar pago pendiente', 'Coordinar donación', 'Agradecer colaboración en el turno'];
  const docsSeguimientos = [];
  for (let i = 0; i < 300; i++) {
    const fecha = entre(new Date(ahora.getTime() - 182 * DIA), new Date(ahora.getTime() + 61 * DIA));
    const doc = {
      contacto_id: elegir(contactos),
      tipo: 'nota',
      descripcion: elegir(notas),
      estado: fecha < ahora && probabilidad(80) ? 'completado' : 'pendiente',
      fecha_creacion: new Date(fecha.getTime() - entero(1, 30) * DIA),
    };
    // Los recordatorios llevan fecha de vencimiento; las notas no.
    if (probabilidad(60)) {
      doc.tipo = 'recordatorio';
      doc.fecha_vencimiento = fecha;
    }
    docsSeguimientos.push(doc);
  }
  insertar('seguimientos', docsSeguimientos);

  print('Listo.');
})();
