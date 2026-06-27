<?php
require_once __DIR__ . '/../lib/db.php';

try {
    $db = app_db();
    echo "Iniciando migración de base de datos...\n";

    // 1. Agregar ubicacion_actualizada_en en users si no existe
    $checkUsers = $db->query("SHOW COLUMNS FROM users LIKE 'ubicacion_actualizada_en'");
    if ($checkUsers->num_rows == 0) {
        $db->query("ALTER TABLE users ADD COLUMN ubicacion_actualizada_en DATETIME NULL AFTER updated_at");
        echo "Columna 'ubicacion_actualizada_en' agregada con éxito a la tabla 'users'.\n";
    } else {
        echo "La columna 'ubicacion_actualizada_en' ya existe en 'users'.\n";
    }

    // 2. Agregar reservado_para_repartidor_id en deliveries si no existe
    $checkDeliv1 = $db->query("SHOW COLUMNS FROM deliveries LIKE 'reservado_para_repartidor_id'");
    if ($checkDeliv1->num_rows == 0) {
        $db->query("ALTER TABLE deliveries ADD COLUMN reservado_para_repartidor_id INT NULL AFTER repartidor_user_id");
        // Intentar agregar llave foránea
        try {
            $db->query("ALTER TABLE deliveries ADD CONSTRAINT fk_deliveries_reservado_repartidor FOREIGN KEY (reservado_para_repartidor_id) REFERENCES users(id) ON DELETE SET NULL");
            echo "Columna 'reservado_para_repartidor_id' y FK agregadas con éxito a 'deliveries'.\n";
        } catch (Throwable $ex) {
            echo "Columna 'reservado_para_repartidor_id' agregada (FK omitida o ya existente: " . $ex->getMessage() . ").\n";
        }
    } else {
        echo "La columna 'reservado_para_repartidor_id' ya existe en 'deliveries'.\n";
    }

    // 3. Agregar reserva_expira_en en deliveries si no existe
    $checkDeliv2 = $db->query("SHOW COLUMNS FROM deliveries LIKE 'reserva_expira_en'");
    if ($checkDeliv2->num_rows == 0) {
        $db->query("ALTER TABLE deliveries ADD COLUMN reserva_expira_en DATETIME NULL AFTER reservado_para_repartidor_id");
        echo "Columna 'reserva_expira_en' agregada con éxito a 'deliveries'.\n";
    } else {
        echo "La columna 'reserva_expira_en' ya existe en 'deliveries'.\n";
    }

    echo "Migración completada con éxito.\n";

} catch (Throwable $e) {
    echo "ERROR en la migración: " . $e->getMessage() . "\n";
    exit(1);
}
