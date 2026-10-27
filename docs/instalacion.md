# Instalación desde cero

Guía completa para dejar ADIministra funcionando desde un clon limpio del repositorio. Se validó en el Seguimiento 4 instalando en una carpeta nueva **siguiendo solo esta guía**; lo que falló o faltaba está en el [registro](#registro-de-la-prueba-desde-cero) al final y ya está corregido aquí.

## 1. Requisitos

| Componente | Versión | Cómo comprobarlo |
| --- | --- | --- |
| PHP | 8.1 o mayor (el servidor usa 8.3) | `php -v` |
| Extensión `mongodb` | 2.4 o mayor (la exige `mongodb/mongodb` 2.4 del `composer.lock`) | `php --ri mongodb` |
| Extensión `zip` | cualquiera (la usa Composer) | `php -m` |
| Composer | 2.x | `composer -V` |
| Git | cualquiera | `git --version` |
| `mongosh` | 2.x | `mongosh --version` |
| Servidor web | Apache 2.4 con `AllowOverride All` (para `.htaccess`), o el servidor integrado de PHP para probar en la computadora | |

Todos los comandos deben responder en una terminal **recién abierta**. Si uno dice "no se reconoce como nombre de un cmdlet", el programa no está en el `PATH` (ver más abajo).

### Windows con XAMPP

1. Instalar XAMPP con **PHP 8.2** o mayor. XAMPP con PHP 8.0 no sirve.
2. Ver la versión exacta: `C:\xampp\php\php.exe -i | findstr /C:"PHP Version" /C:"Thread Safety" /C:"Architecture"`.
3. En [github.com/mongodb/mongo-php-driver/releases](https://github.com/mongodb/mongo-php-driver/releases), versión 2.4.x, bajar el zip que coincida (por ejemplo `php_mongodb-2.4.0-8.2-ts-vs16-x86_64.zip` para PHP 8.2 Thread Safe de 64 bits).
4. Copiar `php_mongodb.dll` a `C:\xampp\php\ext` y agregar `extension=mongodb` al final de `C:\xampp\php\php.ini`.
5. En el mismo `php.ini`, quitar el `;` de `;extension=zip`.
6. Si PHP dice "No se puede encontrar el módulo especificado", instalar el *Microsoft Visual C++ Redistributable 2015-2022 x64*.
7. Instalar Composer con `Composer-Setup.exe`, eligiendo `C:\xampp\php\php.exe`.
8. Reiniciar Apache desde el panel de XAMPP.

### Windows sin XAMPP (PHP independiente)

1. Bajar PHP 8.3 **NTS x64** de [windows.php.net/download](https://windows.php.net/download/) y descomprimirlo, por ejemplo en `C:\Users\<usuario>\php`.
2. Copiar `php.ini-development` como `php.ini`, quitar el `;` de `extension_dir = "ext"` y de `;extension=zip`, y agregar `extension=mongodb` al final.
3. Bajar el driver como en el paso 3 de XAMPP, pero en su versión **nts** (por ejemplo `php_mongodb-2.4.x-8.3-nts-vs16-x86_64.zip`), y copiar `php_mongodb.dll` a la carpeta `ext` de PHP.
4. Agregar la carpeta de PHP al `PATH` del usuario (ver abajo) e instalar Composer con `Composer-Setup.exe`, eligiendo ese `php.exe`.

### `mongosh` en Windows

1. Bajar el zip de [mongodb.com/try/download/shell](https://www.mongodb.com/try/download/shell) y descomprimirlo, por ejemplo en `C:\Users\<usuario>\mongosh`.
2. Agregar la carpeta `bin` al `PATH` del usuario (ver abajo).

### Agregar una carpeta al `PATH` (Windows)

En PowerShell, una sola vez por carpeta (cambiar la ruta por la real):

```powershell
[Environment]::SetEnvironmentVariable("Path", [Environment]::GetEnvironmentVariable("Path","User") + ";C:\Users\<usuario>\mongosh\bin", "User")
```

Cerrar y volver a abrir la terminal para que tome el cambio. Para usarlo en la terminal que ya está abierta:

```powershell
$env:Path = [Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [Environment]::GetEnvironmentVariable("Path","User")
```

## 2. MongoDB Atlas

1. Pedir a Jean Carlo un usuario de base de datos (cada integrante tiene el suyo; el servidor usa el usuario de la aplicación).
2. En **Network Access**, agregar la IP de la máquina donde se instala (**Add Current IP Address**) y esperar a que diga *Active*. En una red de casa la IP puede cambiar de un día para otro: si ayer funcionaba y hoy no conecta, es lo primero que hay que revisar.
3. Copiar la cadena de conexión desde **Database → Connect → Drivers**.

## 3. Código y dependencias

```bash
git clone https://github.com/JeanCarloB/proyecto_adi.git
cd proyecto_adi
composer install          # en el servidor: composer install --no-dev
cp .env.example .env      # en Windows: copy .env.example .env
```

Editar `.env` (en Windows: `notepad .env`) y **guardar**. Las tres variables son obligatorias:

```
MONGODB_URI=mongodb+srv://USUARIO:CONTRASENA@cluster0.xxxxx.mongodb.net/
MONGODB_DB=adi
ALTCHA_HMAC_KEY=
```

- `MONGODB_URI`: la cadena de Atlas del paso 2. Si la contraseña tiene `@ : / ? # %`, codificarla en formato URL (por ejemplo `@` → `%40`).
- `ALTCHA_HMAC_KEY`: clave del captcha del login. Sin ella el login muestra "El formulario no está disponible" y **nadie puede entrar**. Generarla con:

  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```

  y pegar el resultado después del `=`. Cada instalación usa la suya.

## 4. Base de datos

Solo la primera vez (los scripts no borran datos y se pueden correr varias veces).

Para no escribir la URI (con la contraseña) en la terminal, en PowerShell se puede leer del `.env`:

```powershell
$uri = (Get-Content .env | Select-String '^MONGODB_URI=').Line.Substring(12)
mongosh $uri --file scripts/crear_colecciones.js
mongosh $uri --file scripts/crear_colecciones_ventas.js
mongosh $uri --file scripts/crear_colecciones_contactos.js
```

En Linux o macOS, lo mismo con `mongosh "<URI>" --file ...`.

Cuentas para entrar al sistema (si la base es nueva o todavía no existen):

```bash
php scripts/crear_usuarios_prueba.php
```

Crea `admin@example.com` y `comunidad@example.com`. Sus contraseñas están en el script; en el servidor público hay que cambiarlas o borrar estas cuentas.

Datos de prueba, en una base aparte para no tocar los datos compartidos (opcional: carga 100 000 ventas y el clúster M0 tiene 512 MB en total):

```bash
mongosh "<URI>/adi_prueba" --file scripts/cargar_datos.js
```

## 5. Verificación

1. Levantar el sitio:
   - **Con XAMPP o Apache:** `http://localhost/proyecto_adi/`.
   - **Sin Apache:** desde la carpeta del proyecto, `php -S localhost:8000` (dejar esa terminal abierta) y usar `http://localhost:8000/`. El servidor integrado no aplica el `.htaccess`, así que solo sirve para probar en la computadora.
2. Abrir `diagnostico.php`. **Sin sesión** salen 5 verificaciones en OK y "Escritura en la base" dice *Solo admin*.
3. Entrar en `login.php` como `admin@example.com` (marcar "No soy un robot") y volver a `diagnostico.php`: ahora las **6** deben salir en OK.
4. En la portada, entrar a contactos, alquileres y ventas y crear un registro de prueba en cada módulo, con datos ficticios (`@example.test`). Borrarlos al terminar si la base es la compartida.

| Síntoma | Causa probable |
| --- | --- |
| `php` o `mongosh` "no se reconoce como nombre de un cmdlet" | La carpeta no está en el `PATH` o la terminal se abrió antes de agregarla |
| La portada dice "No se pudo conectar con la base de datos" | Ver `diagnostico.php` para saber qué falla |
| "Extensión mongodb" en Falla | DLL que no corresponde a la versión de PHP (TS/NTS, 8.2/8.3), o falta `extension=mongodb` |
| "vendor/autoload.php" en Falla | Falta `composer install` |
| `composer install` falla con "zip extension ... missing" | Falta `extension=zip` en `php.ini` |
| `querySrv ENOTFOUND _mongodb._tcp.cluster0.xxxxx.mongodb.net` | El `.env` sigue con la URI de ejemplo: no se cambió o no se guardó |
| "Conexión" en Falla tras 5 s, o un error `TLS handshake failed` / `SSL alert number 80` / `tlsv1 alert internal error` | La IP no está en Network Access de Atlas (o cambió desde ayer) |
| "Conexión" en Falla por autenticación | Usuario o contraseña incorrectos en `MONGODB_URI` |
| El login dice "El formulario no está disponible: falta configurar el captcha" | `ALTCHA_HMAC_KEY` vacía en `.env` |
| El login no acepta `admin@example.com` | Faltan las cuentas: `php scripts/crear_usuarios_prueba.php` |

## 6. Servidor Virtualmin

El procedimiento de despliegue y actualización está en la sección **Despliegue en el servidor** del [README](../README.md).

## Registro de la prueba desde cero

Prueba hecha por Hazel Molina el 26/10/2026 (Issue #51): clon nuevo en una carpeta vacía, con un `.env` nuevo creado desde `.env.example`, siguiendo solo esta guía.

| Fecha | Máquina / sistema | Paso | Problema encontrado | Corrección |
| --- | --- | --- | --- | --- |
| 26/10/2026 | Windows, PHP 8.3.35 NTS independiente (sin XAMPP), extensión `mongodb` 2.4.1, Composer 2.10, Git 2.55 | 1. Requisitos | La guía solo explicaba XAMPP | Nueva sección "Windows sin XAMPP" |
| 26/10/2026 | ídem | 1. Requisitos | `mongosh` instalado pero "no se reconoce" en una terminal nueva; la guía no decía cómo instalarlo ni ponerlo en el `PATH` | Secciones "`mongosh` en Windows" y "Agregar una carpeta al `PATH`" |
| 26/10/2026 | ídem | 3. Código | `git clone`, `composer install` y `copy .env.example .env` sin problemas | — |
| 26/10/2026 | ídem | 3. Código | `ALTCHA_HMAC_KEY` está en `.env.example` pero la guía no la mencionaba; sin ella el login queda bloqueado | Se documenta como obligatoria, con el comando para generarla |
| 26/10/2026 | ídem | 4. Base de datos | Primer intento con `querySrv ENOTFOUND ...xxxxx...`: el `.env` no se había guardado con la URI real | Se aclara "y guardar" y se agrega el síntoma a la tabla |
| 26/10/2026 | ídem | 4. Base de datos | Error `SSL alert number 80` / "Network Access List": la IP del día no estaba en Atlas. La tabla solo hablaba de "Falla tras 5 s" | Aviso en el paso 2 y síntoma TLS en la tabla |
| 26/10/2026 | ídem | 4. Base de datos | Los tres `crear_colecciones*.js` corrieron bien y no tocaron los datos | — |
| 26/10/2026 | ídem | 4. Base de datos | `cargar_datos.js` no se ejecutó: agrega 100 000 ventas en otra base del mismo clúster M0 (512 MB) | Marcado como opcional, con la advertencia |
| 26/10/2026 | ídem | 4. Base de datos | La guía no mencionaba `crear_usuarios_prueba.php` (solo el README): en una base nueva nadie podría entrar. En la prueba funcionó porque las cuentas ya existen en `adi` | Paso agregado en la sección 4 |
| 26/10/2026 | ídem | 5. Verificación | Solo había direcciones de Apache/XAMPP; se probó con `php -S localhost:8001` | Opción "Sin Apache" en el paso 1 |
| 26/10/2026 | ídem | 5. Verificación | Sin sesión salen 5 OK y "Solo admin", no 6; la guía decía 6 | Pasos 2 y 3 separan sin sesión y con sesión de admin |
| 26/10/2026 | ídem | 5. Verificación | Con sesión de admin, 6 de 6 en OK; se creó un registro en contactos, alquileres y ventas sin errores. Quedaron en la base con datos ficticios ("Prueba Instalación", `prueba@example.test`) | — |
