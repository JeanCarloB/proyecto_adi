# Changelog

Todos los cambios importantes de ADIministra se registran en este archivo.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y las versiones siguen los seguimientos del curso (`v0.1.0` = S1, `v0.2.0` = S2, `v0.3.0` = S3, `v0.9.0` = S4).

## [Sin publicar]

### Agregado
- `scripts/medir_indices.js`: mediciones con `explain()` de las consultas clave con y sin índice, 3 corridas y mediana (#49).
- `docs/mediciones.md`, `docs/pruebas.md` y `docs/instalacion.md` (#49, #50, #51).
- Procedimiento de despliegue del servidor en el README (#47).
- Este `CHANGELOG.md` (#46).
- Portada en `index.php` con el resumen de cada módulo y enlaces a sus pantallas (#45).

### Cambiado
- La verificación del entorno pasa de `index.php` a `diagnostico.php`, y vuelve a mostrar las 6 comprobaciones (el `index.php` de `main` terminaba en un mensaje de prueba).

## [0.2.0] - Seguimiento 2

### Agregado
- **Alquileres:** CRUD de espacios y reservas desde la interfaz; crear apartados, confirmar el pago y cancelar (PR #22; Issues #11, #12, #13).
- **Ventas:** CRUD de actividades, registro, edición, anulación y listado de ventas por actividad, venta de números de rifa sin repetir y total recaudado con aggregation pipeline (PR #39; Issues #31, #32, #33, #34).
- **Contactos:** CRUD de contactos desde la interfaz y desactivación de contactos (Issue #3; PR #26).
- **Datos de prueba:** `scripts/cargar_datos.php` (Faker) y `scripts/cargar_datos.js` (mongosh) cargan datos sintéticos con semilla fija, incluidas 100 000 ventas (PR #1, #24, #25; Issue #44).
- `scripts/crear_colecciones.js` y `scripts/crear_colecciones_ventas.js`: colecciones con JSON Schema e índices (PR #21, #39).
- Diseño compartido de la interfaz (`src/includes/layout.php`, `assets/css/adi.css`) y logo.

### Cambiado
- El DDL de las colecciones pasa de PHP a scripts de `mongosh`.
- Los includes y módulos se mueven a `src/`.

### Corregido
- Desactivar contactos que no tienen el campo `activo` (PR #26).

## [0.1.0] - Seguimiento 1

### Agregado
- Repositorio, estructura de carpetas, licencia MIT y README inicial con problema, alcance, arquitectura y equipo (PR #20; Issue #40).
- Clúster de MongoDB Atlas con la base `adi` y usuarios por integrante (Issue #41).
- Servidor Virtualmin con clon por deploy key, `.env` propio y `.htaccess` (Issue #42).
- `index.php` de verificación de entorno: PHP, extensión, Composer, `.env`, conexión y escritura.
- `.env.example` documentado (Issue #43).
- Diagramas de arquitectura y modelo de datos v1.
- Diseño de las colecciones con JSON Schema e índices único, TTL y compuesto (Issues #2, #7, #8, #9, #10, #27, #28, #29, #30).
