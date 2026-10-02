<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    http_response_code(403);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

csrf_require();
$expires = time() + 86400;
$payload = (int)$user['id'] . '.' . $expires;
$secret = defined('MOBILE_LOCATION_SECRET') ? MOBILE_LOCATION_SECRET : DB_PASS;
$signature = hash_hmac('sha256', $payload, $secret);
$token = rtrim(strtr(base64_encode($payload . '.' . $signature), '+/', '-_'), '=');

ob_clean();
header('Cache-Control: no-store');
header('Content-Type: application/json');
