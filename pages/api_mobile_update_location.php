<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';

function mobile_token_value(): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return null;
    }

    $encoded = strtr($matches[1], '-_', '+/');
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    $decoded = base64_decode($encoded, true);
    $parts = $decoded === false ? [] : explode('.', $decoded);
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
        return null;
    }

    $payload = $parts[0] . '.' . $parts[1];
    $secret = defined('MOBILE_LOCATION_SECRET') ? MOBILE_LOCATION_SECRET : DB_PASS;
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $parts[2]) || (int)$parts[1] < time()) {
        return null;
    }

    return ['user_id' => (int)$parts[0]];
}

if (!rate_limit_check('api_mobile_update_location.php', 180, 60)) {
    rate_limit_deny();
}

$token = mobile_token_value();
$lat = isset($_GET['latitude']) ? (float)$_GET['latitude'] : (float)($_POST['latitude'] ?? 0);
$lng = isset($_GET['longitude']) ? (float)$_GET['longitude'] : (float)($_POST['longitude'] ?? 0);
if (!$token || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
    http_response_code(401);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ubicación no autorizada']);
    exit;
}

$driver = app_one("SELECT id, role, is_online FROM users WHERE id = ?", 'i', [$token['user_id']]);
if (!$driver || $driver['role'] !== 'repartidor' || (int)$driver['is_online'] !== 1) {
    http_response_code(403);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'El seguimiento está desactivado']);
    exit;
}

app_exec(
    "UPDATE users SET latitude = ?, longitude = ?, ubicacion_actualizada_en = NOW(), last_ping = NOW(), updated_at = NOW() WHERE id = ?",
    'ddi',
    [$lat, $lng, $token['user_id']]
);

ob_clean();
header('Cache-Control: no-store');
header('Content-Type: application/json');
