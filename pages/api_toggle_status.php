<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$isOnline = isset($_POST['is_online']) ? (int)$_POST['is_online'] : 0;

$updated = app_exec("
    UPDATE users 
    SET is_online = ?, updated_at = NOW() 
    WHERE id = ?
", 'ii', [$isOnline, (int)$user['id']]);

echo json_encode([
    'success' => true,
    'is_online' => $isOnline,
    'updated' => $updated > 0
]);
