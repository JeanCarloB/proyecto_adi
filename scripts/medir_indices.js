// Mediciones con explain(): cada consulta clave con y sin índice.
//
// Corre cada consulta 3 veces usando su índice y 3 veces forzando un
// recorrido completo de la colección (hint {$natural: 1}, es decir COLLSCAN),
// y reporta la mediana de las 3 corridas. No crea ni borra índices ni
// modifica datos, así que se puede correr sobre la base compartida.
//
// Uso (la base va en la URI, normalmente adi_prueba con los datos de prueba):
//   mongosh "<URI personal de Atlas>/adi_prueba" --file scripts/medir_indices.js
//
// Dentro de mongosh:
//   use adi_prueba
//   CORRIDAS = 3; load("scripts/medir_indices.js")
//
// Requisitos: datos cargados (cargar_datos.js o cargar_datos.php) y los
// índices del diagrama (crear_colecciones*.js o la opción de índices de la
// carga). La salida final es una tabla en Markdown para docs/mediciones.md.

(function () {
  const corridas = typeof CORRIDAS !== 'undefined' ? CORRIDAS : 3;
  const SIN_INDICE = { $natural: 1 };

  const mediana = valores => {
    const v = valores.slice().sort((a, b) => a - b);
    const m = Math.floor(v.length / 2);
    return v.length % 2 ? v[m] : (v[m - 1] + v[m]) / 2;
  };

  // Recorre el árbol del plan ganador y devuelve sus etapas (IXSCAN, COLLSCAN...).
  const etapas = plan => {
    const lista = [];
    const visitar = p => {
      if (!p) return;
      if (p.stage) lista.push(p.stage);
      visitar(p.inputStage);
      (p.inputStages || []).forEach(visitar);
      visitar(p.queryPlan);
    };
    visitar(plan);
    return [...new Set(lista)].join(' → ');
  };

  // En aggregate el executionStats puede venir dentro de stages[0].$cursor.
  const stats = salida => {
    if (salida.executionStats) return { ex: salida.executionStats, plan: salida.queryPlanner.winningPlan };
    const cursor = salida.stages && salida.stages[0].$cursor;
    return { ex: cursor.executionStats, plan: cursor.queryPlanner.winningPlan };
  };

  const medir = (nombre, ejecutar) => {
    const fila = { nombre };
    for (const [modo, hint] of [['con', null], ['sin', SIN_INDICE]]) {
      const tiempos = [], docs = [], llaves = [];
      let plan = '';
      for (let i = 0; i < corridas; i++) {
        const { ex, plan: p } = stats(ejecutar(hint));
        tiempos.push(ex.executionTimeMillis);
        docs.push(ex.totalDocsExamined);
        llaves.push(ex.totalKeysExamined);
        plan = etapas(p);
      }
      fila[modo] = { ms: mediana(tiempos), docs: mediana(docs), llaves: mediana(llaves), plan, tiempos };
      print(`  ${nombre} · ${modo} índice: ${tiempos.join(', ')} ms → mediana ${fila[modo].ms} ms (${plan})`);
    }
    return fila;
  };

  print(`Base ${db.getName()} · ${db.ventas.estimatedDocumentCount()} ventas · ${corridas} corridas por caso`);

  // Datos de ejemplo para las consultas: una actividad con ventas, una reserva y un número de rifa.
  const venta = db.ventas.findOne({}, { actividad_id: 1 });
  const reserva = db.reservas.findOne({}, { espacio_id: 1, fecha: 1, franja: 1 });
  const rifa = db.rifas.findOne({}, { rifa_id: 1, numero: 1 });
  if (!venta || !reserva || !rifa) {
    print('Faltan datos: cargue primero scripts/cargar_datos.js en esta base.');
    return;
  }
  const actividad = venta.actividad_id;
  const primera = db.ventas.find({ actividad_id: actividad }).sort({ fecha: 1 }).limit(1).toArray()[0].fecha;
  const finDia = new Date(primera.getTime() + 24 * 3600 * 1000);
  const opciones = hint => (hint ? { hint } : {});

  const filas = [
    medir('Total recaudado por actividad (aggregate)', hint =>
      db.ventas.explain('executionStats').aggregate([
        { $match: { actividad_id: actividad } },
        { $group: { _id: '$actividad_id', n: { $sum: 1 }, total: { $sum: '$total' } } },
      ], opciones(hint))),
    medir('Ventas de una actividad en un día (find + sort)', hint => {
      const c = db.ventas.find({ actividad_id: actividad, fecha: { $gte: primera, $lt: finDia } }).sort({ fecha: 1 });
      return (hint ? c.hint(hint) : c).explain('executionStats');
    }),
    medir('Disponibilidad de espacio, fecha y franja (reservas)', hint => {
      const c = db.reservas.find({ espacio_id: reserva.espacio_id, fecha: reserva.fecha, franja: reserva.franja });
      return (hint ? c.hint(hint) : c).explain('executionStats');
    }),
    medir('Número de rifa ya vendido (rifas)', hint => {
      const c = db.rifas.find({ rifa_id: rifa.rifa_id, numero: rifa.numero });
      return (hint ? c.hint(hint) : c).explain('executionStats');
    }),
  ];

  print('\n| Consulta | Con índice: mediana ms | Docs examinados | Plan | Sin índice: mediana ms | Docs examinados | Plan |');
  print('| --- | ---: | ---: | --- | ---: | ---: | --- |');
  filas.forEach(f => print(
    `| ${f.nombre} | ${f.con.ms} | ${f.con.docs} | ${f.con.plan} | ${f.sin.ms} | ${f.sin.docs} | ${f.sin.plan} |`
  ));
  print(`\nCorridas individuales (ms), fecha ${new Date().toISOString().slice(0, 10)}:`);
  filas.forEach(f => print(`- ${f.nombre}: con índice ${f.con.tiempos.join(' / ')}; sin índice ${f.sin.tiempos.join(' / ')}`));
})();
