<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Content-Type: application/json');

$user = current_user();
if (!$user || ($user['role'] !== 'repartidor' && $user['role'] !== 'local')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Archivo no recibido o con error']);
    exit;
}

$file = $_FILES['payment_proof'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Formato no permitido. Solo JPG, JPEG o PNG.']);
    exit;
}

// Limitar a 8MB
if ($file['size'] > 8 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'El archivo supera el límite de 8MB.']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/payments/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'proof_' . $user['id'] . '_' . time() . '.' . $ext;
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $dbPath = 'uploads/payments/' . $filename;
    
    // Insertar en driver_payments
    app_exec("
        INSERT INTO driver_payments (driver_user_id, payment_proof_path, status, uploaded_at)
        VALUES (?, ?, 'pending', NOW())
    ", 'is', [(int)$user['id'], $dbPath]);
    
    // Actualizar estado del usuario a 'pending'
    app_exec("
        UPDATE users
        SET subscription_status = 'pending', updated_at = NOW()
        WHERE id = ?
    ", 'i', [(int)$user['id']]);
    
    echo json_encode(['success' => true, 'message' => 'Comprobante subido con éxito']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error al guardar el archivo en el servidor.']);
}
