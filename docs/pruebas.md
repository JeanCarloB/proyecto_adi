# Pruebas documentadas

Cada prueba indica la preparación, los pasos, el resultado esperado y el resultado obtenido. Se ejecutan en el servidor (`grupo4.crnube.net/clientes2/proyecto`) salvo que se indique lo contrario. Las capturas van en `docs/capturas/` con el nombre del caso.

| # | Caso | Resultado | Fecha | Responsable |
| --- | --- | --- | --- | --- |
| P1 | Caso normal: flujo completo | _(OK / falla)_ | | |
| P2 | Error de conexión con Atlas | _(OK / falla)_ | | |
| P3 | Datos inválidos rechazados | _(OK / falla)_ | | |
| P4 | Alquileres: dos reservas simultáneas del mismo horario | OK | 2026-10-22 | Hazel |
| P5 | Alquileres: un apartado vence solo (TTL) y libera el horario | OK | 2026-10-22 | Hazel |
| P6 | Alquileres: acceso según el rol (sin sesión, comunidad, admin) | OK (11 de 11) | | Hazel |
| P7 | Ventas: dos ventas simultáneas del mismo número de rifa | OK | 2026-10-25 | Kendall |
| P8 | Ventas: datos inválidos, número repetido y actividad finalizada | OK (12 de 12, local) | 2026-10-24 | Kendall |
| P9 | Ventas: acceso según el rol y token CSRF (sin sesión, comunidad, admin) | OK (12 de 12, local) | 2026-10-24 | Kendall |

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
2. Abrir `diagnostico.php` (con la sesión de admin abierta antes de cambiar el `.env`).
3. **Esperado:** "Conexión a MongoDB Atlas" y "Escritura en la base" en **Falla**, con un mensaje de autenticación; la página no se cae. Sin sesión, "Escritura" sale en **Solo admin** y el aviso dice "No se pudo conectar con la base de datos", sin el texto del error. La portada (`index.php`) muestra "No se pudo conectar con la base de datos" sin detalles de la URI.
4. Abrir un módulo (por ejemplo `src/contactos/`). **Esperado:** en 5 s como máximo, página con el aviso "No se pudo conectar con la base de datos…" y código 503 (`src/includes/conexion.php`). No es una pantalla en blanco y no muestra la URI ni el host del clúster; el detalle queda en el log de errores del servidor.
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
| 1 | Sin sesión | Abrir `src/publico/calendario.php` | Se ve el calendario: solo franjas ocupadas, sin nombres, teléfonos ni montos | OK · [captura](capturas/s3-alquileres-publico.png) |
| 2 | Sin sesión | Tocar "Solicitar" en una franja libre | Pide iniciar sesión y, al entrar, vuelve al formulario con el día y la franja | OK · [captura](capturas/s3-alquileres-solicitar-login.png) |
| 3 | Sin sesión | Abrir `src/reservas/index.php` | Lo manda al login | OK · [captura](capturas/s3-alquileres-solicitar-login.png) |
| 4 | Cualquiera | Iniciar sesión sin marcar el captcha | "Confirme que no es un robot"; no entra aunque la contraseña sea correcta | OK · [captura](capturas/s3-alquileres-captcha.png) |
| 5 | Comunidad | Enviar una solicitud | Aparece en "Mis solicitudes" como Pendiente | OK · [captura](capturas/s3-alquileres-pendiente.png) |
| 6 | Comunidad | Repetir la misma solicitud (mismo espacio, día y franja) | "Ya tenés una solicitud pendiente…" (índice único parcial) | OK · [captura](capturas/s3-alquileres-duplicada.png) |
| 7 | Comunidad | Abrir `src/reservas/index.php` o `src/espacios/form.php` | Lo redirige al calendario público; no ve datos de la junta | OK · [captura](capturas/s3-alquileres-comunidad-redirige.png) |
| 8 | Comunidad | Enviar un POST directo a `src/reservas/resolver_solicitud.php` | No se ejecuta: el control de acceso de `alquileres.php` lo redirige al calendario público antes de llegar a `requiereRol('admin')` | OK · [captura](capturas/s3-alquileres-403.png) |
| 9 | Admin | Aprobar la solicitud | Se crea la reserva apartada y el contacto (transacción); en "Mis solicitudes" sale Aprobada | OK · [captura](capturas/s3-alquileres-pendiente.png) |
| 10 | Admin | Apartar desde Reservas un horario que alguien de la comunidad pidió y sigue pendiente | Aviso "Alguien de la comunidad ya pidió este horario" con enlace a la bandeja; solo se aparta con "Apartar de todas formas", y entonces la solicitud sale "Horario ya ocupado" sin el botón Aprobar | OK · [captura](capturas/s3-alquileres-ocupado.png) |
| 11 | Admin | Rechazar con motivo | En "Mis solicitudes" sale Rechazada con el motivo | OK · [captura](capturas/s3-alquileres-rechazada.png) |

**Resultado obtenido:** 11 de 11 filas comprobadas, todas OK. Las filas 3 y 9 usan capturas de otras filas: abrir reservas sin sesión lleva al mismo login de la fila 2, y la solicitud aprobada aparece en "Mis solicitudes" de la fila 5. En la fila 8, el `fetch` termina con `redirected: true` en `src/publico/calendario.php`: la petición de comunidad nunca llega a la lógica de aprobar. En la fila 10, después de apartar de todas formas, la solicitud del 31 oct queda marcada "Horario ya ocupado" y solo se puede rechazar.

---

## P7 · Dos ventas simultáneas del mismo número de rifa (Issue #35)

Comprueba que el número repetido lo impide la **base de datos**, aunque dos voluntarios vendan el mismo número en el mismo instante. Es la misma idea de P4 en alquileres: revisar primero si el número está libre y después insertar deja una ventana en la que el otro se cuela. Por eso `src/rifas/vender.php` no revisa: inserta directo y el índice único `{rifa_id, numero}` decide.

**Preparación:** PHP con la extensión `mongodb` y el `.env` configurado. El script crea su propia rifa ("Rifa de prueba de concurrencia", 31/12/2099) y la borra al terminar, con sus números. No toca las rifas reales.

**Pasos (PowerShell, desde la raíz del proyecto):**

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\prueba_rifa_concurrencia.ps1 -Rondas 5
```

El script lanza **dos procesos PHP en paralelo** (`Start-Job`). Cada uno abre su conexión, espera hasta el mismo instante acordado e intenta vender el número 99.

**Esperado:** en cada ronda un proceso queda **VENDIDO** y el otro **RECHAZADO (11000, clave duplicada)**. Al final: 5 vendidos y 5 rechazados.

**Variante en la interfaz:** abrir la misma rifa en dos navegadores con sesión de administración, elegir el mismo número y tocar "Vender número" casi a la vez. Uno lo vende y el otro muestra "El número ya se vendió…" ([captura](capturas/s3-ventas-rifa-repetido.png)).

**Resultado obtenido (2026-10-25, desde el servidor del curso contra Atlas M0 Oregon, 5 rondas):** ✅

Se corrió en el servidor con un bucle de `bash` equivalente al `.ps1`: cada ronda prepara la rifa, lanza los dos procesos con `&` y espera a ambos.

| Ronda | Proceso A | Proceso B | Salida respecto al instante acordado (A / B) | Tiempo del insert (A / B) |
| --- | --- | --- | --- | --- |
| 1 | Rechazado (11000) | Vendido | +0 ms / +1 ms | 7 / 7 ms |
| 2 | Rechazado (11000) | Vendido | +1 ms / +0 ms | 6 / 7 ms |
| 3 | Rechazado (11000) | Vendido | +1 ms / +1 ms | 7 / 6 ms |
| 4 | Vendido | Rechazado (11000) | +0 ms / +0 ms | 7 / 7 ms |
| 5 | Rechazado (11000) | Vendido | +1 ms / +1 ms | 6 / 6 ms |

Al final: *"Limpieza: rifa de prueba y 1 número(s) eliminados"*.

**Lectura:** los dos procesos salieron con a lo sumo 1 ms de diferencia, es decir, prácticamente al mismo tiempo, y en cada ronda **exactamente uno** vendió el número 99: 5 vendidos y 5 rechazados. El ganador cambia (B en las rondas 1, 2, 3 y 5; A en la 4), así que es una carrera real y no un orden fijo. Nunca quedó el mismo número vendido dos veces: lo garantiza el índice único `{rifa_id, numero}`, no el código PHP. Al terminar, la limpieza dejó solo un número, así que la rifa de prueba nunca tuvo un duplicado.

## P8 · Datos inválidos, número repetido y actividad finalizada

Comprueba que el módulo de ventas rechaza lo que no debe guardarse. Las reglas se validan en PHP para dar un mensaje claro, y la base las vuelve a exigir con el JSON Schema y los índices únicos (ver P3).

| # | Caso | Esperado | Resultado |
| --- | --- | --- | --- |
| 1 | Venta con cantidad 0 y precio negativo | "La cantidad de la fila 1…" y "El precio de la fila 1…"; no se guarda | OK · [captura](capturas/s3-ventas-datos-invalidos.png) |
| 2 | Venta sin productos (todas las filas vacías) | "Agregá al menos un producto." | OK |
| 3 | Producto sin nombre en una fila | "Falta el nombre del producto en la fila N." | OK |
| 4 | Venta con un contacto que no existe | "El contacto elegido no existe." | OK |
| 5 | Venta de productos en una rifa | "Seleccioná el turno o la feria de la venta." | OK |
| 6 | Vender el número 100 o un número negativo | No se vende (`error=datos`) | OK |
| 7 | Vender un número ya vendido | "El número ya se vendió. La base de datos no permite venderlo dos veces" (11000) | OK · [captura](capturas/s3-ventas-rifa-repetido.png) |
| 8 | Vender en una rifa sin precio por número | "Esta rifa no tiene precio por número…" | OK |
| 9 | Registrar una venta en una actividad finalizada | "Esa actividad está finalizada y ya no acepta ventas." | OK |
| 10 | Editar o anular ventas de una actividad finalizada | No aparecen los botones; el listado dice "Cerrada" | OK · [captura](capturas/s3-ventas-finalizada.png) |
| 11 | Vender un número en una rifa finalizada | No se vende (`error=cerrada`) | OK |
| 12 | Finalizar con un estado inventado (`estado=cancelada`) | 400, la actividad no cambia | OK |

**Resultado obtenido:** 12 de 12 OK, en local con el driver real de PHP y datos sintéticos (2026-10-24). Falta repetir los casos 1, 7 y 9 en el servidor para las capturas finales.

## P9 · Acceso al módulo de ventas según el rol

Comprueba que los permisos se aplican en el servidor y que la comunidad no ve datos personales. Antes del PR #87, una cuenta de comunidad que abría Actividades terminaba en el calendario de alquileres, porque heredaba el control de acceso de `alquileres.php`.

| # | Usuario | Acción | Esperado | Resultado |
| --- | --- | --- | --- | --- |
| 1 | Sin sesión | Abrir cualquier página de `src/actividades`, `src/ventas` o `src/rifas` | Lo manda al login | OK |
| 2 | Comunidad | Abrir Actividades | Lista con estado y total, solo el botón "Ver"; sin "Nueva actividad" ni "Editar" | OK · [captura](capturas/s3-ventas-comunidad-actividades.png) |
| 3 | Comunidad | Abrir una rifa | Cuadrícula de libres y vendidos y cómo comprar; sin formulario de venta ni nombres de compradores | OK · [captura](capturas/s3-ventas-comunidad-rifa.png) |
| 4 | Comunidad | Abrir un turno | Totales y productos más vendidos; sin el detalle de las ventas ni quién compró | OK · [captura](capturas/s3-ventas-comunidad-turno.png) |
| 5 | Comunidad | Abrir `src/ventas/index.php` | Lo redirige a Actividades; el enlace "Ventas" no aparece en la barra | OK |
| 6 | Comunidad | Abrir `src/ventas/form.php` o `src/actividades/form.php` | 403 "no tiene permiso" | OK · [captura](capturas/s3-ventas-comunidad-403.png) |
| 7 | Comunidad | POST directo a vender, anular o finalizar, aun con su propio token | 403 y no cambia nada | OK |
| 8 | Admin | POST sin token CSRF o con un token falso | 400 "El formulario venció…" y no cambia nada | OK · [captura](capturas/s3-ventas-csrf.png) |
| 9 | Admin | POST con el token de otra sesión | 400 y no cambia nada | OK |
| 10 | Admin | Vender, registrar venta, finalizar, reabrir y anular desde los formularios | Funcionan | OK |
| 11 | Admin | Listado de ventas | Nombre de la actividad y del comprador con `$lookup` | OK · [captura](capturas/s3-ventas-listado-lookup.png) |
| 12 | Comunidad | Revisar el código HTML de la rifa | No aparece ningún nombre de comprador, ni en los `title` de la cuadrícula | OK |

**Resultado obtenido:** 12 de 12 OK, en local con el driver real de PHP y sesiones de los dos roles (2026-10-24). Las filas 3 y 12 dependen de la consulta cubierta de la rifa: para la comunidad la página solo pide `{_id: 0, numero: 1}`, así que el comprador nunca llega a PHP.
