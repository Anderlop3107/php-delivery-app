<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/deliveries.php';

echo "=========================================================\n";
echo "    INICIANDO SIMULACIÓN DE PRUEBAS E2E (END-TO-END)     \n";
echo "=========================================================\n\n";

$localId = 1;      // ID del Local de pruebas
$driverId = 3;     // ID del Conductor Carlos Lopez

// ============================================================================
// PRUEBA 1: Flujo Normal de Pedido (Creación -> Aceptación -> Retiro -> Entrega)
// ============================================================================
echo "---------------------------------------------------------\n";
echo "PRUEBA 1: FLUJO NORMAL (LOCAL -> DRIVER -> ENTREGADO)\n";
echo "---------------------------------------------------------\n";

// 1. El Local crea el pedido
echo "[LOCAL] Creando un nuevo pedido...\n";
$cName = "Cliente Prueba E2E 1";
$cPhone = "0981111222";
$address = "Avda. España c/ Brasilia, Asunción";
$desc = "Hamburguesa doble con papas fritas";
$amount = 45000.0;
$cost = 10000.0;
$driverPays = 1; // El conductor le paga al local al retirar
$feePayer = "cliente";
$pickupLat = -25.2750; $pickupLng = -57.5850;
$destLat = -25.2680; $destLng = -57.5750;

app_exec("
    INSERT INTO deliveries (
        local_user_id, customer_name, customer_phone, delivery_address, 
        order_description, amount, delivery_cost, driver_pays_local, delivery_fee_payer,
        pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude, 
        status, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW())
", 'issssddisdddd', [
    $localId, $cName, $cPhone, $address, 
    $desc, $amount, $cost, $driverPays, $feePayer,
    $pickupLat, $pickupLng, $destLat, $destLng
]);

// Obtener el ID del pedido recién creado
$orderId1 = app_db()->insert_id;
echo "[OK] Pedido #$orderId1 creado con éxito en estado 'pendiente'.\n";

// Verificar en BD
$order = app_one("SELECT status, repartidor_user_id FROM deliveries WHERE id = ?", 'i', [$orderId1]);
if ($order && $order['status'] === 'pendiente') {
    echo "[OK] Verificación 1.1: El pedido se guardó como 'pendiente' en la base de datos.\n";
} else {
    echo "[FAIL] Verificación 1.1: Estado incorrecto.\n";
    exit(1);
}

// 2. El Conductor acepta el pedido
echo "[CONDUCTOR] Conductor Carlos Lopez (#$driverId) acepta el pedido #$orderId1...\n";
// Lógica de api_accept_order.php
$updated = app_exec("
    UPDATE deliveries 
    SET repartidor_user_id = ?, 
        status = 'aceptado', 
        reservado_para_repartidor_id = NULL, 
        reserva_expira_en = NULL, 
        updated_at = NOW()
    WHERE id = ? AND status = 'pendiente' AND repartidor_user_id IS NULL
", 'ii', [$driverId, $orderId1]);

if ($updated > 0) {
    echo "[OK] Pedido #$orderId1 asignado y aceptado por el conductor.\n";
    // Escribir log de estado
    update_delivery_status($orderId1, 'aceptado', $driverId, 'Pedido aceptado por el conductor');
} else {
    echo "[FAIL] No se pudo aceptar el pedido.\n";
    exit(1);
}

// Verificar en BD
$order = app_one("SELECT status, repartidor_user_id FROM deliveries WHERE id = ?", 'i', [$orderId1]);
if ($order && $order['status'] === 'aceptado' && (int)$order['repartidor_user_id'] === $driverId) {
    echo "[OK] Verificación 1.2: Base de datos actualizada con repartidor_user_id = $driverId y status = 'aceptado'.\n";
} else {
    echo "[FAIL] Verificación 1.2: Asignación incorrecta.\n";
    exit(1);
}

// 3. El Conductor retira el pedido del Local
echo "[CONDUCTOR] Retirando el pedido en el local...\n";
$success = update_delivery_status($orderId1, 'en_camino_al_cliente', $driverId, 'Pedido en_camino_al_cliente del local');
if ($success) {
    echo "[OK] Estado actualizado a 'en_camino_al_cliente'.\n";
} else {
    echo "[FAIL] Error al actualizar a 'en_camino_al_cliente'.\n";
    exit(1);
}

// Verificar en BD
$order = app_one("SELECT status FROM deliveries WHERE id = ?", 'i', [$orderId1]);
if ($order && $order['status'] === 'en_camino_al_cliente') {
    echo "[OK] Verificación 1.3: Estado 'en_camino_al_cliente' confirmado en la base de datos.\n";
} else {
    echo "[FAIL] Verificación 1.3: Estado incorrecto.\n";
    exit(1);
}

// 4. El Conductor entrega el pedido al cliente
echo "[CONDUCTOR] Pedido entregado al cliente final...\n";
$success = update_delivery_status($orderId1, 'entregado', $driverId, 'Pedido entregado en destino');
if ($success) {
    echo "[OK] Estado actualizado a 'entregado'.\n";
} else {
    echo "[FAIL] Error al actualizar a 'entregado'.\n";
    exit(1);
}

// Verificar en BD
$order = app_one("SELECT status FROM deliveries WHERE id = ?", 'i', [$orderId1]);
if ($order && $order['status'] === 'entregado') {
    echo "[OK] Verificación 1.4: Estado final 'entregado' confirmado en la base de datos.\n";
} else {
    echo "[FAIL] Verificación 1.4: Estado incorrecto.\n";
    exit(1);
}


// ============================================================================
// PRUEBA 2: Flujo de Cancelación por parte del Local
// ============================================================================
echo "\n---------------------------------------------------------\n";
echo "PRUEBA 2: FLUJO DE CANCELACIÓN (LOCAL -> CANCELADO)\n";
echo "---------------------------------------------------------\n";

// 1. El Local crea el pedido
echo "[LOCAL] Creando pedido #2...\n";
app_exec("
    INSERT INTO deliveries (
        local_user_id, customer_name, customer_phone, delivery_address, 
        order_description, amount, delivery_cost, status, created_at
    ) VALUES (?, 'Cliente Cancel E2E', '0999000111', 'Calle Mcal. Lopez, Asuncion', 'Hamburguesa Simple', 25000, 8000, 'pendiente', NOW())
", 'i', [$localId]);

$orderId2 = app_db()->insert_id;
echo "[OK] Pedido #$orderId2 creado como 'pendiente'.\n";

// 2. El Local cancela el pedido (api_update_status.php logic)
echo "[LOCAL] Local cancela el pedido #$orderId2...\n";
$success = update_delivery_status($orderId2, 'cancelado', $localId, 'Pedido cancelado por el comercio antes de asignación');
if ($success) {
    echo "[OK] Estado actualizado a 'cancelado'.\n";
} else {
    echo "[FAIL] Error al cancelar el pedido.\n";
    exit(1);
}

// Verificar en BD
$order = app_one("SELECT status FROM deliveries WHERE id = ?", 'i', [$orderId2]);
if ($order && $order['status'] === 'cancelado') {
    echo "[OK] Verificación 2.1: El pedido #$orderId2 figura como 'cancelado' en la base de datos.\n";
} else {
    echo "[FAIL] Verificación 2.1: Estado incorrecto.\n";
    exit(1);
}


// ============================================================================
// PRUEBA 3: Conectividad y Sincronización del Mapa de Monitoreo
// ============================================================================
echo "\n---------------------------------------------------------\n";
echo "PRUEBA 3: CONECTIVIDAD DEL DRIVER Y ESTADOS EN EL MAPA\n";
echo "---------------------------------------------------------\n";

// 1. El conductor se desconecta (is_online = 0)
echo "[DRIVER] Conductor Carlos Lopez apaga su disponibilidad...\n";
app_exec("UPDATE users SET is_online = 0 WHERE id = ?", 'i', [$driverId]);

// El admin consulta los conductores activos para el mapa
$liveDrivers = app_all("SELECT id, name FROM users WHERE role = 'repartidor' AND is_online = 1 AND latitude IS NOT NULL");
$found = false;
foreach ($liveDrivers as $ld) {
    if ((int)$ld['id'] === $driverId) {
        $found = true;
    }
}
if (!$found) {
    echo "[OK] Verificación 3.1: Conductor desconectado NO aparece en el mapa del administrador.\n";
} else {
    echo "[FAIL] Verificación 3.1: Conductor offline sigue visible en el mapa.\n";
    exit(1);
}

// 2. El conductor se conecta (is_online = 1) y actualiza ubicación
echo "[DRIVER] Conductor Carlos Lopez activa su turno en la app (is_online = 1, actualiza GPS)...\n";
app_exec("UPDATE users SET is_online = 1, latitude = -25.2685, longitude = -57.6405 WHERE id = ?", 'i', [$driverId]);

// El admin vuelve a consultar
$liveDrivers = app_all("
    SELECT id, name, logo_path as avatar_path, latitude, longitude, is_online, 
           (SELECT COUNT(*) FROM deliveries WHERE repartidor_user_id = users.id AND status NOT IN ('entregado', 'cancelado')) as active_delivery_count
    FROM users 
    WHERE role = 'repartidor' AND is_online = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
");

$targetDriver = null;
foreach ($liveDrivers as $ld) {
    if ((int)$ld['id'] === $driverId) {
        $targetDriver = $ld;
    }
}

if ($targetDriver) {
    echo "[OK] Verificación 3.2: Conductor online visible en el mapa.\n";
    echo "     - Ubicación: [Lat: {$targetDriver['latitude']}, Lng: {$targetDriver['longitude']}]\n";
    echo "     - Pedidos Activos en Curso: {$targetDriver['active_delivery_count']}\n";
    
    if ((int)$targetDriver['active_delivery_count'] === 0) {
        echo "[OK] Verificación 3.3: Conductor figura como disponible (sin entregas) -> Estado: online (Borde Verde).\n";
    } else {
        echo "[FAIL] Verificación 3.3: Conductor tiene pedidos falsamente.\n";
        exit(1);
    }
} else {
    echo "[FAIL] Conductor activo no figura en la consulta de mapa del administrador.\n";
    exit(1);
}

// 3. El Local crea un pedido y el Conductor lo acepta (Cambiando su estado a 'delivering' / ocupado)
echo "[LOCAL] Creando pedido #3...\n";
app_exec("
    INSERT INTO deliveries (
        local_user_id, customer_name, customer_phone, delivery_address, 
        order_description, amount, delivery_cost, status, created_at
    ) VALUES (?, 'Cliente Ocupado E2E', '0971444555', 'Avda. Boggiani, Asuncion', 'Hamburguesa Triple', 55000, 12000, 'pendiente', NOW())
", 'i', [$localId]);
$orderId3 = app_db()->insert_id;

echo "[CONDUCTOR] Conductor acepta el pedido #$orderId3...\n";
app_exec("UPDATE deliveries SET repartidor_user_id = ?, status = 'aceptado' WHERE id = ?", 'ii', [$driverId, $orderId3]);

// El admin consulta el estado del mapa
$liveDrivers = app_all("
    SELECT id, name, is_online, 
           (SELECT COUNT(*) FROM deliveries WHERE repartidor_user_id = users.id AND status NOT IN ('entregado', 'cancelado')) as active_delivery_count
    FROM users 
    WHERE role = 'repartidor' AND is_online = 1
");

$targetDriver = null;
foreach ($liveDrivers as $ld) {
    if ((int)$ld['id'] === $driverId) {
        $targetDriver = $ld;
    }
}

if ($targetDriver && (int)$targetDriver['active_delivery_count'] > 0) {
    echo "[OK] Verificación 3.4: Conductor figura con pedido activo ({$targetDriver['active_delivery_count']}) -> Estado: delivering (Borde Naranja Pulsante).\n";
} else {
    echo "[FAIL] Verificación 3.4: El estado no cambió a ocupado.\n";
    exit(1);
}

// Limpiar el pedido #3 de prueba (marcar como entregado) para no dejar basura en la BD
app_exec("UPDATE deliveries SET status = 'entregado' WHERE id = ?", 'i', [$orderId3]);
echo "[SYSTEM] Limpieza completada. Pedido #3 marcado como entregado.\n";

echo "\n=========================================================\n";
echo "     ¡TODAS LAS PRUEBAS COMPLETADAS CON ÉXITO [100%]!    \n";
echo "=========================================================\n";
