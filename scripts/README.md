# scripts/

## Orden de ejecución

1. `crear_colecciones.js` crea las colecciones `espacios` y `reservas` con su validación JSON Schema y sus índices. No inserta datos y se puede correr las veces que haga falta.
2. `cargar_datos.js` (mongosh) o `cargar_datos.php` (PHP) llenan la base con datos sintéticos. Solo hace falta uno de los dos.

```bash
mongosh "<URI personal de Atlas>" --file scripts/crear_colecciones.js
mongosh "<URI personal de Atlas>/adi_prueba" --file scripts/cargar_datos.js
```

`crear_colecciones.js` siempre trabaja sobre la base `adi`, sin importar la base que lleve la URI. Si las pruebas se hacen en `adi_prueba`, ahí no se aplican las reglas de JSON Schema. La base `adi` es compartida y ya tiene datos de los compañeros: no cargue datos ni limpie colecciones en `adi` sin avisar al equipo.


## cargar_datos.php

Carga datos de prueba sintéticos (generados con Faker, sin datos personales reales) en todas las colecciones del diagrama: espacios, contactos, actividades, reservas, ventas, rifas y seguimientos.

```bash
composer install                      # instala Faker (dependencia de desarrollo)
cp .env.example .env                  # y complete MONGODB_URI / MONGODB_DB
php scripts/cargar_datos.php --limpiar --indices
```

| Opción | Efecto |
|---|---|
| `--ventas=N` | Cantidad de ventas (por defecto 100000) |
| `--semilla=N` | Semilla de Faker (por defecto 2026); la misma semilla da los mismos datos |
| `--lote=N` | Documentos por `insertMany` (por defecto 5000) |
| `--limpiar` | Vacía las colecciones antes de cargar; conserva índices y JSON Schema |
| `--indices` | Crea los índices del diagrama (único en reservas y rifas, TTL en `vence`, compuesto en ventas) |

Sin `--limpiar`, el script se detiene si alguna colección ya tiene datos, para no duplicarlos.

Cantidades: 3 espacios, 500 contactos, 60 actividades, 400 reservas, 100 000 ventas, ~900 números de rifa y 300 seguimientos. Los montos se guardan como `Decimal128` y las fechas como `UTCDateTime`. Las fechas van de 12 meses atrás a 3 meses adelante del día en que se ejecuta; las reservas apartadas vencen de 1 a 3 días después de la carga.

El script solo corre por consola (`php` CLI) y la carpeta `scripts/` está bloqueada en Apache por `.htaccess`.

## cargar_datos.js

Versión para `mongosh` de `cargar_datos.php`. Genera las mismas colecciones y cantidades sin necesitar PHP ni Composer. Carga en la base de la conexión actual.

```bash
mongosh "<URI personal de Atlas>/adi_prueba" --file scripts/cargar_datos.js
```

Dentro de `mongosh`, las opciones se definen antes de `load()`:

```js
use adi_prueba
VENTAS = 1000; LIMPIAR = true; INDICES = true
load("scripts/cargar_datos.js")
```

| Opción | Efecto |
|---|---|
| `VENTAS` | Cantidad de ventas (por defecto 100000) |
| `SEMILLA` | Semilla del generador (por defecto 2026); la misma semilla da los mismos datos |
| `LIMPIAR` | `true` vacía las colecciones antes de cargar; conserva índices y JSON Schema. **Por defecto `true`** |
| `INDICES` | `true` crea los índices del diagrama si no existen. Por defecto `true` |

A diferencia del PHP, `LIMPIAR` está activado por defecto: si no se cambia, el script borra los datos de la base de la conexión antes de cargar. Revise que la URI apunte a `adi_prueba` y no a `adi`. Con `LIMPIAR = false`, el script se detiene si alguna colección ya tiene datos.
