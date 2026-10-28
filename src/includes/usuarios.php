<?php
/**
 * ADIministra · Utilidades del módulo de usuarios (solo administración).
 *
 * Carga la conexión ($baseDatos) y el diseño compartido (layout.php).
 * Colección usuarios: {correo, nombre?, contrasena (hash), rol, activo?, creado}
 * con el índice único correo_unico. Sin el campo "activo" la cuenta cuenta
 * como activa (igual que las creadas con scripts/crear_usuarios_prueba.php).
 */

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/layout.php';   // RAIZ, e(), encabezado(), pie(), avisos

requiereRol('admin');   // solo administración registra y cambia usuarios

/** Largo mínimo de una contraseña nueva. */
const CONTRASENA_MIN = 8;

function coleccionUsuarios(MongoDB\Database $baseDatos): Collection
{
    $usuarios = $baseDatos->selectCollection('usuarios');
    // Idempotente: si el índice ya existe (lo crea también el script de prueba) no hace nada.
    $usuarios->createIndex(['correo' => 1], ['unique' => true, 'name' => 'correo_unico']);
    return $usuarios;
}

/** ObjectId del usuario que llega en el formulario, o null si no es válido. */
function idUsuario(mixed $id): ?ObjectId
{
    return is_string($id) && preg_match('/^[a-f0-9]{24}$/i', $id) ? new ObjectId($id) : null;
}

/** Nadie puede quitarse a sí mismo el rol de admin ni desactivarse: así siempre queda al menos un admin. */
function esUsuarioActual(ObjectId $id): bool
{
    return (usuarioActual()['id'] ?? '') === (string) $id;
}

/** true si MongoDB rechazó la escritura por el índice único (correo repetido). */
function correoRepetido(Throwable $e): bool
{
    if ($e instanceof MongoDB\Driver\Exception\BulkWriteException) {
        foreach ($e->getWriteResult()->getWriteErrors() as $error) {
            if ($error->getCode() === 11000) {
                return true;
            }
        }
    }
    return str_contains($e->getMessage(), 'E11000');
}
