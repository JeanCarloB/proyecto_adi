
<?php
/**
 * ADIministra · Utilidades compartidas del módulo de seguimientos.
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/layout.php';

requiereLogin();   // todas las páginas del módulo exigen sesión

const TIPOS_SEGUIMIENTO = [
    'nota'         => 'Nota',
    'recordatorio' => 'Recordatorio',
];

const ESTADOS_SEGUIMIENTO = [
    'pendiente'  => 'Pendiente',
    'completado' => 'Completado',
    'cancelado'  => 'Cancelado',
];
