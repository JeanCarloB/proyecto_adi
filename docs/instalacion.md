# Instalación desde cero

Guía completa para dejar ADIministra funcionando desde un clon limpio del repositorio. Se valida en el Seguimiento 4 instalando en una máquina nueva **siguiendo solo esta guía**; cada paso que falle o falte se anota al final y se corrige aquí.

## 1. Requisitos

| Componente | Versión | Cómo comprobarlo |
| --- | --- | --- |
| PHP | 8.1 o mayor (el servidor usa 8.3) | `php -v` |
| Extensión `mongodb` | 2.4 o mayor (la exige `mongodb/mongodb` 2.4 del `composer.lock`) | `php --ri mongodb` |
| Extensión `zip` | cualquiera (la usa Composer) | `php -m` |
| Composer | 2.x | `composer -V` |
| Git | cualquiera | `git --version` |
| `mongosh` | 2.x | `mongosh --version` |
| Apache | 2.4 con `AllowOverride All` (para `.htaccess`) | |

### Windows con XAMPP

1. Instalar XAMPP con **PHP 8.2** o mayor. XAMPP con PHP 8.0 no sirve.
2. Ver la versión exacta: `C:\xampp\php\php.exe -i | findstr /C:"PHP Version" /C:"Thread Safety" /C:"Architecture"`.
3. En [github.com/mongodb/mongo-php-driver/releases](https://github.com/mongodb/mongo-php-driver/releases), versión 2.4.x, bajar el zip que coincida (por ejemplo `php_mongodb-2.4.0-8.2-ts-vs16-x86_64.zip` para PHP 8.2 Thread Safe de 64 bits).
4. Copiar `php_mongodb.dll` a `C:\xampp\php\ext` y agregar `extension=mongodb` al final de `C:\xampp\php\php.ini`.
5. En el mismo `php.ini`, quitar el `;` de `;extension=zip`.
6. Si PHP dice "No se puede encontrar el módulo especificado", instalar el *Microsoft Visual C++ Redistributable 2015-2022 x64*.
7. Instalar Composer con `Composer-Setup.exe`, eligiendo `C:\xampp\php\php.exe`.
8. Reiniciar Apache desde el panel de XAMPP.

## 2. MongoDB Atlas

1. Pedir a Jean Carlo un usuario de base de datos (cada integrante tiene el suyo; el servidor usa el usuario de la aplicación).
2. En **Network Access**, agregar la IP de la máquina donde se instala.
3. Copiar la cadena de conexión desde **Database → Connect → Drivers**.

## 3. Código y dependencias

```bash
git clone https://github.com/JeanCarloB/proyecto_adi.git
cd proyecto_adi
composer install          # en el servidor: composer install --no-dev
cp .env.example .env      # en Windows: copy .env.example .env
```

Editar `.env`:

```
MONGODB_URI=mongodb+srv://USUARIO:CONTRASENA@cluster0.xxxxx.mongodb.net/
MONGODB_DB=adi
```

Si la contraseña tiene `@ : / ? # %`, codificarla en formato URL (por ejemplo `@` → `%40`).

## 4. Base de datos

Solo la primera vez (los scripts son idempotentes y no borran datos):

```bash
mongosh "<URI>" --file scripts/crear_colecciones.js
mongosh "<URI>" --file scripts/crear_colecciones_ventas.js
```

Datos de prueba, en una base aparte para no tocar los datos compartidos:

```bash
mongosh "<URI>/adi_prueba" --file scripts/cargar_datos.js
```

## 5. Verificación

1. Abrir `http://localhost/proyecto_adi/diagnostico.php` (o la misma ruta en el servidor).
2. Las 6 verificaciones deben salir en **OK**: PHP, extensión `mongodb`, `vendor/autoload.php`, `.env`, conexión y escritura.
3. Abrir la portada `http://localhost/proyecto_adi/`, entrar a contactos, alquileres y ventas y crear un registro en cada módulo.

| Síntoma | Causa probable |
| --- | --- |
| La portada dice "No se pudo conectar con la base de datos" | Ver `diagnostico.php` para saber qué falla |
| "Extensión mongodb" en Falla | DLL que no corresponde a la versión de PHP, o falta `extension=mongodb` |
| "vendor/autoload.php" en Falla | Falta `composer install` |
| `composer install` falla con "zip extension ... missing" | Falta `extension=zip` en `php.ini` |
| "Conexión" en Falla tras 5 s | La IP no está en Network Access |
| "Conexión" en Falla por autenticación | Usuario o contraseña incorrectos en `MONGODB_URI` |

## 6. Servidor Virtualmin

El procedimiento de despliegue y actualización está en la sección **Despliegue en el servidor** del [README](../README.md).

## Registro de la prueba desde cero

| Fecha | Máquina / sistema | Paso | Problema encontrado | Corrección |
| --- | --- | --- | --- | --- |
| | | | | |
