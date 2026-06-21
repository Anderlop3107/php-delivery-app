<?php
require_once __DIR__ . '/../bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['has_orders' => false]);
    exit;
}

// Consultar el último pedido pendiente con todos sus detalles para el broadcast
$order = app_one("
    SELECT d.*, u.business_name as local_name, u.address as local_address, u.logo_path as local_logo, u.latitude as local_lat, u.longitude as local_lng
    FROM deliveries d
    JOIN users u ON d.local_user_id = u.id
    WHERE d.status = 'pendiente' AND d.repartidor_user_id IS NULL
    ORDER BY d.created_at DESC
    LIMIT 1
");

if ($order) {
    echo json_encode([
        'has_orders' => true,
        'order' => [
            'id' => (int)$order['id'],
            'local_name' => $order['local_name'],
            'local_lat' => (float)$order['local_lat'],
            'local_lng' => (float)$order['local_lng'],
            'dest_lat' => (float)$order['delivery_latitude'],
            'dest_lng' => (float)$order['delivery_longitude'],
            'dest_address' => $order['delivery_address'],
            'local_logo' => $order['local_logo'],
            'amount_product' => (float)$order['amount'],
            'earnings' => (float)$order['delivery_cost'],
            'driver_pays' => (int)$order['driver_pays_local']
        ]
    ]);
} else {
    echo json_encode(['has_orders' => false]);
}
