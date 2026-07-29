<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/matching.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['has_orders' => false]);
    exit;
}

// Actualizar ping de actividad del repartidor
app_exec(
    "UPDATE users SET last_ping = NOW(), ubicacion_actualizada_en = COALESCE(ubicacion_actualizada_en, NOW()), updated_at = NOW() WHERE id = ? AND is_online = 1",
    'i',
    [(int)$user['id']]
);

// Obtener pedidos compatibles geográficamente y según el estado
$matchedOrders = obtener_pedidos_disponibles_para_repartidor((int)$user['id']);

$finalOrder = null;
if (!empty($matchedOrders)) {
    $finalOrder = $matchedOrders[0];
}

if ($finalOrder) {
    echo json_encode([
        'has_orders' => true,
        'order' => [
            'id' => (int)$finalOrder['id'],
            'local_name' => $finalOrder['local_name'],
            'local_lat' => (float)$finalOrder['local_lat'],
            'local_lng' => (float)$finalOrder['local_lng'],
            'dest_lat' => (float)$finalOrder['delivery_latitude'],
            'dest_lng' => (float)$finalOrder['delivery_longitude'],
            'dest_address' => $finalOrder['delivery_address'],
            'local_logo' => $finalOrder['local_logo'],
            'amount_product' => (float)$finalOrder['amount'],
            'earnings' => (float)$finalOrder['delivery_cost'],
            'driver_pays' => (int)$finalOrder['driver_pays_local']
        ]
    ]);
} else {
    echo json_encode(['has_orders' => false]);
}
