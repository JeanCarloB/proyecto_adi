
/**
 * Mediciones del módulo de contactos y seguimientos.
 *
 * Pruebas:
 * 1. Agregación global: todos los contactos + $lookup de seguimientos.
 * 2. Historial individual: un contacto que tenga al menos un seguimiento.
 *
 * Cada prueba realiza 3 ejecuciones y calcula medianas.
 * Solo consulta datos y planes de ejecución; no modifica documentos ni índices.
 *
 * Uso:
 *   Seleccionar la base adi en mongosh y ejecutar:
 *   load("scripts/medir_contactos.js")
 *
 * Requisitos:
 *   Colecciones contactos y seguimientos, con datos de prueba.
 */

(function () {
  const CORRIDAS = 3;

  function mediana(valores) {
    const ordenados = valores.slice().sort((a, b) => a - b);
    const mitad = Math.floor(ordenados.length / 2);

    return ordenados.length % 2
      ? ordenados[mitad]
      : (ordenados[mitad - 1] + ordenados[mitad]) / 2;
  }

  function numero(valor) {
    if (valor == null) return null;
    return typeof valor.toNumber === "function"
      ? valor.toNumber()
      : Number(valor);
  }

  function medirGlobal() {
    const resultados = [];

    print("\n=== PRUEBA 1: HISTORIAL GLOBAL ===");

    for (let i = 1; i <= CORRIDAS; i++) {
      const r = db.contactos
        .explain("executionStats")
        .aggregate([
          {
            $lookup: {
              from: "seguimientos",
              localField: "_id",
              foreignField: "contacto_id",
              as: "historial"
            }
          }
        ]);

      const ex = r.executionStats;
      const plan = r.queryPlanner?.winningPlan?.queryPlan;

      const fila = {
        ejecucion: i,
        tiempoTotalMs: numero(ex?.executionTimeMillis),
        contactosDevueltos: numero(ex?.nReturned),
        documentosExaminados: numero(ex?.totalDocsExamined),
        clavesExaminadas: numero(ex?.totalKeysExamined),
        estrategia: plan?.strategy ?? null,
        indiceLookup: plan?.indexName ?? null
      };

      resultados.push(fila);
      printjson(fila);
    }

    print("Medianas de la prueba global:");
    printjson({
      tiempoTotalMs: mediana(resultados.map(x => x.tiempoTotalMs)),
      documentosExaminados: mediana(
        resultados.map(x => x.documentosExaminados)
      ),
      clavesExaminadas: mediana(
        resultados.map(x => x.clavesExaminadas)
      ),
      contactosDevueltos: resultados.map(x => x.contactosDevueltos),
      indicesLookup: resultados.map(x => x.indiceLookup)
    });
  }

  function medirIndividual() {
    print("\n=== PRUEBA 2: HISTORIAL INDIVIDUAL ===");

    const seguimiento = db.seguimientos.findOne(
      { contacto_id: { $exists: true, $ne: null } },
      { contacto_id: 1 }
    );

    if (!seguimiento) {
      print("No hay seguimientos asociados a contactos.");
      return;
    }

    const idContacto = seguimiento.contacto_id;
    const resultados = [];

    for (let i = 1; i <= CORRIDAS; i++) {
      const r = db.contactos
        .explain("executionStats")
        .aggregate([
          { $match: { _id: idContacto } },
          {
            $lookup: {
              from: "seguimientos",
              localField: "_id",
              foreignField: "contacto_id",
              as: "historial"
            }
          }
        ]);

      const cursor = r.stages?.find(s => s.$cursor);
      const lookup = r.stages?.find(s => s.$lookup);

      const fila = {
        ejecucion: i,
        tiempoCursorEstimadoMs: numero(
          cursor?.executionTimeMillisEstimate
        ),
        tiempoLookupEstimadoMs: numero(
          lookup?.executionTimeMillisEstimate
        ),
        documentosExaminados: numero(lookup?.totalDocsExamined),
        clavesExaminadas: numero(lookup?.totalKeysExamined),
        recorridosCompletos: numero(lookup?.collectionScans),
        contactosDevueltos: numero(lookup?.nReturned),
        indiceLookup: lookup?.indexesUsed ?? []
      };

      resultados.push(fila);
      printjson(fila);
    }

    print("Medianas de la prueba individual:");
    printjson({
      tiempoLookupEstimadoMs: mediana(
        resultados.map(x => x.tiempoLookupEstimadoMs)
      ),
      documentosExaminados: mediana(
        resultados.map(x => x.documentosExaminados)
      ),
      clavesExaminadas: mediana(
        resultados.map(x => x.clavesExaminadas)
      ),
      recorridosCompletos: mediana(
        resultados.map(x => x.recorridosCompletos)
      ),
      indicesLookup: resultados.map(x => x.indiceLookup)
    });
  }

  print("Base de datos: " + db.getName());
  print("Corridas por prueba: " + CORRIDAS);

  medirGlobal();
  medirIndividual();

  print("\nMediciones finalizadas.");
})();

/**
 * Mediciones del módulo de contactos y seguimientos.
 *
 * Pruebas:
 * 1. Agregación global: todos los contactos + $lookup de seguimientos.
 * 2. Historial individual: un contacto que tenga al menos un seguimiento.
 *
 * Cada prueba realiza 3 ejecuciones y calcula medianas.
 * Solo consulta datos y planes de ejecución; no modifica documentos ni índices.
 *
 * Uso:
 *   Seleccionar la base adi en mongosh y ejecutar:
 *   load("scripts/medir_contactos.js")
 *
 * Requisitos:
 *   Colecciones contactos y seguimientos, con datos de prueba.
 */

(function () {
  const CORRIDAS = 3;

  function mediana(valores) {
    const ordenados = valores.slice().sort((a, b) => a - b);
    const mitad = Math.floor(ordenados.length / 2);

    return ordenados.length % 2
      ? ordenados[mitad]
      : (ordenados[mitad - 1] + ordenados[mitad]) / 2;
  }

  function numero(valor) {
    if (valor == null) return null;
    return typeof valor.toNumber === "function"
      ? valor.toNumber()
      : Number(valor);
  }

  function medirGlobal() {
    const resultados = [];

    print("\n=== PRUEBA 1: HISTORIAL GLOBAL ===");

    for (let i = 1; i <= CORRIDAS; i++) {
      const r = db.contactos
        .explain("executionStats")
        .aggregate([
          {
            $lookup: {
              from: "seguimientos",
              localField: "_id",
              foreignField: "contacto_id",
              as: "historial"
            }
          }
        ]);

      const ex = r.executionStats;
      const plan = r.queryPlanner?.winningPlan?.queryPlan;

      const fila = {
        ejecucion: i,
        tiempoTotalMs: numero(ex?.executionTimeMillis),
        contactosDevueltos: numero(ex?.nReturned),
        documentosExaminados: numero(ex?.totalDocsExamined),
        clavesExaminadas: numero(ex?.totalKeysExamined),
        estrategia: plan?.strategy ?? null,
        indiceLookup: plan?.indexName ?? null
      };

      resultados.push(fila);
      printjson(fila);
    }

    print("Medianas de la prueba global:");
    printjson({
      tiempoTotalMs: mediana(resultados.map(x => x.tiempoTotalMs)),
      documentosExaminados: mediana(
        resultados.map(x => x.documentosExaminados)
      ),
      clavesExaminadas: mediana(
        resultados.map(x => x.clavesExaminadas)
      ),
      contactosDevueltos: resultados.map(x => x.contactosDevueltos),
      indicesLookup: resultados.map(x => x.indiceLookup)
    });
  }

  function medirIndividual() {
    print("\n=== PRUEBA 2: HISTORIAL INDIVIDUAL ===");

    const seguimiento = db.seguimientos.findOne(
      { contacto_id: { $exists: true, $ne: null } },
      { contacto_id: 1 }
    );

    if (!seguimiento) {
      print("No hay seguimientos asociados a contactos.");
      return;
    }

    const idContacto = seguimiento.contacto_id;
    const resultados = [];

    for (let i = 1; i <= CORRIDAS; i++) {
      const r = db.contactos
        .explain("executionStats")
        .aggregate([
          { $match: { _id: idContacto } },
          {
            $lookup: {
              from: "seguimientos",
              localField: "_id",
              foreignField: "contacto_id",
              as: "historial"
            }
          }
        ]);

      const cursor = r.stages?.find(s => s.$cursor);
      const lookup = r.stages?.find(s => s.$lookup);

      const fila = {
        ejecucion: i,
        tiempoCursorEstimadoMs: numero(
          cursor?.executionTimeMillisEstimate
        ),
        tiempoLookupEstimadoMs: numero(
          lookup?.executionTimeMillisEstimate
        ),
        documentosExaminados: numero(lookup?.totalDocsExamined),
        clavesExaminadas: numero(lookup?.totalKeysExamined),
        recorridosCompletos: numero(lookup?.collectionScans),
        contactosDevueltos: numero(lookup?.nReturned),
        indiceLookup: lookup?.indexesUsed ?? []
      };

      resultados.push(fila);
      printjson(fila);
    }

    print("Medianas de la prueba individual:");
    printjson({
      tiempoLookupEstimadoMs: mediana(
        resultados.map(x => x.tiempoLookupEstimadoMs)
      ),
      documentosExaminados: mediana(
        resultados.map(x => x.documentosExaminados)
      ),
      clavesExaminadas: mediana(
        resultados.map(x => x.clavesExaminadas)
      ),
      recorridosCompletos: mediana(
        resultados.map(x => x.recorridosCompletos)
      ),
      indicesLookup: resultados.map(x => x.indiceLookup)
    });
  }

  print("Base de datos: " + db.getName());
  print("Corridas por prueba: " + CORRIDAS);

  medirGlobal();
  medirIndividual();

  print("\nMediciones finalizadas.");
})();
