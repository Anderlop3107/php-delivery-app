<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] !== 'repartidor') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$userData = app_one("SELECT * FROM users WHERE id = ?", "i", [(int)$user['id']]);

$docsApproved = (
    ($userData['status_doc_ci'] ?? 'none') === 'approved' &&
    ($userData['status_doc_licencia'] ?? 'none') === 'approved' &&
    ($userData['status_doc_habilitacion'] ?? 'none') === 'approved' &&
    ($userData['status_doc_cedula_verde'] ?? 'none') === 'approved'
);

$subscriptionExpired = true;
if (($userData['subscription_status'] ?? '') === 'active' && !empty($userData['subscription_expires_at'])) {
    if (strtotime($userData['subscription_expires_at']) >= time()) {
        $subscriptionExpired = false;
    }
}

$approved = ($docsApproved && !$subscriptionExpired);

echo json_encode([
    'success' => true,
    'approved' => $approved,
    'docs_approved' => $docsApproved,
    'subscription_expired' => $subscriptionExpired
]);
exit;
