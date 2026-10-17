<?php
/**
 * ADIministra · Utilidades compartidas del módulo de alquiler de espacios.
 *
 * Carga la conexión ($baseDatos) y el diseño compartido (layout.php), y define
 * funciones de apoyo para las vistas y para convertir datos al formato
 * que exige el JSON Schema de espacios y reservas.
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/layout.php';   // RAIZ, e(), encabezado(), pie(), avisos

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Deben coincidir con el enum del JSON Schema (scripts/crear_colecciones.js). */
const FRANJAS = ['mañana' => 'Mañana', 'tarde' => 'Tarde', 'noche' => 'Noche'];
const TIPOS_ESPACIO = ['salon' => 'Salón', 'cancha' => 'Cancha'];

/** Horas que dura un apartado antes de que el índice TTL lo elimine. */
const HORAS_APARTADO = 48;

/** Zona horaria para mostrar horas (vencimientos). Las fechas de reserva se guardan en UTC. */
const ZONA_LOCAL = 'America/Costa_Rica';

// --- Vista ------------------------------------------------------------------

function dinero(mixed $monto): string
{
    if ($monto === null || $monto === '') {
        return '—';
    }
    return '₡' . number_format((float) (string) $monto, 0, ',', '.');
}

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const DIAS  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

/** Fecha de una reserva (medianoche UTC) como día calendario: "17 oct 2026". */
function diaLargo(UTCDateTime $fecha): string
{
    $d = $fecha->toDateTime()->setTimezone(new DateTimeZone('UTC'));
    return $d->format('j') . ' ' . MESES[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
}

/** Día de la semana de una reserva: "viernes". */
function diaSemana(UTCDateTime $fecha): string
{
    $d = $fecha->toDateTime()->setTimezone(new DateTimeZone('UTC'));
    return DIAS[(int) $d->format('w')];
}

/** Un instante (por ejemplo "vence") en hora de Costa Rica: "18 oct · 21:13". */
function mostrarHora(UTCDateTime $fecha): string
{
    $d = $fecha->toDateTime()->setTimezone(new DateTimeZone(ZONA_LOCAL));
    return $d->format('j') . ' ' . MESES[(int) $d->format('n') - 1] . ' · ' . $d->format('H:i');
}

// --- Datos ------------------------------------------------------------------

/** Lee un valor de formulario como texto. Si llega otra cosa (por ejemplo un arreglo), devuelve vacío. */
function texto(mixed $valor): string
{
    return is_string($valor) ? trim($valor) : '';
}

/** Convierte el texto de un formulario en Decimal128, el tipo que exige el esquema para dinero. */
function decimal(string $monto): Decimal128
{
    return new Decimal128(number_format((float) $monto, 2, '.', ''));
}

/** Devuelve el ObjectId si el texto es un id válido de 24 caracteres hexadecimales, o null. */
function idValido(mixed $id): ?ObjectId
{
    return is_string($id) && preg_match('/^[a-f0-9]{24}$/i', $id) ? new ObjectId($id) : null;
}

/**
 * "2026-10-20" → medianoche UTC de ese día.
 *
 * Es el mismo formato que usa scripts/cargar_datos.php. Si dos reservas del
 * mismo día no quedaran exactamente en el mismo instante, el índice único
 * {espacio_id, fecha, franja} no detectaría el doble alquiler.
 */
function diaUtc(string $fecha): ?UTCDateTime
{
    $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone('UTC'));
    if (!$dia || $dia->format('Y-m-d') !== $fecha) {
        return null;
    }
    return new UTCDateTime($dia);
}

function hoyLocal(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(ZONA_LOCAL)))->format('Y-m-d');
}

/**
 * Código del error que devolvió MongoDB.
 *   11000 → clave duplicada (índice único)
 *   121   → el documento no cumple el validador JSON Schema
 */
function codigoError(Throwable $e): int
{
    if ($e instanceof MongoDB\Driver\Exception\BulkWriteException) {
        $errores = $e->getWriteResult()->getWriteErrors();
        if ($errores) {
            return $errores[0]->getCode();
        }
    }
    if (str_contains($e->getMessage(), 'E11000')) {
        return 11000;
    }
    return (int) $e->getCode();
}
