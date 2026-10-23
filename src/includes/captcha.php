<?php
/**
 * ADIministra · Captcha ALTCHA (open source, sin servicios externos).
 *
 * ALTCHA no pide resolver imágenes: el navegador resuelve una pequeña
 * "prueba de trabajo" (encontrar un número que, junto a un valor aleatorio,
 * produzca cierto hash SHA-256). A una persona le toma un segundo; a un bot
 * que manda miles de formularios le sale caro. No usa cookies ni rastreo.
 *
 * Flujo:
 *   1. captchaReto()      → el servidor genera el reto y lo firma con HMAC.
 *   2. <altcha-widget>    → el navegador lo resuelve y envía el campo "altcha".
 *   3. captchaVerificar() → el servidor revisa la firma, el vencimiento y la
 *                           solución. Devuelve el reto para guardarlo: el índice
 *                           único de solicitudes.captcha impide reutilizarlo.
 *
 * Necesita ALTCHA_HMAC_KEY en .env (una clave aleatoria de al menos 32 caracteres).
 * Widget: https://github.com/altcha-org/altcha (licencia MIT), versión 2.x.
 */

/** Número máximo que el navegador puede tener que probar (dificultad). */
const CAPTCHA_MAXIMO = 100000;

/** Minutos que dura un reto antes de vencer. */
const CAPTCHA_MINUTOS = 20;

/** Versión fija del widget (protocolo v1 de ALTCHA, compatible con esta verificación). */
const CAPTCHA_WIDGET = 'https://cdn.jsdelivr.net/npm/altcha@2.3.0/dist/altcha.js';

function captchaClave(): string
{
    global $env;
    $clave = $env['ALTCHA_HMAC_KEY'] ?? '';
    if (strlen($clave) < 32) {
        throw new RuntimeException('Falta ALTCHA_HMAC_KEY en .env (mínimo 32 caracteres). Vea .env.example.');
    }
    return $clave;
}

/** Genera un reto nuevo, firmado, para entregarlo al widget. */
function captchaReto(): array
{
    $salt   = bin2hex(random_bytes(12)) . '?expires=' . (time() + CAPTCHA_MINUTOS * 60);
    $numero = random_int(1000, CAPTCHA_MAXIMO);
    $reto   = hash('sha256', $salt . $numero);

    return [
        'algorithm' => 'SHA-256',
        'challenge' => $reto,
        'maxnumber' => CAPTCHA_MAXIMO,
        'salt'      => $salt,
        'signature' => hash_hmac('sha256', $reto, captchaClave()),
    ];
}

/**
 * Revisa la respuesta del widget (campo "altcha", JSON en base64).
 * Devuelve el reto resuelto si es válido, o null si no lo es.
 */
function captchaVerificar(mixed $respuesta): ?string
{
    if (!is_string($respuesta) || $respuesta === '' || strlen($respuesta) > 2000) {
        return null;
    }

    $datos = json_decode((string) base64_decode($respuesta, true), true);
    if (!is_array($datos)) {
        return null;
    }

    $reto   = $datos['challenge'] ?? null;
    $salt   = $datos['salt'] ?? null;
    $numero = $datos['number'] ?? null;
    $firma  = $datos['signature'] ?? null;

    if (($datos['algorithm'] ?? '') !== 'SHA-256'
        || !is_string($reto) || !is_string($salt) || !is_string($firma) || !is_int($numero)) {
        return null;
    }

    // El salt lleva su vencimiento: "...?expires=1761234567"
    parse_str((string) parse_url('?' . substr($salt, (int) strpos($salt, '?') + 1), PHP_URL_QUERY), $parametros);
    if (!isset($parametros['expires']) || (int) $parametros['expires'] < time()) {
        return null;
    }

    // 1) La firma demuestra que el reto lo generó este servidor (no se puede inventar uno fácil).
    // 2) El hash demuestra que el navegador hizo el trabajo y encontró el número.
    if (!hash_equals(hash_hmac('sha256', $reto, captchaClave()), $firma)
        || !hash_equals($reto, hash('sha256', $salt . $numero))) {
        return null;
    }

    return $reto;
}

/** Imprime el widget dentro de un formulario, con textos en español. */
function captchaWidget(): void
{
    $textos = [
        'label'     => 'No soy un robot',
        'verifying' => 'Verificando…',
        'verified'  => 'Verificado',
        'error'     => 'No se pudo verificar. Intentá de nuevo.',
        'expired'   => 'La verificación venció. Intentá de nuevo.',
        'waitAlert' => 'Verificando… un momento.',
        'ariaLinkLabel' => 'Visitar altcha.org',
        'footer'    => 'Protegido por <a href="https://altcha.org/" target="_blank" rel="noopener">ALTCHA</a>',
    ];
    try {
        $reto = captchaReto();
    } catch (RuntimeException $e) {
        // Sin clave no se puede generar el reto: se avisa en vez de cortar la página.
        aviso('danger', 'El formulario no está disponible: falta configurar el captcha (ALTCHA_HMAC_KEY en .env).');
        return;
    }
    ?>
    <script async defer src="<?= e(CAPTCHA_WIDGET) ?>" type="module"></script>
    <altcha-widget
        challengejson="<?= e(json_encode($reto)) ?>"
        strings="<?= e(json_encode($textos, JSON_UNESCAPED_UNICODE)) ?>"
        hidelogo></altcha-widget>
    <?php
}
