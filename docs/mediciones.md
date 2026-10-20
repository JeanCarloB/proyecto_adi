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

_(Pegar aquí la tabla que imprime el script al correrlo contra el clúster, con la fecha.)_

## Corrida de referencia (MongoDB 7 local, 2026-10-19)

Sirve para validar el script; los tiempos de Atlas M0 serán mayores por la red y el hardware compartido.

| Consulta | Con índice: mediana ms | Docs examinados | Plan | Sin índice: mediana ms | Docs examinados | Plan |
| --- | ---: | ---: | --- | ---: | ---: | --- |
| Total recaudado por actividad (aggregate) | 6 | 1 973 | FETCH → IXSCAN | 41 | 100 000 | COLLSCAN |
| Ventas de una actividad en un día (find + sort) | 6 | 1 973 | FETCH → IXSCAN | 40 | 100 000 | SORT → COLLSCAN |
| Disponibilidad de espacio, fecha y franja | 0 | 1 | FETCH → IXSCAN | 0 | 400 | COLLSCAN |
| Número de rifa ya vendido | 0 | 1 | FETCH → IXSCAN | 0 | 420 | COLLSCAN |

## Lectura de los resultados

- En `ventas` (100 000 documentos) el índice compuesto reduce los documentos examinados de 100 000 a los de una sola actividad (~2 000) y el tiempo unas 7 veces. Sin índice, además, la consulta ordenada necesita una etapa `SORT` en memoria.
- En `reservas` y `rifas` las colecciones son pequeñas y el tiempo es casi 0 en ambos casos, pero el índice pasa de examinar todos los documentos a examinar **1**. Además, ese índice es **único**: es el que impide el doble alquiler y la rifa repetida, así que no es opcional.
