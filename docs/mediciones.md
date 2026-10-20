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
