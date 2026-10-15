# scripts/

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
