# Pruebas documentadas

Cada prueba indica la preparación, los pasos, el resultado esperado y el resultado obtenido. Se ejecutan en el servidor (`grupo4.crnube.net/clientes2/proyecto`) salvo que se indique lo contrario. Las capturas van en `docs/capturas/` con el nombre del caso.

| # | Caso | Resultado | Fecha | Responsable |
| --- | --- | --- | --- | --- |
| P1 | Caso normal: flujo completo | _(OK / falla)_ | | |
| P2 | Error de conexión con Atlas | _(OK / falla)_ | | |
| P3 | Datos inválidos rechazados | _(OK / falla)_ | | |

---

## P1 · Caso normal: flujo completo

**Preparación:** colecciones creadas con `scripts/crear_colecciones*.js` y `index.php` con las 6 verificaciones en OK.

**Pasos y resultado esperado:**

1. Abrir `index.php`: las 6 verificaciones salen en **OK** y se listan las colecciones.
2. **Contactos:** crear un contacto de tipo vecino, editar su teléfono y verlo en el listado.
3. **Alquileres:** crear una reserva (apartado) del salón para una fecha libre; aparece con estado *apartado*. Confirmar el pago: pasa a *confirmado* y ya no tiene `vence`.
4. **Ventas:** crear una actividad tipo feria, registrar una venta con dos productos; el total de la venta es la suma de cantidad × precio y el total recaudado de la actividad aumenta.
5. **Rifas:** en una actividad tipo rifa, vender un número libre; aparece como vendido.

**Resultado obtenido:** _(completar con capturas)_

## P2 · Error de conexión con Atlas

Comprueba que la aplicación falla de forma controlada, sin mostrar la URI ni la contraseña.

**Variante A · URI incorrecta** (en una copia local, nunca en el `.env` del servidor):

1. En `.env`, cambiar la contraseña de `MONGODB_URI` por una incorrecta.
2. Abrir `index.php`.
3. **Esperado:** "Conexión a MongoDB Atlas" y "Escritura en la base" en **Falla**, con un mensaje de autenticación; la página no se cae.
4. Abrir un módulo (por ejemplo `src/contactos/`). **Esperado:** mensaje de error, no una pantalla en blanco ni la URI.
5. Restaurar el `.env`.

**Variante B · IP no autorizada:** desde una red cuya IP no está en Network Access de Atlas, abrir `index.php`. **Esperado:** "Conexión" en **Falla** a los 5 s (`serverSelectionTimeoutMS`) con un error de selección de servidor.

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
