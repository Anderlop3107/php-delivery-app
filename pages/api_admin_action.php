<?php
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

// Validar que el usuario esté logueado y sea admin
require_login();
$user = current_user();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado. Se requieren permisos de administrador.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Utilizar POST.']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'approve_document' || $action === 'reject_document') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $docType = $_POST['doc_type'] ?? '';
    
    $validDocs = ['ci', 'licencia', 'habilitacion', 'cedula_verde'];
    if (!in_array($docType, $validDocs, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de documento no válido.']);
        exit;
    }
    
    $column = 'status_doc_' . $docType;
    $status = ($action === 'approve_document') ? 'approved' : 'rejected';
    
    // Verificar que el conductor exista
    $driver = app_one("SELECT id FROM users WHERE id = ? AND role = 'repartidor'", 'i', [$driverId]);
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Conductor no encontrado.']);
        exit;
    }
    
    // Actualizar el estado del documento
    app_exec("UPDATE users SET $column = ? WHERE id = ?", 'si', [$status, $driverId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'El documento fue ' . ($status === 'approved' ? 'aprobado' : 'rechazado') . ' con éxito.',
        'new_status' => $status
    ]);
    exit;
}

if ($action === 'update_subscription') {
    $localId = (int)($_POST['local_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $days = (int)($_POST['days'] ?? 0); // Días de extensión (opcional)

    $validStatuses = ['active', 'expired', 'pending'];
    if (!in_array($status, $validStatuses, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Estado de suscripción no válido.']);
        exit;
    }
    
    // Verificar que el comercio exista
    $local = app_one("SELECT id, subscription_expires_at FROM users WHERE id = ? AND role = 'local'", 'i', [$localId]);
    if (!$local) {
        http_response_code(404);
        echo json_encode(['error' => 'Comercio no encontrado.']);
        exit;
    }
    
    $expiresAt = null;
    if ($status === 'active') {
        if ($days > 0) {
            // Extender desde la fecha actual
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$days days"));
        } else {
            // Por defecto, 30 días de suscripción
            $expiresAt = date('Y-m-d H:i:s', strtotime("+30 days"));
        }
    }
    
    app_exec("
        UPDATE users 
        SET subscription_status = ?, subscription_expires_at = ? 
        WHERE id = ?
    ", 'ssi', [$status, $expiresAt, $localId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Suscripción del comercio actualizada con éxito.',
        'new_status' => $status,
        'expires_at' => $expiresAt ? date('d/m/Y H:i', strtotime($expiresAt)) : 'N/A'
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no especificada o no soportada.']);
exit;
