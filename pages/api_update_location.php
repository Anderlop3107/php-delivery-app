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

// Actualizar latitud, longitud, timestamp y marcar como online/offline según suscripción
$updated = app_exec("
    UPDATE users 
    SET latitude = ?, longitude = ?, ubicacion_actualizada_en = NOW(), is_online = ?, updated_at = NOW() 
    WHERE id = ?
", 'ddii', [$lat, $lng, $isOnlineVal, (int)$user['id']]);

echo json_encode([
    'success' => true,
    'updated' => $updated > 0
]);
