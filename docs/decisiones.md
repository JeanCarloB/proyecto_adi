# Decisiones técnicas

Por qué el modelo y las consultas de ADIministra quedaron como quedaron. Cada módulo documenta sus decisiones de modelado, índices y garantías de consistencia.

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
