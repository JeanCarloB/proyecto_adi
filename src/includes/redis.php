<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Devuelve una conexión a Redis.
 * Si Redis no está disponible, devuelve null para permitir
 * que la aplicación continúe sin utilizar la caché.
 */
function conexionRedis(): ?Predis\Client
{
    static $redis = null;
    static $inicializado = false;

    if ($inicializado) {
        return $redis;
    }

    $inicializado = true;

    try {
        $redis = new Predis\Client([
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'connect_timeout' => 1,
        ]);

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
 * Invalida los resúmenes de ventas almacenados en Redis.
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
                'administra:ventas:resumen:*',
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
