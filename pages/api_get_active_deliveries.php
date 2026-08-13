<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['local']);

$user = current_user();

// Obtener entregas activas más las completadas recientemente (último minuto)
$rows = app_all(
    "SELECT d.*, r.name AS repartidor_name, r.phone AS repartidor_phone, r.logo_path AS repartidor_avatar, u_local.business_name AS local_name, u_local.logo_path AS local_logo, u_local.phone AS local_phone, u_local.latitude as local_lat, u_local.longitude as local_lng
     FROM deliveries d
     LEFT JOIN users r ON r.id = d.repartidor_user_id
     JOIN users u_local ON d.local_user_id = u_local.id
     WHERE d.local_user_id = ? AND (d.status NOT IN ('entregado', 'cancelado', 'rechazado') OR d.updated_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE))
     ORDER BY d.created_at DESC",
    'i',
    [(int) $user['id']]
);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: application/json');
echo json_encode($rows);
exit;
