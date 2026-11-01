<?php
/**
 * Caché opcional con Redis para el resumen de ventas (PR #119).
 *
 * MongoDB sigue siendo la única fuente de verdad: Redis solo guarda por 60 s el
 * total y la cantidad de ventas que calcula el aggregation de src/ventas/index.php.
 *
 * Configuración en .env (ver .env.example):
 *   REDIS_HOST      vacío = sin caché (la aplicación funciona igual, todo sale de MongoDB)
 *   REDIS_PORT      6379 por defecto
 *   REDIS_PASSWORD  opcional
 *   REDIS_PREFIJO   prefijo de TODAS las claves; el enunciado exige "grupoX:" en el
 *                   Redis compartido del servidor, así que por defecto es "grupo4:adi:"
 *
 * Si Redis no responde, conexionRedis() devuelve null y la página sigue sin caché.
 * Nunca se usa FLUSHALL ni FLUSHDB: se invalida solo lo nuestro, por prefijo.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Segundos que vive un resumen de ventas en la caché. */
const REDIS_TTL_RESUMEN = 60;

/** Lee una variable de Redis del .env que ya cargó conexion.php. */
function configRedis(string $clave, string $defecto = ''): string
{
    global $env;
    $valor = is_array($env ?? null) ? trim((string) ($env[$clave] ?? '')) : '';
    return $valor !== '' ? $valor : $defecto;
}

/** Arma una clave de Redis con el prefijo obligatorio del grupo. */
function claveRedis(string $resto): string
{
    return configRedis('REDIS_PREFIJO', 'grupo4:adi:') . $resto;
}

/**
 * Devuelve una conexión a Redis, o null si no está configurado o no responde,
 * para que la aplicación continúe sin utilizar la caché.
 */
function conexionRedis(): ?Predis\Client
{
    static $redis = null;
    static $inicializado = false;

    if ($inicializado) {
        return $redis;
    }
    $inicializado = true;

    $host = configRedis('REDIS_HOST');
    if ($host === '') {
        return null;   // caché desactivada en esta instalación
    }

    try {
        $parametros = [
            'scheme'          => 'tcp',
            'host'            => $host,
            'port'            => (int) configRedis('REDIS_PORT', '6379'),
            'timeout'         => 1,
            'read_write_timeout' => 1,
        ];
        $password = configRedis('REDIS_PASSWORD');
        if ($password !== '') {
            $parametros['password'] = $password;
        }

        $redis = new Predis\Client($parametros);
        $redis->connect();
        $redis->ping();

        return $redis;
    } catch (Throwable $e) {
        error_log('ADIministra: Redis no disponible: ' . $e->getMessage());
        $redis = null;

        return null;
    }
}

/**
 * Invalida los resúmenes de ventas guardados en Redis (al registrar, editar o
 * anular una venta). Borra solo las claves con nuestro prefijo, con SCAN.
 */
function invalidarCacheVentas(): void
{
    $redis = conexionRedis();

    if ($redis === null) {
        return;
    }

    try {
        $cursor = '0';

        do {
            $resultado = $redis->scan(
                $cursor,
                'MATCH',
                claveRedis('ventas:resumen:*'),
                'COUNT',
                100
            );

            $cursor = (string) $resultado[0];
            $claves = $resultado[1];

            if ($claves) {
                $redis->del($claves);
            }
        } while ($cursor !== '0');
    } catch (Throwable $e) {
        error_log(
            'ADIministra: no se pudo invalidar la caché de ventas: '
            . $e->getMessage()
        );
    }
}
