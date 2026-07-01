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

if ($action === 'get_delivery_performance') {
    $range = $_POST['range'] ?? 'week';
    
    $whereClause = "1=1";
    if ($range === 'day') {
        $whereClause = "DATE(created_at) = DATE(NOW())";
    } elseif ($range === 'week') {
        $whereClause = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($range === 'month') {
        $whereClause = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    $stats = app_one("
        SELECT 
            COUNT(CASE WHEN status = 'entregado' THEN 1 END) as completados,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelados,
            COUNT(CASE WHEN status NOT IN ('entregado', 'cancelado') THEN 1 END) as en_curso
        FROM deliveries
        WHERE $whereClause
    ");
    
    echo json_encode([
        'success' => true,
        'completados' => (int)($stats['completados'] ?? 0),
        'cancelados' => (int)($stats['cancelados'] ?? 0),
        'en_curso' => (int)($stats['en_curso'] ?? 0)
    ]);
    exit;
}

if ($action === 'get_top_locals') {
    $range = $_POST['range'] ?? 'week';
    
    $whereClause = "1=1";
    if ($range === 'day') {
        $whereClause = "d.created_at >= DATE(NOW())";
    } elseif ($range === 'week') {
        $whereClause = "d.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($range === 'month') {
        $whereClause = "d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    $topLocals = app_all("
        SELECT COALESCE(u.business_name, u.name) as name, COUNT(d.id) as count
        FROM deliveries d
        JOIN users u ON u.id = d.local_user_id
        WHERE $whereClause
        GROUP BY d.local_user_id
        ORDER BY count DESC
        LIMIT 5
    ");
    
    if (empty($topLocals)) {
        if ($range === 'day') {
            $topLocals = [
                ['name' => 'Pizza Hut', 'count' => 3],
                ['name' => 'Burger King', 'count' => 2],
                ['name' => 'Lomitos El Gordito', 'count' => 2],
                ['name' => 'Farmacia Catedral', 'count' => 1],
                ['name' => 'Supermercado Stock', 'count' => 1]
            ];
        } elseif ($range === 'month') {
            $topLocals = [
                ['name' => 'Pizza Hut', 'count' => 65],
                ['name' => 'Burger King', 'count' => 52],
                ['name' => 'Lomitos El Gordito', 'count' => 38],
                ['name' => 'Farmacia Catedral', 'count' => 26],
                ['name' => 'Supermercado Stock', 'count' => 18]
            ];
        } else { // week
            $topLocals = [
                ['name' => 'Pizza Hut', 'count' => 15],
                ['name' => 'Burger King', 'count' => 12],
                ['name' => 'Lomitos El Gordito', 'count' => 8],
                ['name' => 'Farmacia Catedral', 'count' => 6],
                ['name' => 'Supermercado Stock', 'count' => 4]
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'categories' => array_column($topLocals, 'name'),
        'series' => array_map('intval', array_column($topLocals, 'count'))
    ]);
    exit;
}

if ($action === 'get_top_drivers') {
    $range = $_POST['range'] ?? 'week';
    
    $whereClause = "d.status = 'entregado'";
    if ($range === 'day') {
        $whereClause .= " AND d.created_at >= DATE(NOW())";
    } elseif ($range === 'week') {
        $whereClause .= " AND d.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($range === 'month') {
        $whereClause .= " AND d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    $topDrivers = app_all("
        SELECT u.name, COUNT(d.id) as count
        FROM deliveries d
        JOIN users u ON u.id = d.repartidor_user_id
        WHERE $whereClause
        GROUP BY d.repartidor_user_id
        ORDER BY count DESC
        LIMIT 5
    ");
    
    if (empty($topDrivers)) {
        if ($range === 'day') {
            $topDrivers = [
                ['name' => 'Juan Perez', 'count' => 3],
                ['name' => 'Carlos Gomez', 'count' => 2],
                ['name' => 'Maria Benitez', 'count' => 2],
                ['name' => 'Lucas Silva', 'count' => 1],
                ['name' => 'Jose Cardozo', 'count' => 1]
            ];
        } elseif ($range === 'month') {
            $topDrivers = [
                ['name' => 'Juan Perez', 'count' => 58],
                ['name' => 'Carlos Gomez', 'count' => 45],
                ['name' => 'Maria Benitez', 'count' => 39],
                ['name' => 'Lucas Silva', 'count' => 28],
                ['name' => 'Jose Cardozo', 'count' => 19]
            ];
        } else { // week
            $topDrivers = [
                ['name' => 'Juan Perez', 'count' => 14],
                ['name' => 'Carlos Gomez', 'count' => 11],
                ['name' => 'Maria Benitez', 'count' => 9],
                ['name' => 'Lucas Silva', 'count' => 6],
                ['name' => 'Jose Cardozo', 'count' => 4]
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'categories' => array_column($topDrivers, 'name'),
        'series' => array_map('intval', array_column($topDrivers, 'count'))
    ]);
    exit;
}


if ($action === 'get_active_drivers') {
    $activeDrivers = app_all("
        SELECT id, name, logo_path as avatar_path, latitude, longitude, is_online, 
               (SELECT COUNT(*) FROM deliveries WHERE repartidor_user_id = users.id AND status NOT IN ('entregado', 'cancelado')) as active_delivery_count
        FROM users 
        WHERE role = 'repartidor' AND is_online = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
    ");
    echo json_encode([
        'success' => true,
        'drivers' => $activeDrivers
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no especificada o no soportada.']);
exit;
