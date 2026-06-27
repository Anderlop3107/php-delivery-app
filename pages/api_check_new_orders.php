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

// Obtener pedidos compatibles geográficamente y según el estado
$matchedOrders = obtener_pedidos_disponibles_para_repartidor((int)$user['id']);

$finalOrder = null;
foreach ($matchedOrders as $candidate) {
    // Intentar bloquear el pedido temporalmente para este repartidor por 15 segundos
    $reserved = app_exec("
        UPDATE deliveries 
        SET reservado_para_repartidor_id = ?, 
            reserva_expira_en = DATE_ADD(NOW(), INTERVAL 15 SECOND)
        WHERE id = ? 
          AND status = 'pendiente'
          AND (reservado_para_repartidor_id IS NULL OR reservado_para_repartidor_id = ? OR reserva_expira_en < NOW())
    ", 'iii', [(int)$user['id'], (int)$candidate['id'], (int)$user['id']]);

    if ($reserved > 0) {
        $finalOrder = $candidate;
        break; // Hemos reservado exitosamente el pedido
    }
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
