/**
 * Mediciones del módulo de ventas con explain("executionStats") (Issues #36 y #82).
 *
 * Mide tres consultas reales de las pantallas del módulo, cada una CON su
 * índice y SIN índice (forzando un recorrido completo con hint({$natural: 1})),
 * 3 corridas por caso, y reporta la mediana. No crea ni borra índices ni
 * modifica datos: es seguro en la base compartida.
 *
 *   1. Listado de ventas de la junta (src/ventas/index.php) sin filtro:
 *      $match + $sort {fecha: -1} + $limit 200 + $lookup a actividades y a
 *      contactos. Usa el índice {fecha: -1}.
 *   2. Productos más vendidos de una actividad (src/actividades/ver.php):
 *      $match + $unwind de items + $group + $sort. Usa {actividad_id, fecha}.
 *   3. Cuadrícula de la rifa para la comunidad (src/actividades/ver.php):
 *      find {rifa_id} con proyección {_id: 0, numero: 1}. Con el índice único
 *      {rifa_id, numero} es una consulta CUBIERTA: 0 documentos leídos.
 *
 * Complementa a medir_indices.js (total recaudado y ventas de un día), que
 * no se repite aquí.
 *
 * Uso (la base va en la URI, normalmente adi_prueba con los datos de prueba):
 *   mongosh "<URI personal de Atlas>/adi_prueba" --file scripts/medir_ventas.js
 *
 * Dentro de mongosh:
 *   use adi_prueba
 *   CORRIDAS = 5; load("scripts/medir_ventas.js")
 *
 * Requisitos: datos cargados (cargar_datos.js o cargar_datos.php) y los índices
 * del módulo (crear_colecciones_ventas.js, o la opción de índices de la carga).
 * Imprime una tabla en Markdown lista para pegar en docs/mediciones.md.
 */

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

  // En aggregate el executionStats puede venir arriba o dentro de stages[0].$cursor.
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

  // --- Revisión previa: datos e índices ---------------------------------------

  const nVentas = db.ventas.estimatedDocumentCount();
  print(`Base ${db.getName()} · ${nVentas} ventas · ${db.rifas.estimatedDocumentCount()} números de rifa · ${corridas} corridas por caso`);

  const tieneIndice = (coleccion, clave) =>
    db.getCollection(coleccion).getIndexes().some(i => JSON.stringify(i.key) === JSON.stringify(clave));
  const faltan = [
    ['ventas', { fecha: -1 }],
    ['ventas', { actividad_id: 1, fecha: 1 }],
    ['rifas', { rifa_id: 1, numero: 1 }],
  ].filter(([c, k]) => !tieneIndice(c, k));
  if (faltan.length) {
    faltan.forEach(([c, k]) => print(`Falta el índice ${JSON.stringify(k)} en ${c}.`));
    print('Créelos con scripts/crear_colecciones_ventas.js (base adi) o con la opción de índices de la carga, y vuelva a medir.');
    return;
  }

  // La actividad con más ventas y la rifa con más números vendidos, para que las consultas devuelvan algo
  const top = (coleccion, campo) => db.getCollection(coleccion).aggregate([
    { $group: { _id: '$' + campo, n: { $sum: 1 } } }, { $sort: { n: -1 } }, { $limit: 1 },
  ]).toArray()[0];
  const actividad = top('ventas', 'actividad_id');
  const rifa = top('rifas', 'rifa_id');
  if (!actividad || !rifa) {
    print('Faltan datos: cargue primero scripts/cargar_datos.js en esta base.');
    return;
  }
  print(`Actividad medida: ${actividad._id} (${actividad.n} ventas) · rifa medida: ${rifa._id} (${rifa.n} números)\n`);

  const opciones = hint => (hint ? { hint } : {});

  // Mismo pipeline que src/ventas/index.php sin filtros
  const pipelineListado = [
    { $match: {} },
    { $sort: { fecha: -1 } },
    { $limit: 200 },
    { $lookup: { from: 'actividades', localField: 'actividad_id', foreignField: '_id', as: 'actividad' } },
    { $unwind: { path: '$actividad', preserveNullAndEmptyArrays: true } },
    { $lookup: { from: 'contactos', localField: 'contacto_id', foreignField: '_id', as: 'contacto' } },
    { $unwind: { path: '$contacto', preserveNullAndEmptyArrays: true } },
    { $project: { fecha: 1, items: 1, total: 1, actividad_id: 1, 'actividad.nombre': 1, 'actividad.estado': 1, 'contacto.nombre': 1 } },
  ];

  // Mismo pipeline que src/actividades/ver.php para turnos y ferias
  const pipelineProductos = [
    { $match: { actividad_id: actividad._id } },
    { $unwind: '$items' },
    { $group: {
      _id: '$items.producto',
      unidades: { $sum: '$items.cantidad' },
      monto: { $sum: { $multiply: ['$items.cantidad', '$items.precio'] } },
    } },
    { $sort: { monto: -1 } },
    { $limit: 10 },
  ];

  const filas = [
    medir('Listado de ventas de la junta ($sort + $limit + 2 $lookup)', hint =>
      db.ventas.explain('executionStats').aggregate(pipelineListado, opciones(hint))),
    medir('Productos más vendidos de una actividad ($unwind + $group)', hint =>
      db.ventas.explain('executionStats').aggregate(pipelineProductos, opciones(hint))),
    medir('Cuadrícula de la rifa para la comunidad (proyección {_id: 0, numero: 1})', hint => {
      const c = db.rifas.find({ rifa_id: rifa._id }, { _id: 0, numero: 1 }).sort({ numero: 1 });
      return (hint ? c.hint(hint) : c).explain('executionStats');
    }),
  ];

  print('\n| Consulta | Con índice: mediana ms | Docs leídos | Claves leídas | Plan | Sin índice: mediana ms | Docs leídos | Plan |');
  print('| --- | ---: | ---: | ---: | --- | ---: | ---: | --- |');
  filas.forEach(f => print(
    `| ${f.nombre} | ${f.con.ms} | ${f.con.docs} | ${f.con.llaves} | ${f.con.plan} | ${f.sin.ms} | ${f.sin.docs} | ${f.sin.plan} |`
  ));
  print(`\nCorridas individuales (ms), fecha ${new Date().toISOString().slice(0, 10)}:`);
  filas.forEach(f => print(`- ${f.nombre}: con índice ${f.con.tiempos.join(' / ')}; sin índice ${f.sin.tiempos.join(' / ')}`));
})();
