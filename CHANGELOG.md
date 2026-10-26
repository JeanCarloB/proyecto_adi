# Changelog

Todos los cambios importantes de ADIministra se registran en este archivo.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y las versiones siguen los seguimientos del curso (`v0.1.0` = S1, `v0.2.0` = S2, `v0.3.0` = S3, `v0.9.0` = S4).

## [Sin publicar]

## [0.3.0] - Seguimiento 3

### Agregado
- **Seguridad:** inicio y cierre de sesión (`login.php`, `logout.php`) contra la colección `usuarios` con `password_verify`, y permisos por rol en `src/includes/sesion.php`: todas las páginas exigen sesión y el backend solo deja al rol `admin` crear, editar, anular o borrar (responde 403 a `comunidad`). Ocultar los botones en cada módulo queda a cargo de sus responsables.
- **Seguridad:** token CSRF (`exigirCsrf()` en `src/includes/sesion.php`) en todos los formularios de administración de contactos, seguimientos, espacios y reservas: un POST sin el token de su formulario responde 400 y no cambia nada. Ventas ya lo valida con `exigirAccionAdmin()` (PR #88). (PR #95; Issue #98)
- **Alquileres:** calendario público y solicitudes de reserva de la comunidad con captcha ALTCHA de un solo uso; la junta las aprueba en una transacción (PR #73).
- **Alquileres:** pruebas P4 (concurrencia) y P5 (TTL) con resultados en Atlas, prueba P6 de acceso por rol, mediciones con `explain()` en `scripts/medir_alquileres.js` y decisiones técnicas del módulo (PR #74, #75, #76, #77, #78; Issues #15, #16, #17, #18).
- **Contactos:** historial de seguimientos de cada contacto con `$lookup` y acceso solo para administración (PR #79).
- **Contactos:** JSON Schema de `contactos` y `seguimientos` en `scripts/crear_colecciones_contactos.js` (PR #71).
- Decisiones generales y de inicio de sesión y permisos en `docs/decisiones.md` (Issue #100).
- Bitácora `docs/seguimientos/S3.md` (Issue #54).
- `scripts/crear_usuarios_prueba.php`: usuarios ficticios `admin@example.com` y `comunidad@example.com` para probar el login.
- **Ventas:** vistas de administración y comunidad. La comunidad ve actividades, totales y números libres, sin acciones ni nombres de compradores (PR #87; Issue #80).
- **Ventas:** token CSRF en todas las acciones de administración y validación estricta del estado de la actividad (PR #88; Issue #81).
- **Ventas:** índice `{fecha: -1}` y listado de ventas con `$lookup` a actividades y contactos (PR #89; Issue #82).
- `scripts/medir_ventas.js`: mediciones `explain()` del módulo de ventas con y sin índice, 3 corridas y mediana (PR #90; Issue #36).
- `scripts/prueba_rifa_concurrencia.ps1` y pruebas P7 a P9 del módulo de ventas en `docs/pruebas.md` (PR #91; Issues #35, #83).
- Decisiones técnicas del módulo de ventas en `docs/decisiones.md` (PR #92; Issue #37) y guía del módulo con el guion de la demo en `docs/ventas.md` (Issues #38, #86).

### Corregido
- **Ventas:** una cuenta de comunidad que abría Actividades o Ventas terminaba en el calendario de alquileres (PR #87).
- **Carga de datos:** las rifas generadas por `cargar_datos.js` y `cargar_datos.php` traen `precio_numero` y se pueden vender sin editarlas (PR #93; Issue #84).

### Cambiado
- Si MongoDB no responde, los módulos muestran "No se pudo conectar con la base de datos" con código 503 en lugar de una pantalla en blanco, sin la URI ni el host; el detalle va al log del servidor (`src/includes/conexion.php`; prueba P2, Issue #50).
- README al día: rol de comunidad, variable `ALTCHA_HMAC_KEY`, cuentas de prueba, tabla de documentación, capturas y estado de los seguimientos (Issue #56).
- `diagnostico.php` ya no escribe en la base con visitas anónimas: la prueba de escritura, las colecciones, las últimas conexiones y el texto del error solo se muestran con sesión de admin (PR #95).

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
