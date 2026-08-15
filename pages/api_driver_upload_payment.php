<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Content-Type: application/json');


// --- SEGURIDAD ---
if (!rate_limit_check('api_driver_upload_payment.php', 120, 60)) {
    rate_limit_deny();
}
csrf_require();
// -----------------
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
$allowedExts  = ['jpg', 'jpeg', 'png'];

// Validar MIME type del navegador
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Formato no permitido. Solo JPG, JPEG o PNG.']);
    exit;
}

// Validar extensión real del archivo
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExts, true)) {
    echo json_encode(['success' => false, 'message' => 'Extensión de archivo no permitida.']);
    exit;
}

// Verificar que el contenido es realmente una imagen (no un PHP disfrazado)
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false || !in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
    echo json_encode(['success' => false, 'message' => 'El archivo no es una imagen válida.']);
    exit;
}

// Limitar a 8MB
if ($file['size'] > 8 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'El archivo supera el límite de 8MB.']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/payments/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Nombre seguro: solo user_id + timestamp + extensión validada (sin input del usuario)
$filename = 'proof_' . (int)$user['id'] . '_' . time() . '.' . $ext;
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
