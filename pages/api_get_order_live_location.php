<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: application/json');

$user = current_user();
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

// Verificar que el usuario es dueño o repartidor asignado del pedido
$isOwner = (int)$row['repartidor_user_id'] === (int)$user['id'];
$isLocal = false;
if (!$isOwner) {
    $delivery = app_one("SELECT local_user_id FROM deliveries WHERE id = ?", 'i', [$orderId]);
    $isLocal = $delivery && (int)$delivery['local_user_id'] === (int)$user['id'];
}
if (!$isOwner && !$isLocal && ($user['role'] !== 'admin')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado para este pedido']);
    exit;
}


echo json_encode([
    'success' => true,
    'status' => $row['status'],
    'driver_lat' => $row['driver_lat'],
    'driver_lng' => $row['driver_lng'],
    'updated_at' => $row['ubicacion_actualizada_en']
]);
