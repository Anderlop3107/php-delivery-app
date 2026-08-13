<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['count' => 0]);
    exit;
}

$row = app_one("
    SELECT COUNT(*) as count
    FROM deliveries
    WHERE repartidor_user_id = ?
      AND status NOT IN ('entregado', 'cancelado', 'rechazado')
", 'i', [(int)$user['id']]);

echo json_encode(['count' => (int)($row['count'] ?? 0)]);
