<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Content-Type: application/json');


// --- SEGURIDAD ---
if (!rate_limit_check('api_toggle_status.php', 120, 60)) {
    rate_limit_deny();
}
csrf_require();
// -----------------
$user = current_user();
if (!$user || $user['role'] !== 'repartidor') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$isOnline = isset($_POST['is_online']) ? (int)$_POST['is_online'] : 0;
$driverId = (int)$user['id'];

// Verificar validez de suscripción semanal y documentos antes de conectar
if ($isOnline === 1) {
    $driverCheck = app_one("
        SELECT subscription_status, subscription_expires_at,
               status_doc_ci, status_doc_licencia, status_doc_habilitacion, status_doc_cedula_verde
        FROM users WHERE id = ?
    ", 'i', [$driverId]);
    
    $docsOk = ($driverCheck['status_doc_ci'] === 'approved' &&
               $driverCheck['status_doc_licencia'] === 'approved' &&
               $driverCheck['status_doc_habilitacion'] === 'approved' &&
               $driverCheck['status_doc_cedula_verde'] === 'approved');
               
    $isExpired = ($driverCheck['subscription_status'] === 'expired' || 
                  empty($driverCheck['subscription_expires_at']) || 
                  strtotime($driverCheck['subscription_expires_at']) <= time());

    if (!$docsOk) {
        app_exec("UPDATE users SET is_online = 0 WHERE id = ?", 'i', [$driverId]);
        echo json_encode([
            'success' => false,
            'is_online' => 0,
            'reason' => 'docs_pending',
            'message' => 'Debes tener todos tus documentos aprobados para poder conectarte.'
        ]);
        exit;
    }

    if ($isExpired) {
        app_exec("UPDATE users SET is_online = 0, subscription_status = 'expired' WHERE id = ?", 'i', [$driverId]);
        echo json_encode([
            'success' => false,
            'is_online' => 0,
            'reason' => 'subscription_expired',
            'message' => 'Tu suscripción semanal ha expirado (Lunes 12:00 hs). Sube tu comprobante de pago en tu perfil para reactivar tu cuenta.'
        ]);
        exit;
    }
}

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
    SET is_online = ?, last_ping = NOW(), ubicacion_actualizada_en = NOW(), updated_at = NOW() 
    WHERE id = ?
", 'ii', [$isOnline, $driverId]);

echo json_encode([
    'success' => true,
    'is_online' => $isOnline,
    'updated' => $updated > 0
]);
