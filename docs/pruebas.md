# Pruebas documentadas

Cada prueba indica la preparación, los pasos, el resultado esperado y el resultado obtenido. Se ejecutan en el servidor (`grupo4.crnube.net/clientes2/proyecto`) salvo que se indique lo contrario. Las capturas van en `docs/capturas/` con el nombre del caso.

| # | Caso | Resultado | Fecha | Responsable |
| --- | --- | --- | --- | --- |
| P1 | Caso normal: flujo completo | _(OK / falla)_ | | |
| P2 | Error de conexión con Atlas | _(OK / falla)_ | | |
| P3 | Datos inválidos rechazados | _(OK / falla)_ | | |
| P4 | Alquileres: dos reservas simultáneas del mismo horario | OK | 2026-10-22 | Hazel |
| P5 | Alquileres: un apartado vence solo (TTL) y libera el horario | OK | 2026-10-22 | Hazel |
| P6 | Alquileres: acceso según el rol (sin sesión, comunidad, admin) | _(OK / falla)_ | | Hazel |

---

## P1 · Caso normal: flujo completo

**Preparación:** colecciones creadas con `scripts/crear_colecciones*.js` y `diagnostico.php` con las 6 verificaciones en OK.

**Pasos y resultado esperado:**

1. Abrir `diagnostico.php`: las 6 verificaciones salen en **OK** y se listan las colecciones. Abrir la portada (`index.php`): muestra el resumen de los tres módulos con enlaces a cada uno.
2. **Contactos:** crear un contacto de tipo vecino, editar su teléfono y verlo en el listado.
3. **Alquileres:** crear una reserva (apartado) del salón para una fecha libre; aparece con estado *apartado*. Confirmar el pago: pasa a *confirmado* y ya no tiene `vence`.
4. **Ventas:** crear una actividad tipo feria, registrar una venta con dos productos; el total de la venta es la suma de cantidad × precio y el total recaudado de la actividad aumenta.
5. **Rifas:** en una actividad tipo rifa, vender un número libre; aparece como vendido.

**Resultado obtenido:** _(completar con capturas)_

## P2 · Error de conexión con Atlas

Comprueba que la aplicación falla de forma controlada, sin mostrar la URI ni la contraseña.

**Variante A · URI incorrecta** (en una copia local, nunca en el `.env` del servidor):

1. En `.env`, cambiar la contraseña de `MONGODB_URI` por una incorrecta.
2. Abrir `diagnostico.php`.
3. **Esperado:** "Conexión a MongoDB Atlas" y "Escritura en la base" en **Falla**, con un mensaje de autenticación; la página no se cae. La portada (`index.php`) muestra "No se pudo conectar con la base de datos" sin detalles de la URI.
4. Abrir un módulo (por ejemplo `src/contactos/`). **Esperado:** mensaje de error, no una pantalla en blanco ni la URI.
5. Restaurar el `.env`.

**Variante B · IP no autorizada:** desde una red cuya IP no está en Network Access de Atlas, abrir `diagnostico.php`. **Esperado:** "Conexión" en **Falla** a los 5 s (`serverSelectionTimeoutMS`) con un error de selección de servidor.

**Variante C · sin `.env`:** renombrar `.env` a `.env.bak`. **Esperado:** "Archivo .env con MONGODB_URI" en **Falla**.

**Resultado obtenido:** _(completar)_

## P3 · Datos inválidos rechazados

Comprueba que las reglas las garantiza la base de datos y no solo el formulario.

**Desde la interfaz:**

1. Crear un contacto sin nombre o con un tipo que no existe. **Esperado:** el formulario muestra el error y no guarda.
2. Registrar una venta con cantidad 0 o negativa. **Esperado:** error y no se guarda.
3. Crear dos reservas del mismo espacio, fecha y franja. **Esperado:** la segunda falla (índice único, código 11000).
4. Vender dos veces el mismo número de rifa. **Esperado:** la segunda falla (índice único, código 11000).

**Directo en la base** (`mongosh` contra `adi`, sin pasar por PHP):

```js
use adi
// Tipo de espacio no permitido por el JSON Schema → error 121 "Document failed validation"
db.espacios.insertOne({ nombre: 'Prueba', tipo: 'piscina', tarifas: [], activo: true })
// Venta con cantidad 0 → error 121
db.ventas.insertOne({ actividad_id: ObjectId(), items: [{ producto: 'Tamal', cantidad: 0, precio: NumberDecimal('1500') }], total: NumberDecimal('0'), fecha: new Date() })
// Estado no permitido en reservas → error 121
db.reservas.insertOne({ espacio_id: ObjectId(), contacto_id: ObjectId(), fecha: new Date(), franja: 'tarde', estado: 'pendiente', monto: NumberDecimal('100') })
```

**Esperado:** los tres inserts fallan con `Document failed validation` y no queda ningún documento nuevo.

**Resultado obtenido:** _(completar)_

---

## P4 · Dos reservas simultáneas del mismo horario (Issue #15)

Comprueba que el doble alquiler lo impide la **base de datos**, aunque dos personas aparten el mismo horario en el mismo instante. Revisar primero y después insertar no alcanza: entre la revisión y la inserción el otro puede colarse. El índice único `{espacio_id, fecha, franja}` cierra esa ventana.

**Preparación:** PHP con la extensión `mongodb` y el `.env` configurado. La prueba usa la fecha 31/12/2099 (franja noche) y la limpia al terminar.

**Pasos (PowerShell, desde la raíz del proyecto):**

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\prueba_concurrencia.ps1 -Rondas 5
```

El script lanza **dos procesos PHP en paralelo** (`Start-Job`). Cada uno abre su conexión, espera hasta el mismo instante acordado e intenta insertar el mismo apartado.

**Esperado:** en cada ronda un proceso queda **INSERTADA** y el otro **RECHAZADA (11000, clave duplicada)**. Al final: 5 insertadas y 5 rechazadas.

**Variante en la interfaz:** abrir `src/reservas/crear.php` en dos navegadores con la misma sesión de admin, llenar el mismo espacio, fecha y franja, y enviar los dos casi a la vez. Uno aparta y el otro muestra "Ese horario ya está reservado".

**Resultado obtenido (2026-10-22, Atlas M0 Oregon, 5 rondas):** ✅

| Ronda | Proceso A | Proceso B | Salida respecto al instante acordado | Tiempo del insert |
| --- | --- | --- | --- | --- |
| 1 | Insertada | Rechazada (11000) | +11 ms / +11 ms | 354 / 353 ms |
| 2 | Insertada | Rechazada (11000) | −6 ms / −6 ms | 248 / 247 ms |
| 3 | Rechazada (11000) | Insertada | +1 ms / +1 ms | 246 / 247 ms |
| 4 | Rechazada (11000) | Insertada | +16 ms / −7 ms | 133 / 147 ms |
| 5 | Rechazada (11000) | Insertada | +5 ms / +5 ms | 236 / 237 ms |

**Lectura:** los dos procesos salieron con menos de 25 ms de diferencia y en cada ronda **exactamente uno** quedó insertado: 5 insertadas y 5 rechazadas. El ganador cambia de ronda a ronda (A en las rondas 1 y 2, B en la 3 a la 5), lo que confirma que es una carrera real y no un orden fijo. Aun así, nunca quedaron dos reservas del mismo horario: lo garantiza el índice único, no el código PHP.

## P5 · Un apartado vence solo y libera el horario (Issue #16)

Comprueba que el índice TTL sobre `vence` borra el apartado que nadie confirmó y que el horario vuelve a quedar libre.

**Pasos:**

```powershell
& "<ruta a mongosh.exe>" "mongodb+srv://<clúster>/" --username <usuario> --file scripts/prueba_ttl.js
```

El script crea un apartado que vence en 60 s (fecha de prueba 30/12/2099), revisa cada 10 s si sigue existiendo y, cuando desaparece, intenta reservar el mismo horario.

**Esperado:**
- El apartado desaparece **entre 0 y ~60 s después** de su fecha de vencimiento. El monitor TTL de MongoDB corre cada ~60 s, así que no es al segundo exacto.
- Luego el mismo horario se reserva sin error 11000.

**Resultado obtenido (2026-10-22, Atlas M0 Oregon):** ✅

| Momento | Hora (UTC) | Estado |
| --- | --- | --- |
| Creado | 03:45:15 | apartado, `vence` = creado + 60 s |
| Vence | 03:46:15 | sigue apartado: el TTL todavía no pasó |
| +70 s | — | sigue apartado |
| +80 s | — | **ya no existe** |

**Lectura:** el apartado desapareció a los **82 s** de crearse, es decir, **22 s después** de su fecha de vencimiento. Coincide con el monitor TTL de MongoDB, que revisa cada ~60 s: el borrado no es al segundo exacto, pero ocurre solo y sin ningún proceso de la aplicación. Después, el mismo espacio, día y franja se volvió a reservar **sin error 11000**: el horario quedó libre.

**Implicación para la aplicación:** durante esos segundos de retraso un apartado vencido todavía ocupa el horario. Es aceptable para un alquiler de 48 h, y "Confirmar pago" solo actúa si la reserva sigue en estado `apartado`.

## P6 · Acceso a alquileres según el rol

Comprueba que los permisos se aplican en el servidor y no solo escondiendo botones.

| # | Usuario | Acción | Esperado | Resultado |
| --- | --- | --- | --- | --- |
| 1 | Sin sesión | Abrir `src/publico/calendario.php` | Se ve el calendario: solo franjas ocupadas, sin nombres, teléfonos ni montos | |
| 2 | Sin sesión | Tocar "Solicitar" en una franja libre | Pide iniciar sesión y, al entrar, vuelve al formulario con el día y la franja | |
| 3 | Sin sesión | Abrir `src/reservas/index.php` | Lo manda al login | |
| 4 | Cualquiera | Iniciar sesión sin marcar el captcha | "Confirme que no es un robot"; no entra aunque la contraseña sea correcta | |
| 5 | Comunidad | Enviar una solicitud | Aparece en "Mis solicitudes" como Pendiente | |
| 6 | Comunidad | Repetir la misma solicitud (mismo espacio, día y franja) | "Ya tenés una solicitud pendiente…" (índice único parcial) | |
| 7 | Comunidad | Abrir `src/reservas/index.php` o `src/espacios/form.php` | Lo redirige al calendario público; no ve datos de la junta | |
| 8 | Comunidad | Enviar un POST directo a `src/reservas/resolver_solicitud.php` | 403 "no tiene permiso" (`requiereRol('admin')`) | |
| 9 | Admin | Aprobar la solicitud | Se crea la reserva apartada y el contacto (transacción); en "Mis solicitudes" sale Aprobada | |
| 10 | Admin | Aprobar una solicitud cuyo horario ya se ocupó | No aparece el botón Aprobar; si se fuerza, el índice único lo rechaza y no se guarda nada | |
| 11 | Admin | Rechazar con motivo | En "Mis solicitudes" sale Rechazada con el motivo | |

**Resultado obtenido:** _(marcar cada fila y adjuntar capturas en `docs/capturas/s3-alquileres-*.png`)_
