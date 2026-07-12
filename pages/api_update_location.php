<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : (isset($_GET['latitude']) ? (float)$_GET['latitude'] : null);
$lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : (isset($_GET['longitude']) ? (float)$_GET['longitude'] : null);

if ($lat === null || $lng === null || $lat === 0.0 || $lng === 0.0) {
    echo json_encode(['success' => false, 'message' => 'Coordenadas inválidas']);
    exit;
}

// Verificar si la suscripción está vencida
$userData = app_one("SELECT subscription_status, subscription_expires_at FROM users WHERE id = ?", "i", [(int)$user['id']]);
$subscriptionExpired = true;
if ($userData['subscription_status'] === 'active' && !empty($userData['subscription_expires_at'])) {
    if (strtotime($userData['subscription_expires_at']) >= time()) {
        $subscriptionExpired = false;
    }
}

$isOnlineVal = $subscriptionExpired ? 0 : 1;
$driverId = (int)$user['id'];

// Log session state in driver_sessions based on ping status
if ($isOnlineVal === 0) {
    app_exec("
        UPDATE driver_sessions 
        SET disconnected_at = NOW() 
        WHERE driver_user_id = ? AND disconnected_at IS NULL
    ", 'i', [$driverId]);
} else {
    // Ensure there is an active session
    $hasActiveSession = app_one("
        SELECT id FROM driver_sessions 
        WHERE driver_user_id = ? AND disconnected_at IS NULL 
        LIMIT 1
    ", 'i', [$driverId]);
    if (!$hasActiveSession) {
        app_exec("
            INSERT INTO driver_sessions (driver_user_id, connected_at) 
            VALUES (?, NOW())
        ", 'i', [$driverId]);
    }
}

// Actualizar latitud, longitud, timestamp, last_ping y marcar como online/offline según suscripción
$updated = app_exec("
    UPDATE users 
    SET latitude = ?, longitude = ?, ubicacion_actualizada_en = NOW(), is_online = ?, last_ping = NOW(), updated_at = NOW() 
    WHERE id = ?
", 'ddii', [$lat, $lng, $isOnlineVal, $driverId]);

echo json_encode([
    'success' => true,
    'updated' => $updated > 0
]);
