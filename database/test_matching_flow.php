<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/matching.php';

// Asegurar que el script se ejecute en CLI
if (PHP_SAPI !== 'cli') {
    die("Este script solo se puede ejecutar desde la consola de comandos.\n");
}

echo "=== INICIANDO SIMULADOR DE PRUEBAS DE GEO-EMPAREJAMIENTO ===\n\n";

// 1. Obtener usuarios de prueba
$driver = app_one("SELECT * FROM users WHERE role = 'repartidor' LIMIT 1");
$local = app_one("SELECT * FROM users WHERE role = 'local' LIMIT 1");

if (!$driver || !$local) {
    die("ERROR: Se necesita al menos un repartidor y un local en la base de datos para la prueba.\n");
}

$driverId = (int)$driver['id'];
$localId = (int)$local['id'];

echo "Repartidor de prueba: {$driver['name']} (ID: {$driverId})\n";
echo "Local de prueba:      {$local['business_name']} (ID: {$localId})\n\n";

// Guardar estado original del repartidor para restaurarlo al final
$originalLat = $driver['latitude'];
$originalLng = $driver['longitude'];
$originalOnline = $driver['is_online'];
$originalUpdated = $driver['ubicacion_actualizada_en'];

// Limpieza de datos de prueba anteriores
app_exec("DELETE FROM deliveries WHERE order_description LIKE '%[TEST_MATCHING]%'");

function assertTest(string $description, bool $condition) {
    if ($condition) {
        echo "🟢 [ÉXITO] $description\n";
    } else {
        echo "🔴 [FALLO] $description\n";
        exit(1);
    }
}

try {
    // ----------------------------------------------------
    // FASE A: Repartidor Libre (Estado 3)
    // ----------------------------------------------------
    echo "--- FASE A: Repartidor Libre (Estado 3) ---\n";
    
    // Configurar conductor: Ubicación Centro (Asunción) y activo hace 5 segundos
    app_exec("
        UPDATE users 
        SET latitude = -25.2637, longitude = -57.6359, 
            ubicacion_actualizada_en = DATE_SUB(NOW(), INTERVAL 5 SECOND), 
            is_online = 1 
        WHERE id = ?
    ", 'i', [$driverId]);

    // Crear un pedido cercano (Local de retiro a 300 metros del repartidor)
    app_exec("
        INSERT INTO deliveries (
            local_user_id, customer_name, customer_phone, order_description, 
            delivery_address, amount, delivery_cost, 
            pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude, 
            status, created_at, updated_at
        ) VALUES (?, 'Cliente Test A', '0981111111', '[TEST_MATCHING] Pedido Cercano', 
                  'Calle Test 123', 50000, 15000, 
                  -25.2650, -57.6380, -25.2690, -57.6410, 
                  'pendiente', NOW(), NOW())
    ", 'i', [$localId]);
    
    $orderA = app_one("SELECT * FROM deliveries WHERE order_description = '[TEST_MATCHING] Pedido Cercano' LIMIT 1");
    assertTest("Pedido de prueba Creado con éxito.", $orderA !== null);

    // Ejecutar emparejamiento
    $matched = obtener_pedidos_disponibles_para_repartidor($driverId);
    $found = false;
    foreach ($matched as $m) {
        if ((int)$m['id'] === (int)$orderA['id']) {
            $found = true;
            break;
        }
    }
    assertTest("El repartidor libre ve el pedido cercano.", $found);

    // ----------------------------------------------------
    // FASE B: Reserva Temporal Exclusiva (15 segundos)
    // ----------------------------------------------------
    echo "\n--- FASE B: Reserva Temporal Exclusiva ---\n";
    
    // Simular el api_check_new_orders.php que reserva el pedido
    $reserved = app_exec("
        UPDATE deliveries 
        SET reservado_para_repartidor_id = ?, 
            reserva_expira_en = DATE_ADD(NOW(), INTERVAL 15 SECOND)
        WHERE id = ? 
          AND status = 'pendiente'
          AND (reservado_para_repartidor_id IS NULL OR reservado_para_repartidor_id = ? OR reserva_expira_en < NOW())
    ", 'iii', [$driverId, (int)$orderA['id'], $driverId]);
    
    assertTest("El pedido fue reservado exitosamente para el conductor.", $reserved > 0);

    // Verificar que otro conductor ficticio (ID 9999) no pueda verlo ni reservarlo
    $matchedOther = app_all("
        SELECT id FROM deliveries 
        WHERE id = ? 
          AND status = 'pendiente'
          AND (reservado_para_repartidor_id IS NULL OR reservado_para_repartidor_id = 9999 OR reserva_expira_en < NOW())
    ", 'i', [(int)$orderA['id']]);
    
    assertTest("Otro repartidor no puede ver ni tomar el pedido reservado.", empty($matchedOther));

    // ----------------------------------------------------
    // FASE C: Doble Envío (Estado 1 - Pooling)
    // ----------------------------------------------------
    echo "\n--- FASE C: Doble Envío (Estado 1) ---\n";
    
    // Asignar primer pedido al conductor (Conductor ahora tiene estado ASIGNADO)
    app_exec("
        UPDATE deliveries 
        SET repartidor_user_id = ?, status = 'aceptado', 
            reservado_para_repartidor_id = NULL, reserva_expira_en = NULL 
        WHERE id = ?
    ", 'ii', [$driverId, (int)$orderA['id']]);

    // Crear un segundo pedido con Local B cercano (a 1.2 km del Local A) y clientes cercanos
    app_exec("
        INSERT INTO deliveries (
            local_user_id, customer_name, customer_phone, order_description, 
            delivery_address, amount, delivery_cost, 
            pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude, 
            status, created_at, updated_at
        ) VALUES (?, 'Cliente Test B', '0982222222', '[TEST_MATCHING] Pedido B (Cercano)', 
                  'Calle Test 456', 60000, 15000, 
                  -25.2700, -57.6400, -25.2720, -57.6430, 
                  'pendiente', NOW(), NOW())
    ", 'i', [$localId]);
    
    $orderB = app_one("SELECT * FROM deliveries WHERE order_description = '[TEST_MATCHING] Pedido B (Cercano)' LIMIT 1");

    // Crear un tercer pedido lejano (Local C a 12 km de A)
    app_exec("
        INSERT INTO deliveries (
            local_user_id, customer_name, customer_phone, order_description, 
            delivery_address, amount, delivery_cost, 
            pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude, 
            status, created_at, updated_at
        ) VALUES (?, 'Cliente Test C', '0983333333', '[TEST_MATCHING] Pedido C (Lejano)', 
                  'Calle Lejana 999', 40000, 25000, 
                  -25.3500, -57.7000, -25.3600, -57.7200, 
                  'pendiente', NOW(), NOW())
    ", 'i', [$localId]);
    
    $orderC = app_one("SELECT * FROM deliveries WHERE order_description = '[TEST_MATCHING] Pedido C (Lejano)' LIMIT 1");

    // Ejecutar emparejamiento para conductor asignado
    $matchedPooling = obtener_pedidos_disponibles_para_repartidor($driverId);
    
    $foundB = false;
    $foundC = false;
    foreach ($matchedPooling as $m) {
        if ((int)$m['id'] === (int)$orderB['id']) $foundB = true;
        if ((int)$m['id'] === (int)$orderC['id']) $foundC = true;
    }
    
    assertTest("Pooling: Conductor ve el Pedido B (Cercano a Local A).", $foundB);
    assertTest("Pooling: Conductor NO ve el Pedido C (Lejano a Local A).", !$foundC);

    // ----------------------------------------------------
    // FASE D: Notificación Anticipada (Estado 2)
    // ----------------------------------------------------
    echo "\n--- FASE D: Notificación Anticipada (Estado 2) ---\n";
    
    // Cambiar estado de Order A a 'en_camino_al_cliente' (Repartidor ahora está en tránsito)
    app_exec("UPDATE deliveries SET status = 'en_camino_al_cliente' WHERE id = ?", 'i', [(int)$orderA['id']]);

    // Posicionar conductor a 500 metros del Cliente A (está a menos de 1 km de entregar)
    app_exec("
        UPDATE users 
        SET latitude = -25.2685, longitude = -57.6405,
            ubicacion_actualizada_en = DATE_SUB(NOW(), INTERVAL 5 SECOND)
        WHERE id = ?
    ", 'i', [$driverId]);

    // Ejecutar emparejamiento
    $matchedAnticipated = obtener_pedidos_disponibles_para_repartidor($driverId);

    $foundAnticipatedB = false;
    foreach ($matchedAnticipated as $m) {
        if ((int)$m['id'] === (int)$orderB['id']) {
            $foundAnticipatedB = true;
            break;
        }
    }
    
    assertTest("Anticipated: Conductor ve el pedido de Local B cuando está a < 1 km de la entrega.", $foundAnticipatedB);

} finally {
    // ----------------------------------------------------
    // LIMPIEZA Y RESTAURACIÓN
    // ----------------------------------------------------
    echo "\n--- LIMPIEZA Y RESTAURACIÓN ---\n";
    
    // Eliminar registros de prueba
    app_exec("DELETE FROM deliveries WHERE order_description LIKE '%[TEST_MATCHING]%'");
    echo "Registros de prueba eliminados.\n";
    
    // Restaurar repartidor original
    app_exec("
        UPDATE users 
        SET latitude = ?, longitude = ?, is_online = ?, ubicacion_actualizada_en = ? 
        WHERE id = ?
    ", 'ddisi', [$originalLat, $originalLng, $originalOnline, $originalUpdated, $driverId]);
    echo "Estado original del repartidor restaurado.\n";
}

echo "\n=== PRUEBAS DE GEO-EMPAREJAMIENTO COMPLETADAS CON ÉXITO ===\n";
