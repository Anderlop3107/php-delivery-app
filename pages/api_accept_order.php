<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';

ob_clean();
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

// Aceptación atómica: solo el PRIMER conductor que llegue puede tomarlo.
// La condición status='pendiente' AND repartidor_user_id IS NULL garantiza
// que si dos conductores llaman al mismo tiempo, solo uno actualiza 1 fila.
$updated = app_exec("
    UPDATE deliveries 
    SET repartidor_user_id = ?, 
        status = 'aceptado', 
        updated_at = NOW()
    WHERE id = ? 
      AND status = 'pendiente' 
      AND repartidor_user_id IS NULL
", 'ii', [(int)$user['id'], $orderId]);

if ($updated > 0) {
    // Verificar si el repartidor alcanzó el límite de 2 pedidos activos
    $row = app_one("
        SELECT COUNT(*) as count
        FROM deliveries
        WHERE repartidor_user_id = ?
          AND status NOT IN ('entregado', 'cancelado', 'rechazado')
    ", 'i', [(int)$user['id']]);
    $activeCount = (int)($row['count'] ?? 0);
    
    if ($activeCount >= 2) {
        app_exec("UPDATE users SET is_online = 0 WHERE id = ?", 'i', [(int)$user['id']]);
    }
    
    echo json_encode(['success' => true]);
} else {
    // El pedido ya fue tomado por otro conductor
    echo json_encode(['success' => false, 'message' => 'Este pedido ya fue tomado por otro repartidor.']);
}
