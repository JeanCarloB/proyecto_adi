# Mediciones con y sin índice

Comparación experimental del Perfil A: cada consulta clave se ejecuta con su índice y forzando un recorrido completo de la colección, **3 corridas por caso**, y se reporta la **mediana**.

## Cómo se mide

```bash
mongosh "<URI personal de Atlas>/adi_prueba" --file scripts/medir_indices.js
```

- **Con índice:** el planificador elige el índice del diagrama (`IXSCAN`).
- **Sin índice:** se fuerza `hint({$natural: 1})`, que obliga a un `COLLSCAN`. Así se mide sin borrar índices, lo que es seguro en la base compartida y no rompe las reglas de unicidad.
- Se toma `executionTimeMillis`, `totalDocsExamined` y las etapas del plan ganador de `explain("executionStats")`.
- Datos: los de `scripts/cargar_datos.js` (semilla 2026): 100 000 ventas, 400 reservas y ~900 números de rifa.

| Consulta | Colección | Índice que usa |
| --- | --- | --- |
| Total recaudado por actividad (aggregate `$match` + `$group`) | `ventas` | `{actividad_id: 1, fecha: 1}` |
| Ventas de una actividad en un día, ordenadas por fecha | `ventas` | `{actividad_id: 1, fecha: 1}` |
| Disponibilidad de espacio, fecha y franja | `reservas` | único `{espacio_id, fecha, franja}` |
| ¿El número de rifa ya se vendió? | `rifas` | único `{rifa_id, numero}` |

## Resultados en Atlas

Corrida del 2026-10-20 desde el servidor del curso contra Atlas M0 (AWS Oregon), base `adi_prueba` con 100 000 ventas.

| Consulta | Con índice: mediana ms | Docs examinados | Plan | Sin índice: mediana ms | Docs examinados | Plan |
| --- | ---: | ---: | --- | ---: | ---: | --- |
| Total recaudado por actividad (aggregate) | 27 | 1 973 | GROUP → FETCH → IXSCAN | 269 | 100 000 | GROUP → COLLSCAN |
| Ventas de una actividad en un día (find + sort) | 7 | 1 973 | FETCH → IXSCAN | 324 | 100 000 | SORT → COLLSCAN |
| Disponibilidad de espacio, fecha y franja | 0 | 1 | FETCH → IXSCAN | 0 | 400 | COLLSCAN |
| Número de rifa ya vendido | 0 | 1 | FETCH → IXSCAN | 0 | 420 | COLLSCAN |

Corridas individuales (ms):

| Consulta | Con índice | Sin índice |
| --- | --- | --- |
| Total recaudado por actividad | 18 / 29 / 27 | 269 / 174 / 271 |
| Ventas de una actividad en un día | 4 / 7 / 19 | 360 / 324 / 82 |
| Disponibilidad de espacio, fecha y franja | 0 / 0 / 0 | 0 / 0 / 0 |
| Número de rifa ya vendido | 0 / 0 / 0 | 0 / 0 / 0 |

## Corrida de referencia (MongoDB 7 local, 2026-10-19)

Sirve para validar el script; los tiempos de Atlas M0 serán mayores por la red y el hardware compartido.

| Consulta | Con índice: mediana ms | Docs examinados | Plan | Sin índice: mediana ms | Docs examinados | Plan |
| --- | ---: | ---: | --- | ---: | ---: | --- |
| Total recaudado por actividad (aggregate) | 6 | 1 973 | FETCH → IXSCAN | 41 | 100 000 | COLLSCAN |
| Ventas de una actividad en un día (find + sort) | 6 | 1 973 | FETCH → IXSCAN | 40 | 100 000 | SORT → COLLSCAN |
| Disponibilidad de espacio, fecha y franja | 0 | 1 | FETCH → IXSCAN | 0 | 400 | COLLSCAN |
| Número de rifa ya vendido | 0 | 1 | FETCH → IXSCAN | 0 | 420 | COLLSCAN |

## Lectura de los resultados

- En `ventas` (100 000 documentos) el índice compuesto `{actividad_id, fecha}` reduce los documentos examinados de 100 000 a los de una sola actividad (1 973). En Atlas el tiempo baja unas **10 veces** en el total por actividad (269 → 27 ms) y unas **46 veces** en las ventas de un día (324 → 7 ms). Sin índice, además, la consulta ordenada necesita una etapa `SORT` en memoria.
- Las corridas en Atlas varían más que en local (por ejemplo, 82 a 360 ms sin índice) por la red y el hardware compartido del M0; por eso se reporta la mediana. Aun la corrida más rápida sin índice es más lenta que la más lenta con índice.
- En `reservas` y `rifas` las colecciones son pequeñas y el tiempo es casi 0 en ambos casos, pero el índice pasa de examinar todos los documentos a examinar **1**. Además, ese índice es **único**: es el que impide el doble alquiler y la rifa repetida, así que no es opcional.

---

## Módulo de alquileres (Issue #17)

Mide cuatro consultas que usan las pantallas de alquileres, con su índice y sin índice (`hint({$natural: 1})`). Cada caso se corre 3 veces y se reporta la mediana. Es complementaria a la medición general de arriba: agrega la consulta **cubierta** del calendario público, el **`$lookup`** del calendario de la junta y la bandeja de solicitudes.

```powershell
mongosh "<URI personal de Atlas>" --file scripts/medir_alquileres.js
```

**Corrida en Atlas M0 Oregon, 2026-10-22:** 389 reservas, 502 contactos y 2 solicitudes. Mes medido: septiembre de 2026, el que más reservas tiene.

| Consulta | Pantalla | Índice | Con índice: mediana ms | Docs leídos | Claves leídas | Plan | Sin índice: mediana ms | Docs leídos | Plan |
| --- | --- | --- | ---: | ---: | ---: | --- | ---: | ---: | --- |
| Reservas del mes con proyección `{_id: 0, espacio_id, fecha, franja}` | Calendario público | `{espacio_id, fecha, franja}` | 0 | **0** | 38 | PROJECTION_COVERED → IXSCAN | 0 | 389 | PROJECTION_SIMPLE → COLLSCAN |
| ¿Está libre este espacio, día y franja? (horario libre) | Apartar / solicitar | único `{espacio_id, fecha, franja}` | 0 | **0** | 0 | LIMIT → FETCH → IXSCAN | 0 | 389 | LIMIT → COLLSCAN |
| `$match` del mes + `$lookup` a contactos + `$unwind` + `$project` | Calendario de la junta | `{espacio_id, fecha, franja}` en el `$match` | 2 | **33** | 38 | $lookup → PROJECTION_SIMPLE → FETCH → IXSCAN | 2 | 389 | $lookup → PROJECTION_SIMPLE → COLLSCAN |
| Solicitudes pendientes ordenadas por `creada` | Bandeja de la junta | `{estado, creada}` | 0 | **0** | 0 | FETCH → IXSCAN | 0 | 2 | **SORT** → COLLSCAN |

### Lectura

- **Calendario público, consulta cubierta.** Con el índice, MongoDB responde **solo con el índice**, sin abrir ningún documento: `totalDocsExamined = 0` y la etapa `PROJECTION_COVERED`. Esto pasa porque la página pide únicamente `espacio_id`, `fecha` y `franja`, que son los tres campos del índice, y excluye `_id`. Así, la pantalla pública ni siquiera lee los documentos que tienen el contacto o el monto. Sin índice se leen las 389 reservas para devolver 33.
- **El `$in` sobre `espacio_id` es lo que permite usar el índice.** El calendario filtra "todos los espacios" con `espacio_id: {$in: [...]}` más el rango de fechas. Filtrar solo por `fecha` no podría usar `{espacio_id, fecha, franja}`, porque `fecha` no es el primer campo, y terminaría en `COLLSCAN`. Por eso las claves leídas (38) son algunas más que los documentos (33): el índice recorre un rango por cada espacio.
- **Disponibilidad de un horario libre.** Es la pregunta que se hace antes de apartar o al pedir una reserva, y lo normal es que la respuesta sea "no hay nada". Sin índice, para confirmar que no existe hay que revisar **todas** las reservas; con el índice basta con mirar el lugar donde estaría (0 claves, 0 documentos). Además, ese índice es **único**: es el que impide el doble alquiler (prueba P4 de `docs/pruebas.md`).
- **`$lookup` del calendario de la junta.** El índice reduce lo que entra al pipeline de 389 a 33 reservas. El `$lookup` busca cada contacto por `_id`, que siempre tiene índice. Sin índice en el `$match`, el `$lookup` trabajaría sobre todo lo que se haya leído antes de filtrar.
- **Bandeja de solicitudes.** Con `{estado, creada}` las pendientes ya salen ordenadas de la más antigua a la más nueva. Sin índice aparece una etapa **`SORT` en memoria**, que crece con la cantidad de solicitudes.
- **Sobre los tiempos.** Con 389 reservas todo responde en 0–2 ms con índice y sin índice: la colección cabe completa en memoria. La diferencia que sí se ve es cuántos documentos se leen, y el tiempo crece con esa cantidad. Es lo mismo que muestra la medición de `ventas` de arriba, donde con 100 000 documentos el índice baja el tiempo entre 10 y 46 veces. Una ADI real acumula reservas año tras año, así que el `COLLSCAN` se vuelve más caro con el tiempo, mientras que con el índice se lee siempre solo el mes pedido.

