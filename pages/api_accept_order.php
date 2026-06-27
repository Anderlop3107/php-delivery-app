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

// Intentamos asignar el pedido al repartidor actual (respetando reservas)
$updated = app_exec("
    UPDATE deliveries 
    SET repartidor_user_id = ?, 
        status = 'aceptado', 
        reservado_para_repartidor_id = NULL, 
        reserva_expira_en = NULL, 
        updated_at = NOW()
    WHERE id = ? 
      AND status = 'pendiente' 
      AND repartidor_user_id IS NULL
      AND (reservado_para_repartidor_id IS NULL OR reservado_para_repartidor_id = ? OR reserva_expira_en < NOW())
", 'iiii', [(int)$user['id'], $orderId, (int)$user['id']]);

if ($updated > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'El pedido ya no está disponible o la reserva expiró.']);
}
