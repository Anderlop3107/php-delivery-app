<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/deliveries.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$newStatus = $_POST['status'] ?? '';

if ($orderId <= 0 || $newStatus === '') {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

// Validar transición permitida y pertenencia
$order = app_one("SELECT * FROM deliveries WHERE id = ?", 'i', [$orderId]);

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
    exit;
}

// Lógica de seguridad por roles
if ($user['role'] === 'repartidor') {
    // El repartidor solo puede actualizar pedidos que ya tomó o tomar uno pendiente
    if ($newStatus === 'aceptado') {
        if ($order['status'] !== 'pendiente' || !empty($order['repartidor_user_id'])) {
            echo json_encode(['success' => false, 'message' => 'El pedido ya no está disponible']);
            exit;
        }
        // Asignar repartidor
        app_exec("UPDATE deliveries SET repartidor_user_id = ? WHERE id = ?", 'ii', [$user['id'], $orderId]);
    } else {
        if ($order['repartidor_user_id'] != $user['id']) {
            echo json_encode(['success' => false, 'message' => 'Este pedido no te pertenece']);
            exit;
        }
    }
} elseif ($user['role'] === 'local') {
    // El local solo puede actualizar sus propios pedidos (ej. cancelar)
    if ($order['local_user_id'] != $user['id']) {
        echo json_encode(['success' => false, 'message' => 'No tienes permiso sobre este pedido']);
        exit;
    }
}

// Ejecutar actualización
$success = update_delivery_status($orderId, $newStatus, (int)$user['id']);

if ($success) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error al actualizar el estado']);
}
