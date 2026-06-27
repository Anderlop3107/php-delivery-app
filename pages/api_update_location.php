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

// Actualizar latitud, longitud, timestamp y marcar como online
$updated = app_exec("
    UPDATE users 
    SET latitude = ?, longitude = ?, ubicacion_actualizada_en = NOW(), is_online = 1, updated_at = NOW() 
    WHERE id = ?
", 'ddi', [$lat, $lng, (int)$user['id']]);

echo json_encode([
    'success' => true,
    'updated' => $updated > 0
]);
