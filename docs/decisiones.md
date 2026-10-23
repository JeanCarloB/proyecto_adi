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
