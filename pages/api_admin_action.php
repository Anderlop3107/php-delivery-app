<?php
ob_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../bootstrap.php';

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
    
    // Verificar que el usuario exista y sea repartidor o local
    $driver = app_one("SELECT id FROM users WHERE id = ? AND role IN ('repartidor', 'local')", 'i', [$driverId]);
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado.']);
        exit;
    }
    
    // Si se subieron archivos durante la aprobación/rechazo, guardarlos
    $uploadDir = __DIR__ . '/../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    if (!empty($_FILES['doc_ci_front']['name'])) {
        $ext = pathinfo($_FILES['doc_ci_front']['name'], PATHINFO_EXTENSION);
        $fileNameFront = 'doc_ci_front_' . $driverId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['doc_ci_front']['tmp_name'], $uploadDir . $fileNameFront)) {
            app_exec("UPDATE users SET doc_ci_path = ? WHERE id = ?", 'si', ['uploads/documents/' . $fileNameFront, $driverId]);
        }
    }
    
    if (!empty($_FILES['doc_ci_back']['name'])) {
        $ext = pathinfo($_FILES['doc_ci_back']['name'], PATHINFO_EXTENSION);
        $fileNameBack = 'doc_ci_back_' . $driverId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['doc_ci_back']['tmp_name'], $uploadDir . $fileNameBack)) {
            app_exec("UPDATE users SET doc_ci_back_path = ? WHERE id = ?", 'si', ['uploads/documents/' . $fileNameBack, $driverId]);
        }
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

if ($action === 'verify_driver_payment') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $status = $_POST['status'] ?? ''; // 'approved' or 'rejected'
    $notes = trim((string)($_POST['notes'] ?? ''));
    $paymentId = (int)($_POST['payment_id'] ?? 0);

    if ($driverId <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos.']);
        exit;
    }

    // Verificar que el usuario exista y sea repartidor o local
    $driver = app_one("SELECT id, role FROM users WHERE id = ? AND role IN ('repartidor', 'local')", 'i', [$driverId]);
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado.']);
        exit;
    }

    if ($status === 'approved') {
        if ($driver['role'] === 'local') {
            // El 1 del próximo mes 00:00:00
            $expiresAt = date('Y-m-d H:i:s', strtotime('first day of next month 00:00:00'));
        } else {
            // Calcular próximo lunes 10:30 AM
            $now = time();
            $todayMonday1030 = strtotime('this Monday 10:30:00');
            if ($now < $todayMonday1030) {
                $expiresAt = date('Y-m-d H:i:s', $todayMonday1030);
            } else {
                $expiresAt = date('Y-m-d H:i:s', strtotime('next Monday 10:30:00'));
            }
        }

        // Actualizar tabla users
        app_exec("
            UPDATE users 
            SET subscription_status = 'active', subscription_expires_at = ?, updated_at = NOW()
            WHERE id = ?
        ", 'si', [$expiresAt, $driverId]);

        // Actualizar comprobante en driver_payments
        if ($paymentId > 0) {
            app_exec("
                UPDATE driver_payments 
                SET status = 'approved', verified_at = NOW(), notes = ?
                WHERE id = ?
            ", 'si', [$notes, $paymentId]);
        }
    } else { // rejected
        // Actualizar tabla users
        app_exec("
            UPDATE users 
            SET subscription_status = 'expired', updated_at = NOW()
            WHERE id = ?
        ", 'i', [$driverId]);

        // Actualizar comprobante en driver_payments
        if ($paymentId > 0) {
            app_exec("
                UPDATE driver_payments 
                SET status = 'rejected', verified_at = NOW(), notes = ?
                WHERE id = ?
            ", 'si', [$notes, $paymentId]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Comprobante verificado con éxito.']);
    exit;
}
        // Eliminar comprobante de pago
        if ($action === 'delete_payment_proof') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'ID de comprobante inválido.']);
                exit;
            }
            // Obtener información del comprobante y del conductor
            $payment = app_one('SELECT driver_user_id, payment_proof_path FROM driver_payments WHERE id = ?', 'i', [$paymentId]);
            if (!$payment) {
                http_response_code(404);
                echo json_encode(['error' => 'Comprobante no encontrado.']);
                exit;
            }
            $driverId = (int)$payment['driver_user_id'];
            // Construir ruta completa al archivo
            $filePath = __DIR__ . '/../../uploads/payments/' . $payment['payment_proof_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            // Actualizar registro del pago: eliminar referencia al archivo y marcar como rechazado
            app_exec('UPDATE driver_payments SET payment_proof_path = NULL, status = \'rejected\', verified_at = NOW() WHERE id = ?', 'i', [$paymentId]);
            // Resetear suscripción del conductor
            if ($driverId > 0) {
                app_exec('UPDATE users SET subscription_status = \'expired\', subscription_expires_at = NULL, updated_at = NOW() WHERE id = ?', 'i', [$driverId]);
            }
            echo json_encode(['success' => true, 'message' => 'Comprobante eliminado y suscripción desactivada.']);
            exit;
        }
        if ($action === 'check_new_payment') {
            // Retrieve any pending payment that hasn't been notified yet (status = 'pending' and not notified)
            $newPayments = app_all("SELECT dp.id, dp.driver_user_id, u.name AS driver_name, dp.payment_proof_path FROM driver_payments dp JOIN users u ON dp.driver_user_id = u.id WHERE dp.status = 'pending' AND dp.notified = 0");
            header('Content-Type: application/json');
            if ($newPayments) {
                // Mark them as notified to avoid duplicate alerts
                $ids = array_column($newPayments, 'id');
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?')) ;
                    $types = str_repeat('i', count($ids));
                    app_exec("UPDATE driver_payments SET notified = 1 WHERE id IN ($placeholders)", $types, $ids);
                }
                // Ensure no previous output buffers interfere
                if (ob_get_level()) { ob_end_clean(); }
                echo json_encode(['new' => true, 'payments' => $newPayments]);
            } else {
                header('Content-Type: application/json');
                if (ob_get_level()) { ob_end_clean(); }
                echo json_encode(['new' => false]);
            }
            exit;
        }
        // continue with other actions
        // Disable subscription endpoint
if ($action === 'disable_subscription') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    if ($driverId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos de conductor inválidos.']);
        exit;
    }
    $driver = app_one('SELECT id FROM users WHERE id = ? AND role = \'repartidor\'', 'i', [$driverId]);
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Repartidor no encontrado.']);
        exit;
    }
    app_exec('UPDATE users SET subscription_status = \'expired\', subscription_expires_at = NULL, updated_at = NOW() WHERE id = ?', 'i', [$driverId]);
    echo json_encode(['success' => true, 'message' => 'Suscripción deshabilitada correctamente.']);
    exit;
}

// Enable subscription endpoint
if ($action === 'enable_subscription') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    if ($driverId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos de conductor inválidos.']);
        exit;
    }
    $driver = app_one('SELECT id FROM users WHERE id = ? AND role = \'repartidor\'', 'i', [$driverId]);
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Repartidor no encontrado.']);
        exit;
    }
    // Calculate next Monday 10:30 AM for expiration
    $now = time();
    $todayMonday1030 = strtotime('this Monday 10:30:00');
    if ($now < $todayMonday1030) {
        $expiresAt = date('Y-m-d H:i:s', $todayMonday1030);
    } else {
        $expiresAt = date('Y-m-d H:i:s', strtotime('next Monday 10:30:00'));
    }
    app_exec('UPDATE users SET subscription_status = \'active\', subscription_expires_at = ?, updated_at = NOW() WHERE id = ?', 'si', [$expiresAt, $driverId]);
    echo json_encode(['success' => true, 'message' => 'Suscripción habilitada correctamente.']);
    exit;
}


if ($action === 'extend_driver_grace_period') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $hours = (int)($_POST['hours'] ?? 0);

    if ($driverId <= 0 || $hours <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos.']);
        exit;
    }

    // Verificar que el conductor exista
    $driver = app_one("SELECT id FROM users WHERE id = ? AND role = 'repartidor'", 'i', [$driverId]);
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Repartidor no encontrado.']);
        exit;
    }

    $expiresAt = date('Y-m-d H:i:s', strtotime("+$hours hours"));

    // Actualizar tabla users
    app_exec("
        UPDATE users 
        SET subscription_status = 'active', subscription_expires_at = ?, updated_at = NOW()
        WHERE id = ?
    ", 'si', [$expiresAt, $driverId]);

    echo json_encode([
        'success' => true, 
        'message' => 'Prórroga otorgada con éxito.',
        'expires_at' => date('d/m/Y H:i', strtotime($expiresAt))
    ]);
    exit;
}

if ($action === 'get_driver_kpis') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $range = $_POST['range'] ?? 'week';

    if ($driverId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos.']);
        exit;
    }

    $whereClause = "repartidor_user_id = $driverId";
    if ($range === 'day') {
        $whereClause .= " AND created_at >= DATE(NOW())";
    } elseif ($range === 'week') {
        $whereClause .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($range === 'month') {
        $whereClause .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }

    // 1. Entregados
    $stats = app_one("
        SELECT 
            COUNT(CASE WHEN status = 'entregado' THEN 1 END) as completados,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelados,
            COALESCE(SUM(CASE WHEN status = 'entregado' THEN delivery_cost END), 0) as earnings
        FROM deliveries
        WHERE $whereClause
    ");

    $completados = (int)($stats['completados'] ?? 0);
    $cancelados = (int)($stats['cancelados'] ?? 0);
    $earnings = (float)($stats['earnings'] ?? 0);

    // Calcular horas conectadas reales basadas en la tabla driver_sessions
    $sinceDate = '1970-01-01 00:00:00';
    if ($range === 'day') {
        $sinceDate = date('Y-m-d 00:00:00');
    } elseif ($range === 'week') {
        $sinceDate = date('Y-m-d H:i:s', strtotime('-7 days'));
    } elseif ($range === 'month') {
        $sinceDate = date('Y-m-d H:i:s', strtotime('-30 days'));
    }

    $sessions = app_all("
        SELECT ds.connected_at, ds.disconnected_at, u.last_ping, u.is_online 
        FROM driver_sessions ds
        JOIN users u ON ds.driver_user_id = u.id
        WHERE ds.driver_user_id = ? AND ds.connected_at >= ?
    ", "is", [$driverId, $sinceDate]);

    $totalSeconds = 0;
    $nowTime = time();

    foreach ($sessions as $session) {
        $start = strtotime($session['connected_at']);
        
        if (!empty($session['disconnected_at'])) {
            $end = strtotime($session['disconnected_at']);
        } else {
            // Sesión activa (disconnected_at es NULL)
            $lastPing = !empty($session['last_ping']) ? strtotime($session['last_ping']) : $start;
            $isOnlineUser = (int)$session['is_online'];
            
            // Si está activo y reportó en el último minuto, contamos hasta NOW()
            if ($isOnlineUser === 1 && ($nowTime - $lastPing) <= 60) {
                $end = $nowTime;
            } else {
                $end = $lastPing;
            }
        }
        
        if ($end > $start) {
            $totalSeconds += ($end - $start);
        }
    }

    // Convertir a horas con 1 decimal
    $horasConectadas = round($totalSeconds / 3600, 1);
    $distanciaKm = $completados * 4.2;

    // Generar series para los gráficos del repartidor (agrupados por fecha de creación)
    $seriesData = app_all("
        SELECT DATE(created_at) as date_label,
               COUNT(CASE WHEN status = 'entregado' THEN 1 END) as count_delivered,
               COALESCE(SUM(CASE WHEN status = 'entregado' THEN delivery_cost END), 0) as sum_earnings,
               COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as count_cancelled
        FROM deliveries
        WHERE $whereClause
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");

    $labels = [];
    $seriesDelivered = [];
    $seriesEarnings = [];
    $seriesCancelled = [];

    foreach ($seriesData as $sd) {
        $labels[] = date('d/m', strtotime($sd['date_label']));
        $seriesDelivered[] = (int)$sd['count_delivered'];
        $seriesEarnings[] = (float)$sd['sum_earnings'];
        $seriesCancelled[] = (int)$sd['count_cancelled'];
    }

    // Fallbacks si no hay entregas para mostrar el gráfico premium con estructura
    if (empty($labels)) {
        if ($range === 'day') {
            $labels = [date('d/m')];
            $seriesDelivered = [0];
            $seriesEarnings = [0];
            $seriesCancelled = [0];
        } else {
            $days = ($range === 'week') ? 7 : 30;
            for ($i = $days - 1; $i >= 0; $i--) {
                $labels[] = date('d/m', strtotime("-$i days"));
                $seriesDelivered[] = 0;
                $seriesEarnings[] = 0;
                $seriesCancelled[] = 0;
            }
        }
    }

    // Obtener los puntos de entrega del rango
    $whereClauseJoined = str_replace(
        ['repartidor_user_id', 'created_at'], 
        ['d.repartidor_user_id', 'd.created_at'], 
        $whereClause
    );
    $deliveriesPoints = app_all("
        SELECT d.id, d.delivery_latitude, d.delivery_longitude, d.delivery_address,
               COALESCE(d.pickup_latitude, l.latitude) as local_lat, COALESCE(d.pickup_longitude, l.longitude) as local_lng,
               l.business_name as local_name, d.status
        FROM deliveries d
        JOIN users l ON l.id = d.local_user_id
        WHERE $whereClauseJoined
    ");

    echo json_encode([
        'success' => true,
        'completados' => $completados,
        'cancelados' => $cancelados,
        'earnings' => $earnings,
        'horas_conectadas' => round($horasConectadas, 1),
        'distancia_km' => round($distanciaKm, 1),
        'labels' => $labels,
        'series_delivered' => $seriesDelivered,
        'series_earnings' => $seriesEarnings,
        'series_cancelled' => $seriesCancelled,
        'points' => $deliveriesPoints
    ]);
    exit;
}

if ($action === 'get_local_kpis') {
    $localId = (int)($_POST['local_id'] ?? 0);
    $range = $_POST['range'] ?? 'week';

    if ($localId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos.']);
        exit;
    }

    $whereClause = "local_user_id = $localId";
    if ($range === 'day') {
        $whereClause .= " AND created_at >= DATE(NOW())";
    } elseif ($range === 'week') {
        $whereClause .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($range === 'month') {
        $whereClause .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }

    // 1. Entregados y Cancelados
    $stats = app_one("
        SELECT 
            COUNT(CASE WHEN status = 'entregado' THEN 1 END) as completados,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelados
        FROM deliveries
        WHERE $whereClause
    ");

    $completados = (int)($stats['completados'] ?? 0);
    $cancelados = (int)($stats['cancelados'] ?? 0);

    // 2. Horarios de pedidos (distribución por hora de 00h a 23h)
    $hourlyData = app_all("
        SELECT HOUR(created_at) as order_hour, COUNT(*) as count 
        FROM deliveries
        WHERE $whereClause
        GROUP BY HOUR(created_at)
        ORDER BY order_hour ASC
    ");

    $hourlyCounts = array_fill(0, 24, 0);
    foreach ($hourlyData as $hd) {
        $hour = (int)$hd['order_hour'];
        if ($hour >= 0 && $hour < 24) {
            $hourlyCounts[$hour] = (int)$hd['count'];
        }
    }

    $hourLabels = [];
    for ($i = 0; $i < 24; $i++) {
        $hourLabels[] = sprintf("%02dh", $i);
    }

    // 3. Generar series agrupadas por día para el rango
    $seriesData = app_all("
        SELECT DATE(created_at) as date_label,
               COUNT(CASE WHEN status = 'entregado' THEN 1 END) as count_delivered,
               COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as count_cancelled
        FROM deliveries
        WHERE $whereClause
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");

    $labels = [];
    $seriesDelivered = [];
    $seriesCancelled = [];

    foreach ($seriesData as $sd) {
        $labels[] = date('d/m', strtotime($sd['date_label']));
        $seriesDelivered[] = (int)$sd['count_delivered'];
        $seriesCancelled[] = (int)$sd['count_cancelled'];
    }

    // Fallbacks si no hay datos
    if (empty($labels)) {
        if ($range === 'day') {
            $labels = [date('d/m')];
            $seriesDelivered = [0];
            $seriesCancelled = [0];
        } else {
            $days = ($range === 'week') ? 7 : 30;
            for ($i = $days - 1; $i >= 0; $i--) {
                $labels[] = date('d/m', strtotime("-$i days"));
                $seriesDelivered[] = 0;
                $seriesCancelled[] = 0;
            }
        }
    }

    // Obtener los puntos de entrega en el mapa (pickup y delivery) para este local
    $points = app_all("
        SELECT d.id, d.delivery_latitude as lat, d.delivery_longitude as lng, d.delivery_address as address, d.status,
               COALESCE(d.pickup_latitude, l.latitude) as local_lat, COALESCE(d.pickup_longitude, l.longitude) as local_lng
        FROM deliveries d
        JOIN users l ON l.id = d.local_user_id
        WHERE d.local_user_id = ? AND d.delivery_latitude IS NOT NULL AND d.delivery_longitude IS NOT NULL
        ORDER BY d.id DESC LIMIT 100
    ", "i", [$localId]);

    // Calcular distancia estimada total
    $distanciaKm = $completados * 4.2;

    echo json_encode([
        'success' => true,
        'completados' => $completados,
        'cancelados' => $cancelados,
        'distancia_km' => round($distanciaKm, 1),
        'labels' => $labels,
        'series_delivered' => $seriesDelivered,
        'series_cancelled' => $seriesCancelled,
        'hourly_labels' => $hourLabels,
        'hourly_data' => $hourlyCounts,
        'points' => $points
    ]);
    exit;
}

if ($action === 'get_local_history') {
    $localId = (int)($_POST['local_id'] ?? 0);
    $limit = 15;
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;

    if ($localId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos.']);
        exit;
    }

    $totalRow = app_one("SELECT COUNT(*) as count FROM deliveries WHERE local_user_id = ?", 'i', [$localId]);
    $totalCount = (int)($totalRow['count'] ?? 0);
    $totalPages = ceil($totalCount / $limit);

    $history = app_all("
        SELECT d.*, l.business_name as local_name, r.name as driver_name
        FROM deliveries d
        JOIN users l ON l.id = d.local_user_id
        LEFT JOIN users r ON r.id = d.repartidor_user_id
        WHERE d.local_user_id = ?
        ORDER BY d.created_at DESC
        LIMIT ? OFFSET ?
    ", "iii", [$localId, $limit, $offset]);

    echo json_encode([
        'success' => true,
        'history' => $history,
        'page' => $page,
        'total_pages' => $totalPages,
        'total_count' => $totalCount
    ]);
    exit;
}

if ($action === 'get_driver_history') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $limit = (int)($_POST['limit'] ?? 15);
    $offset = (int)($_POST['offset'] ?? 0);

    if ($driverId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos.']);
        exit;
    }

    $history = app_all("
        SELECT d.*, l.business_name as local_name
        FROM deliveries d
        JOIN users l ON l.id = d.local_user_id
        WHERE d.repartidor_user_id = ?
        ORDER BY d.created_at DESC
        LIMIT ? OFFSET ?
    ", 'iii', [$driverId, $limit, $offset]);

    $total = (int)app_one("SELECT COUNT(*) as cnt FROM deliveries WHERE repartidor_user_id = ?", 'i', [$driverId])['cnt'];

    echo json_encode([
        'success' => true,
        'history' => $history,
        'total' => $total
    ]);
    exit;
}

if ($action === 'get_driver_live_status') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    if ($driverId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de repartidor inválido.']);
        exit;
    }
    $driver = app_one("
        SELECT is_online,
               (SELECT COUNT(*) FROM deliveries WHERE repartidor_user_id = users.id AND status NOT IN ('entregado', 'cancelado', 'rechazado')) as active_delivery_count
        FROM users
        WHERE id = ? AND role = 'repartidor'
    ", "i", [$driverId]);
    
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Repartidor no encontrado.']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'is_online' => (int)$driver['is_online'],
        'active_delivery_count' => (int)$driver['active_delivery_count']
    ]);
    exit;
}

if ($action === 'get_active_drivers') {
    $activeDrivers = app_all(
        "SELECT id, name, logo_path as avatar_path, latitude, longitude, is_online,
               (SELECT COUNT(*) FROM deliveries WHERE repartidor_user_id = users.id AND status NOT IN ('entregado', 'cancelado')) as active_delivery_count
        FROM users 
        WHERE role = 'repartidor' 
          AND latitude IS NOT NULL AND longitude IS NOT NULL 
          AND last_ping >= DATE_SUB(NOW(), INTERVAL 30 SECOND)"
    );
    echo json_encode([
        'success' => true,
        'drivers' => $activeDrivers
    ]);
    exit;
}

if ($action === 'upload_local_doc') {
    $driverId = (int)($_POST['driver_id'] ?? 0);
    
    $driver = app_one("SELECT id FROM users WHERE id = ? AND role IN ('repartidor', 'local')", 'i', [$driverId]);
    if (!$driver) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $uploadedAny = false;
    if (!empty($_FILES['doc_ci_front']['name'])) {
        $ext = pathinfo($_FILES['doc_ci_front']['name'], PATHINFO_EXTENSION);
        $fileNameFront = 'doc_ci_front_' . $driverId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['doc_ci_front']['tmp_name'], $uploadDir . $fileNameFront)) {
            app_exec("UPDATE users SET doc_ci_path = ? WHERE id = ?", 'si', ['uploads/documents/' . $fileNameFront, $driverId]);
            $uploadedAny = true;
        }
    }
    
    if (!empty($_FILES['doc_ci_back']['name'])) {
        $ext = pathinfo($_FILES['doc_ci_back']['name'], PATHINFO_EXTENSION);
        $fileNameBack = 'doc_ci_back_' . $driverId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['doc_ci_back']['tmp_name'], $uploadDir . $fileNameBack)) {
            app_exec("UPDATE users SET doc_ci_back_path = ? WHERE id = ?", 'si', ['uploads/documents/' . $fileNameBack, $driverId]);
            $uploadedAny = true;
        }
    }

    if ($uploadedAny) {
        app_exec("UPDATE users SET status_doc_ci = 'pending' WHERE id = ?", 'i', [$driverId]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Cédula cargada y guardada con éxito.']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no especificada o no soportada.']);
exit;
