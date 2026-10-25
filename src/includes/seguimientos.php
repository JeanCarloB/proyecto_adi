<?php
/**
 * ADIministra · Utilidades compartidas del módulo de seguimientos.
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/layout.php';

requiereRol('admin');   // solo los administradorespueden ver el modulo 

const TIPOS_SEGUIMIENTO = [
    'nota'         => 'Nota',
    'recordatorio' => 'Recordatorio',
];

const ESTADOS_SEGUIMIENTO = [
    'pendiente'  => 'Pendiente',
    'completado' => 'Completado',
    'cancelado'  => 'Cancelado',
];
