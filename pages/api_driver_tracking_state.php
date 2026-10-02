<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    http_response_code(403);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'orders' => []]);
    exit;
}

if (!rate_limit_check('api_driver_tracking_state.php', 120, 60)) {
    rate_limit_deny();
}

$orders = app_all(
    "SELECT id, status FROM deliveries
     WHERE repartidor_user_id = ?
       AND status NOT IN ('entregado', 'cancelado', 'rechazado')
     ORDER BY id ASC",
    'i',
    [(int)$user['id']]
);
$driverState = app_one("SELECT is_online FROM users WHERE id = ?", 'i', [(int)$user['id']]);

ob_clean();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'is_online' => (int)($driverState['is_online'] ?? 0),
    'orders' => $orders
]);
