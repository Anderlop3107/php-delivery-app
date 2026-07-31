<?php
require_once __DIR__ . '/../lib/app.php';
header('Content-Type: application/json');

$user = get_logged_in_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de pedido inválido']);
    exit;
}

$row = app_row(
    "SELECT d.id, d.status, d.repartidor_user_id, r.latitude as driver_lat, r.longitude as driver_lng, r.ubicacion_actualizada_en
     FROM deliveries d
     LEFT JOIN users r ON r.id = d.repartidor_user_id
     WHERE d.id = ?",
    'i',
    [$orderId]
);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
    exit;
}

echo json_encode([
    'success' => true,
    'status' => $row['status'],
    'driver_lat' => $row['driver_lat'],
    'driver_lng' => $row['driver_lng'],
    'updated_at' => $row['ubicacion_actualizada_en']
]);
