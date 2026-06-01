<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de pedido inválido']);
    exit;
}

// Intentamos asignar el pedido al repartidor actual (solo si sigue pendiente y sin dueño)
$updated = app_exec("
    UPDATE deliveries 
    SET repartidor_user_id = ?, status = 'aceptado', updated_at = NOW()
    WHERE id = ? AND status = 'pendiente' AND repartidor_user_id IS NULL
", 'ii', [(int)$user['id'], $orderId]);

if ($updated > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'El pedido ya fue tomado por otro repartidor.']);
}
