<?php
/**
 * Cerrar sesión. Solo por POST con token, para que un enlace o una imagen
 * de otro sitio no pueda sacar al usuario.
 */
require_once __DIR__ . '/src/includes/layout.php';   // incluye sesion.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido()) {
    http_response_code(405);
    exit('Use el botón "Salir" para cerrar la sesión.');
}

cerrarSesion();
header('Location: login.php?salio=1');
exit;
