# Changelog

Todos los cambios importantes de ADIministra se registran en este archivo.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y las versiones siguen los seguimientos del curso (`v0.1.0` = S1, `v0.2.0` = S2, `v0.3.0` = S3, `v0.9.0` = S4).

## [Sin publicar]

### Agregado
- **Seguridad:** inicio y cierre de sesión (`login.php`, `logout.php`) contra la colección `usuarios` con `password_verify`, y permisos por rol en `src/includes/sesion.php`: todas las páginas exigen sesión y solo el rol `admin` puede crear, editar, anular o borrar; el rol `comunidad` solo consulta.
- `scripts/crear_usuarios_prueba.php`: usuarios ficticios `admin@example.com` y `comunidad@example.com` para probar el login.

## [0.2.0] - Seguimiento 2

### Agregado
- **Alquileres:** CRUD de espacios y reservas desde la interfaz; crear apartados, confirmar el pago y cancelar (PR #22; Issues #11, #12, #13).
- **Ventas:** CRUD de actividades, registro, edición, anulación y listado de ventas por actividad, venta de números de rifa sin repetir y total recaudado con aggregation pipeline (PR #39; Issues #31, #32, #33, #34).
- **Contactos:** CRUD de contactos desde la interfaz y desactivación de contactos (Issue #3; PR #26).
- **Datos de prueba:** `scripts/cargar_datos.php` (Faker) y `scripts/cargar_datos.js` (mongosh) cargan datos sintéticos con semilla fija, incluidas 100 000 ventas (PR #1, #24, #25; Issue #44).
- `scripts/crear_colecciones.js`, `scripts/crear_colecciones_ventas.js` y `scripts/crear_colecciones_contactos.js`: colecciones con JSON Schema e índices (PR #21, #39; Issue #2).
- **Seguimientos:** CRUD de notas y recordatorios con filtro por estado (PR #65, #68; Issues #3, #4).
- Diseño compartido de la interfaz (`src/includes/layout.php`, `assets/css/adi.css`) y logo.
- **Alquileres:** calendario mensual de ocupación, edición de reservas y paginación de la lista (PR #23, #62; Issue #14).
- Portada en `index.php` con el resumen de cada módulo y enlaces a sus pantallas (PR #52; Issue #45).
- Procedimiento de despliegue del servidor en el README (Issue #47) y este `CHANGELOG.md` (Issue #46).
- Bitácoras `docs/seguimientos/S1.md` y `S2.md` (Issues #48, #53; PR #63).
- Adelantos del S3: `scripts/medir_indices.js` con mediciones `explain()` con y sin índice, 3 corridas y mediana, y `docs/mediciones.md`, `docs/pruebas.md` y `docs/instalacion.md` (Issues #49, #50, #51).

### Cambiado
- El DDL de las colecciones pasa de PHP a scripts de `mongosh`.
- Los includes y módulos se mueven a `src/`.
- La verificación del entorno pasa de `index.php` a `diagnostico.php`, con las 6 comprobaciones.

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
