<?php
ob_start();
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] !== 'repartidor' && $user['role'] !== 'local') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$userData = app_one("SELECT * FROM users WHERE id = ?", "i", [(int)$user['id']]);

$subscriptionExpired = true;
if (($userData['subscription_status'] ?? '') === 'active' && !empty($userData['subscription_expires_at'])) {
    if (strtotime($userData['subscription_expires_at']) >= time()) {
        $subscriptionExpired = false;
    }
}

$notifications = app_all("
    SELECT id, type, title, message FROM app_notifications 
    WHERE user_id = ? AND is_read = 0 
    ORDER BY id ASC
", 'i', [(int)$user['id']]);

if (!empty($notifications)) {
    app_exec("UPDATE app_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", 'i', [(int)$user['id']]);
}

$docsApproved = true;
if ($user['role'] === 'repartidor') {
    $docsApproved = (
        ($userData['status_doc_ci'] ?? 'none') === 'approved' &&
        ($userData['status_doc_licencia'] ?? 'none') === 'approved' &&
        ($userData['status_doc_habilitacion'] ?? 'none') === 'approved' &&
        ($userData['status_doc_cedula_verde'] ?? 'none') === 'approved'
    );
}

$approved = ($docsApproved && !$subscriptionExpired);

ob_clean();
header('Content-Type: application/json');

// --- SEGURIDAD ---
if (!rate_limit_check('api_check_approval.php', 120, 60)) {
    rate_limit_deny();
}
// -----------------
echo json_encode([
    'success' => true,
    'approved' => $approved,
    'docs_approved' => $docsApproved,
    'subscription_expired' => $subscriptionExpired,
    'notifications' => $notifications
]);
exit;
