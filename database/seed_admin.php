<?php
require_once __DIR__ . '/../lib/db.php';

try {
    $db = app_db();
    
    // Verificar si el admin ya existe en la base de datos
    $existing = app_one("SELECT id FROM users WHERE email = 'admin@delivery.com'");
    if ($existing) {
        echo "El usuario administrador (admin@delivery.com) ya existe.\n";
    } else {
        echo "Insertando administrador de prueba (admin@delivery.com / admin123)...\n";
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        app_exec("
            INSERT INTO users (role, name, email, password_hash, subscription_status, created_at, updated_at)
            VALUES ('admin', 'Administrador General', 'admin@delivery.com', ?, 'active', NOW(), NOW())
        ", 's', [$hash]);
        echo "¡Administrador creado con éxito!\n";
    }
} catch (Throwable $e) {
    echo "Error al crear el administrador: " . $e->getMessage() . "\n";
    exit(1);
}
