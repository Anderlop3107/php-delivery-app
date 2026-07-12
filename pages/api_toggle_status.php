<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$isOnline = isset($_POST['is_online']) ? (int)$_POST['is_online'] : 0;
$driverId = (int)$user['id'];

// Log session state in driver_sessions
if ($isOnline === 1) {
    // Close any orphaned open sessions first
    app_exec("
        UPDATE driver_sessions 
        SET disconnected_at = NOW() 
        WHERE driver_user_id = ? AND disconnected_at IS NULL
    ", 'i', [$driverId]);
    
    // Create new session
    app_exec("
        INSERT INTO driver_sessions (driver_user_id, connected_at) 
        VALUES (?, NOW())
    ", 'i', [$driverId]);
} else {
    // Close current open session
    app_exec("
        UPDATE driver_sessions 
        SET disconnected_at = NOW() 
        WHERE driver_user_id = ? AND disconnected_at IS NULL
    ", 'i', [$driverId]);
}

$updated = app_exec("
    UPDATE users 
    SET is_online = ?, last_ping = NOW(), updated_at = NOW() 
    WHERE id = ?
", 'ii', [$isOnline, $driverId]);

echo json_encode([
    'success' => true,
    'is_online' => $isOnline,
    'updated' => $updated > 0
]);
