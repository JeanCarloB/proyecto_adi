<?php
/**
 * ADIministra · Utilidades compartidas del módulo de contactos.
 *
 * Carga la conexión ($baseDatos) y el diseño compartido (layout.php).
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/layout.php';   // RAIZ, e(), encabezado(), pie(), avisos

/** Tipos de contacto y cómo se muestran. */
const TIPOS_CONTACTO = [
    'vecino'      => 'Vecino',
    'comercio'    => 'Comercio',
    'institucion' => 'Institución',
];
