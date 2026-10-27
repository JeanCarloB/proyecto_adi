<?php
require_once __DIR__ . '/../src/includes/conexion.php';

foreach (['contactos', 'seguimientos'] as $nombre) {
    echo "\nÍndices de {$nombre}:\n";

    foreach ($baseDatos->selectCollection($nombre)->listIndexes() as $indice) {
        $info = iterator_to_array($indice);

        echo json_encode(
            $info,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }
}
