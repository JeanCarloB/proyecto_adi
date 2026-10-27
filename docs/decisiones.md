# Decisiones técnicas

Por qué el modelo y las consultas de ADIministra quedaron como quedaron. Cada módulo documenta sus decisiones de modelado, índices y garantías de consistencia.

## Decisiones generales (Issue #100)

### 1. Un solo motor: MongoDB Atlas (Perfil A)
Toda la información vive en un clúster M0 de Atlas (AWS Oregon), base `adi`. El perfil A pide profundidad en un motor y no cantidad de motores, así que las reglas del negocio se resuelven con funciones de MongoDB: índices únicos, TTL, JSON Schema, aggregation, `$lookup` y transacciones. Un solo motor también simplifica el despliegue y las copias de seguridad, que Atlas ya hace.

### 2. PHP sin framework y la librería oficial
La aplicación es PHP 8.3 con la extensión `mongodb` 2.4 y la librería `mongodb/mongodb` 2.x (Composer), con HTML y Bootstrap 5. Sin framework, cada consulta a MongoDB se ve tal cual en el código, que es lo que se evalúa en el curso, y el servidor Virtualmin no necesita nada fuera de PHP y Composer.

### 3. El DDL en scripts de `mongosh`, no en PHP
El JSON Schema y los índices de cada colección están en `scripts/crear_colecciones*.js`. Se pueden correr varias veces sin romper nada, y quedan versionados junto al código. La aplicación nunca crea colecciones ni índices: si falta uno, se nota en `diagnostico.php` y en las mediciones, no a mitad de una venta.

### 4. Reglas en la base, no solo en el formulario
Cada regla importante tiene una garantía en MongoDB además de la validación en PHP: JSON Schema para los tipos y valores permitidos, e índices únicos contra duplicados. Si el formulario falla o alguien manda un POST a mano, la base rechaza el dato inválido (pruebas P3, P4 y P7 en `docs/pruebas.md`).

### 5. Tipos de datos
Los montos se guardan como `Decimal128`, para que las sumas no pierdan céntimos como pasaría con `double`. Las fechas son `date` (`UTCDateTime`), para poder ordenar, filtrar por rango y usar TTL. `contactos` y `espacios` usan `activo` (booleano). Si el campo no existe se toma como activo, y los filtros usan `activo: {$ne: false}`.

### 6. Datos sintéticos reproducibles
`scripts/cargar_datos.php` (Faker) y `scripts/cargar_datos.js` (mongosh) generan los mismos datos cada vez porque usan una semilla fija (2026), incluidas 100 000 ventas para las mediciones. Las pruebas de carga se hacen en `adi_prueba`; `adi` es la base compartida y no se limpia sin avisar al equipo.

### 7. Credenciales fuera del repositorio
La URI de Atlas y la clave del captcha viven en un `.env` que no se sube (`.gitignore`) y que Apache no entrega (`.htaccess`). En el repositorio solo está `.env.example`, sin valores reales. Cada integrante usa su propio usuario de Atlas. El servidor usa un usuario exclusivo de la aplicación que solo tiene permisos sobre `adi`, y Atlas solo acepta la IP del servidor.

### 8. Integración por pull request y servidor de solo lectura
Todo cambio entra a `main` por pull request. El servidor tiene un clon con una *deploy key* de solo lectura y se actualiza únicamente con `git pull --ff-only`. Así lo que corre en el servidor siempre es un commit de `main`, y si alguien edita un archivo directamente allí, el `pull` falla y se nota.

### 9. Qué pasa si MongoDB no responde
La conexión espera como máximo 5 s (`serverSelectionTimeoutMS`) en lugar de los 30 s del controlador. Si se agota el tiempo o falla la autenticación, `src/includes/conexion.php` muestra un aviso de "No se pudo conectar con la base de datos" con código 503, no guarda nada y escribe el detalle solo en el log del servidor, porque el mensaje del controlador puede incluir el host del clúster. `diagnostico.php` indica cuál de las verificaciones falla (prueba P2).

## Módulo de alquileres (Issue #18)

### 1. Tarifas embebidas en el espacio
Cada espacio guarda sus tarifas por franja dentro del mismo documento (`tarifas: [{franja, monto}, …]`). Son pocas, cambian poco y siempre se leen junto con el espacio, así que embeberlas evita una consulta extra. El monto de cada reserva se copia al crearla: si la tarifa sube después, las reservas viejas conservan el precio acordado.

### 2. Reservas por referencia
Las reservas son una colección aparte que guarda `espacio_id` y `contacto_id`. Embeberlas en el espacio haría crecer el documento sin límite (cientos de reservas por año) y complicaría las consultas por fecha. Con referencia, cada reserva es un documento pequeño y el calendario las trae por rango de fechas. Para mostrar el nombre del contacto se usa `$lookup` en el calendario de la junta.

### 3. Índice único contra el doble alquiler
El índice único `{espacio_id, fecha, franja}` impide que dos personas reserven el mismo espacio, día y franja. La garantía la da la base, no el código PHP: en la prueba de concurrencia (P4), dos procesos insertaron el mismo horario en el mismo instante, y en las 5 rondas uno entró y el otro recibió el error 11000. La aplicación captura ese código y muestra "ese horario ya está ocupado".

### 4. Apartados que vencen solos (TTL)
Un apartado sin pagar guarda `vence` = ahora + 48 h. El índice TTL sobre `vence` lo borra cuando pasa esa hora, y el horario queda libre sin ninguna tarea programada. Las reservas confirmadas no tienen `vence`, así que el TTL no las toca. En la prueba P5, el apartado desapareció 22 s después de vencer (el monitor TTL corre cada ~60 s) y el horario se volvió a reservar sin error.

### 5. Solicitudes de la comunidad
Los vecinos no reservan directo: envían una solicitud que la junta aprueba o rechaza.
- **JSON Schema:** `expira` existe solo mientras la solicitud está pendiente. Una solicitud pendiente sin `expira`, o una resuelta con `expira`, es rechazada por la base.
- **TTL sobre `expira`:** una solicitud que nadie atiende en 7 días se borra sola.
- **Índice único parcial** `{espacio_id, fecha, franja, usuario_id}` con `estado: "pendiente"`: una persona no puede pedir dos veces el mismo horario mientras espera, pero sí puede volver a pedirlo si se lo rechazaron.

### 6. Aprobar en una transacción
Aprobar una solicitud toca tres colecciones: crea o reutiliza el contacto, inserta la reserva apartada y marca la solicitud como aprobada. Se hace con `with_transaction`. Si el horario ya está ocupado (11000), se deshace todo y la solicitud sigue pendiente. Nunca queda una reserva sin solicitud aprobada, ni al revés.

### 7. Captcha de un solo uso en el login
El login usa ALTCHA (prueba de trabajo, sin servicios externos ni cookies de terceros), verificado en PHP con HMAC. Para que un mismo captcha resuelto no se pueda reutilizar, cada reto se inserta en `captchas_usados` con el reto como `_id`. Un segundo uso choca con el `_id` (11000) y se rechaza. Un TTL borra los retos cuando vencen, así que la colección no crece.

### 8. Calendario público con consulta cubierta
El calendario público solo muestra qué horarios están ocupados, sin datos de las personas. La consulta proyecta `{_id: 0, espacio_id, fecha, franja}`, que son justo los campos del índice, así que MongoDB responde desde el índice sin leer documentos (`PROJECTION_COVERED`, 0 documentos examinados). Además, al no traer contactos, no puede filtrar datos personales aunque haya un error en la vista.

### 9. `$in` sobre `espacio_id` para usar el índice por rango de fechas
El índice empieza por `espacio_id`. Filtrar solo por `fecha` no lo aprovecharía. Por eso las consultas del calendario usan `espacio_id: {$in: [espacios activos]}` + rango de `fecha`, y así el índice sirve también para "todas las reservas del mes". Con esto, el `$lookup` del calendario de la junta lee 33 documentos en lugar de 389 (ver `docs/mediciones.md`).

## Módulo de ventas en actividades (Issue #37)

### 1. Productos embebidos en la venta
Cada venta guarda sus productos dentro del mismo documento (`items: [{producto, cantidad, precio}, …]`). Son pocos, siempre se leen junto con la venta y no cambian después: una venta registrada es un hecho que ya pasó. Embeberlos evita una colección `items` con un documento por línea, y con eso un `$lookup` o una consulta extra en cada pantalla. El precio se copia en cada ítem, así que si el tamal sube de precio, las ventas viejas conservan el que se cobró.

### 2. La venta apunta a su actividad por referencia
La alternativa era guardar las ventas dentro de la actividad. Un turno puede tener miles de ventas (en los datos de prueba hay actividades con casi 2 000), y el documento crecería sin límite hacia el máximo de 16 MB de MongoDB. Con `actividad_id` como referencia, cada venta es un documento pequeño y el índice `{actividad_id, fecha}` trae las de una actividad ya ordenadas.

### 3. Los números de rifa en su propia colección
La alternativa era un arreglo `vendidos: [7, 42, …]` dentro de la actividad. Se descartó por dos razones:
- **No se puede garantizar que no se repitan.** MongoDB no tiene un índice único sobre los elementos de un arreglo dentro del mismo documento, así que la regla quedaría en el código PHP.
- **Cada número tiene sus propios datos:** quién lo compró, cuánto pagó y cuándo.

En la colección `rifas`, cada número vendido es un documento y el índice único `{rifa_id, numero}` hace que la **base** rechace el segundo intento con el error 11000.

### 4. Vender sin revisar antes si el número está libre
`src/rifas/vender.php` no pregunta "¿está libre el 15?" antes de insertar. Si lo hiciera, dos voluntarios podrían preguntar al mismo tiempo, recibir los dos "sí" y vender los dos. Por eso inserta directo y deja que decida el índice único. La aplicación solo traduce el 11000 a "El número ya se vendió". La prueba P7 lo comprueba con dos procesos en paralelo.

### 5. El total recaudado se calcula, no se guarda
El total de una actividad no está en el documento de la actividad: se calcula cada vez con un aggregation pipeline (`$match` por actividad + `$group` con `$sum`) sobre `ventas`, o sobre `rifas` en las rifas. Un total guardado habría que actualizarlo en cada venta, edición y anulación. Si una de esas actualizaciones falla, el total queda desfasado y nadie se entera. Con el índice `{actividad_id, fecha}`, el cálculo lee solo las ventas de esa actividad: 27 ms en Atlas con 100 000 ventas (ver `docs/mediciones.md`).

### 6. Una actividad finalizada no acepta cambios
Cuando la junta finaliza una actividad, su total queda cerrado: no se registran ventas nuevas ni se editan o anulan las que ya tiene. Así, el total que se reportó en la asamblea no cambia después. Si hay que corregir algo, se reabre la actividad, que es una acción explícita. Las actividades no se borran, porque perderían su historial de ventas.

### 7. Dinero en `Decimal128`
Montos, precios y totales se guardan como `Decimal128`, igual que en alquileres. Un `double` no representa exacto todos los decimales, y al sumar miles de ventas el error se acumula. El JSON Schema rechaza cualquier monto que no sea `decimal`.

### 8. Lo que ve la comunidad
La comunidad ve las actividades, sus totales y qué números de rifa quedan libres. Es información que la ADI comparte para rendir cuentas y para que la gente sepa qué número pedir. No ve quién compró cada número ni el detalle de cada venta, porque son datos personales de los vecinos. Para la cuadrícula de la rifa, la consulta pide solo `{_id: 0, numero: 1}`, una consulta **cubierta** por el índice único: MongoDB responde sin abrir los documentos, así que el comprador nunca llega a PHP aunque la vista tuviera un error.

### 9. Índice `{fecha: -1}` para el listado general
La primera pantalla de ventas de la junta muestra las 200 más recientes de todas las actividades, y se puede filtrar solo por fechas. El índice `{actividad_id, fecha}` no sirve sin elegir actividad, porque `fecha` no es su primer campo, y sin otro índice MongoDB tendría que leer y ordenar las 100 000 ventas en memoria. Se agregó `{fecha: -1}`. El costo es un índice más que actualizar en cada venta, que se acepta porque el listado se abre mucho más de lo que se registran ventas.

### 10. `$lookup` después del `$limit`
El listado trae el nombre de la actividad y del comprador con `$lookup`. El orden de las etapas importa: `$match`, `$sort` y `$limit 200` van primero, así MongoDB los resuelve con el índice y los `$lookup` trabajan solo sobre esas 200 ventas. Puesto al revés, haría `$lookup` sobre todas las ventas del filtro y después descartaría casi todas.

### 11. Control de acceso propio
`src/includes/ventas.php` reutiliza las funciones generales de `alquileres.php` (dinero, fechas, validación de ids), pero no su control de acceso, que manda a la comunidad al calendario de alquileres. Por eso define `ACCESO_PROPIO` y aplica el suyo: cualquier usuario con sesión consulta, y las acciones que cambian datos exigen rol `admin`, método POST y token CSRF en el servidor (`exigirAccionAdmin()`). Esconder los botones es solo comodidad: la regla la aplica el servidor (pruebas P9).

### 12. Números apartados por la comunidad (Issue #114)
Un vecino aparta un número desde el sistema y lo paga después a la junta. El apartado es un documento más de `rifas` con `estado: "apartado"` y `vence` = ahora + 48 h:
- **Mismo índice único.** `{rifa_id, numero}` aplica igual a apartados y vendidos, así que dos personas no pueden tener el mismo número aunque lo pidan en el mismo instante.
- **Vence solo.** Un índice TTL sobre `vence` borra el apartado que nadie pagó, como los apartados de alquiler, y no hace falta ninguna tarea programada.
- **El validador separa los estados.** Un apartado exige `vence` y la cuenta que lo apartó. Uno pagado no puede tener `vence`, porque el TTL lo borraría, y debe tener contacto. Los documentos viejos sin `estado` cuentan como pagados.
- **Confirmar el pago en una transacción.** Se busca o se crea el contacto por el correo de la cuenta y el número pasa a pagado en la misma operación. Si el apartado venció entre medio, se deshace todo y no queda un contacto de más.
- **Límites.** El total recaudado solo suma números pagados, y cada persona puede tener como máximo 5 apartados sin pagar por rifa.

### 13. Encargos en turnos y ferias (Issue #115)
- **El menú va embebido en la actividad** (`menu: [{producto, precio}]`): son pocos productos y siempre se leen con ella.
- **El encargo solo manda cantidades.** El nombre y el precio salen del menú guardado y el total lo calcula el servidor, así que nadie puede encargar a otro precio desde el formulario.
- **Colección propia `encargos`**, en lugar de una venta con otro estado. Un encargo todavía no es dinero recaudado: puede cancelarse o no retirarse. Mezclarlos en `ventas` obligaría a filtrar el estado en todos los totales y en las 100 000 ventas medidas.
- **Entregar en una transacción.** Se busca o crea el contacto, se inserta la venta y se marca el encargo como entregado con el `venta_id`. Si el encargo ya no estaba pendiente, se deshace todo: nunca hay ventas duplicadas.
- **TTL sobre `expira`**, que solo existe en los pendientes (lo exige el validador). Un encargo que nadie atendió se borra 2 días después de la actividad. Los entregados y cancelados quedan de historial en Mis compras.

## Inicio de sesión y permisos (Issues #96, #97 y #98)

### 1. Usuarios en MongoDB con contraseña cifrada
Las cuentas están en la colección `usuarios` con `correo`, `nombre`, `rol` y `contrasena`, guardada con `password_hash` (bcrypt). `login.php` usa `password_verify` y actualiza el hash si PHP recomienda uno más fuerte (`password_needs_rehash`). El mensaje de error es siempre "Correo o contraseña incorrectos". Si el correo no existe, el login igual verifica contra un hash cualquiera para tardar lo mismo, así no se puede averiguar qué correos existen.

### 2. Dos roles: `admin` y `comunidad`
`admin` es la junta directiva: crea, edita, anula y borra. `comunidad` son los vecinos: ven el calendario público, piden reservas y consultan actividades y números libres. Con dos roles alcanza para el MVP, y cada página lo revisa en una sola línea.

### 3. Los permisos se validan en el servidor
Ocultar un botón no impide escribir la URL o mandar el POST a mano. Por eso cada página que cambia datos llama a `requiereRol('admin')` antes de tocar la base, y responde 403 a una cuenta de comunidad. Los módulos que son solo de la junta (contactos y seguimientos) lo exigen desde su `include`, y alquileres manda a la comunidad a su calendario público. Ocultar los botones es solo para que la interfaz se entienda (pruebas P6 y P9).

### 4. Sesión de PHP endurecida
La cookie `adi_sesion` es `HttpOnly` (JavaScript no la lee), `SameSite=Lax` (no viaja en peticiones POST desde otros sitios) y `Secure` cuando hay HTTPS. El servidor solo acepta identificadores de sesión creados por él (`session.use_strict_mode`), cambia el identificador al iniciar sesión (`session_regenerate_id`) y cierra la sesión después de 60 minutos sin actividad.

### 5. Token CSRF en los formularios de administración
Cada sesión tiene un token aleatorio de 32 bytes. Cada formulario POST lo incluye (`campoCsrf()`) y la página lo compara con `hash_equals` antes de guardar (`exigirCsrf()`, o `exigirAccionAdmin()` en ventas). Sin el token correcto responde 400 y no cambia nada. Cerrar sesión también es un POST con token, para que un enlace de otro sitio no pueda sacar a nadie.

### 6. Volver a la página pedida, solo dentro del sitio
Si alguien abre una página sin sesión, va a `login.php?volver=...` y, al entrar, regresa a esa página. `destinoSeguro()` solo acepta rutas del mismo sitio (que empiezan con una sola `/`), así un enlace no puede usar el login para mandar a alguien a otra página web.

### 7. `diagnostico.php` limitado sin sesión
La verificación del entorno tiene que funcionar justo cuando Atlas falla y nadie puede iniciar sesión, por eso sigue abierta. Sin sesión de admin solo dice OK o Falla en cada verificación: no hace la prueba de escritura, no lista las colecciones ni las últimas conexiones, y no muestra el texto del error.

