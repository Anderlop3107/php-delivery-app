<?php
require_once __DIR__ . '/../lib/db.php';

try {
    $db = app_db();
    echo "Modificando la columna 'role' de la tabla 'users' para incluir 'admin'...\n";
    $db->query("ALTER TABLE users MODIFY COLUMN role ENUM('local', 'repartidor', 'cliente', 'admin') NOT NULL");
    echo "¡Estructura de la base de datos modificada con éxito!\n";
} catch (Throwable $e) {
    echo "Error al modificar la columna 'role': " . $e->getMessage() . "\n";
    exit(1);
}
