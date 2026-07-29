<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] !== 'admin') {
    header('Location: ' . delivery_app_url('dashboard.php'));
    exit;
}

// 1. Estadísticas Bento
$todayOrders = app_one("
    SELECT COUNT(*) as count 
    FROM deliveries 
    WHERE DATE(created_at) = DATE(NOW())
")['count'] ?? 0;

$activeDrivers = app_all("
    SELECT id, name, logo_path as avatar_path, latitude, longitude, is_online, 
           status_doc_ci, status_doc_licencia, status_doc_habilitacion, status_doc_cedula_verde,
           doc_ci_path, doc_ci_back_path, doc_licencia_path, doc_licencia_back_path,
           doc_habilitacion_path, doc_habilitacion_back_path, doc_cedula_verde_path, doc_cedula_verde_back_path,
           phone, email, subscription_status, subscription_expires_at,
           (SELECT COUNT(*) FROM deliveries WHERE repartidor_user_id = users.id AND status NOT IN ('entregado', 'cancelado')) as active_delivery_count,
           (SELECT payment_proof_path FROM driver_payments WHERE driver_user_id = users.id AND status = 'pending' ORDER BY id DESC LIMIT 1) as payment_proof_path,
           (SELECT id FROM driver_payments WHERE driver_user_id = users.id AND status = 'pending' ORDER BY id DESC LIMIT 1) as payment_id
    FROM users 
    WHERE role = 'repartidor'
");

$onlineDriversCount = 0;
$onlineDriversTotal = 0;
$offlineDriversTotal = 0;
$deliveringDriversTotal = 0;
foreach ($activeDrivers as $d) {
    if (($d['is_online'] ?? 0) == 1) {
        $onlineDriversTotal++;
    } else {
        $offlineDriversTotal++;
    }
    if ((int)($d['active_delivery_count'] ?? 0) > 0) {
        $deliveringDriversTotal++;
    }
    // Activo = is_online=1 Y hizo ping en los últimos 2 minutos
    $recentPing = !empty($d['last_ping']) && (time() - strtotime($d['last_ping'])) < 120;
    if ($d['is_online'] == 1 && $recentPing && $d['latitude'] && $d['longitude']) {
        $onlineDriversCount++;
    }
}

$activeLocals = app_all("
    SELECT * 
    FROM users 
    WHERE role = 'local' 
    ORDER BY COALESCE(business_name, name) ASC
");
$activeLocalsCount = 0;
foreach ($activeLocals as $l) {
    if (($l['subscription_status'] ?? '') === 'active') {
        $activeLocalsCount++;
    }
}

// Estadísticas del Donut: Rendimiento de Entregas (Esta Semana)
$deliveryStats = app_one("
    SELECT 
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as completados,
        COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelados,
        COUNT(CASE WHEN status NOT IN ('entregado', 'cancelado') THEN 1 END) as en_curso
    FROM deliveries
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$completadosCount = (int)($deliveryStats['completados'] ?? 0);
$canceladosCount = (int)($deliveryStats['cancelados'] ?? 0);
$enCursoCount = (int)($deliveryStats['en_curso'] ?? 0);

// 2. Entregas activas
$activeDeliveries = app_all("
    SELECT d.*, l.business_name as local_name, r.name as driver_name
    FROM deliveries d
    LEFT JOIN users l ON l.id = d.local_user_id
    LEFT JOIN users r ON r.id = d.repartidor_user_id
    WHERE d.status NOT IN ('entregado', 'cancelado')
    ORDER BY d.created_at DESC
");

// Top Comercios (Demanda) y Repartidores Estrella (Esta Semana)
$topLocals = app_all("
    SELECT COALESCE(u.business_name, u.name) as name, COUNT(d.id) as count
    FROM deliveries d
    JOIN users u ON u.id = d.local_user_id
    WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY d.local_user_id
    ORDER BY count DESC
    LIMIT 5
");
$topDrivers = app_all("
    SELECT u.name, COUNT(d.id) as count
    FROM deliveries d
    JOIN users u ON u.id = d.repartidor_user_id
    WHERE d.status = 'entregado'
      AND d.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY d.repartidor_user_id
    ORDER BY count DESC
    LIMIT 5
");


// 3. Usuarios (Repartidores y Locales) con verificaciones o pagos pendientes
$pendingVerifications = [];
foreach ($activeDrivers as $d) {
    if (
        $d['status_doc_ci'] === 'pending' ||
        $d['status_doc_licencia'] === 'pending' ||
        $d['status_doc_habilitacion'] === 'pending' ||
        $d['status_doc_cedula_verde'] === 'pending' ||
        ($d['subscription_status'] ?? '') === 'pending'
    ) {
        $d['user_type'] = 'repartidor';
        $pendingVerifications[] = $d;
    }
}

// Agregar Locales que tengan pagos pendientes de verificación
$pendingLocals = app_all("
    SELECT u.id, u.name, u.business_name, u.logo_path as avatar_path, u.phone, u.email, u.subscription_status, 'local' as user_type,
           (SELECT payment_proof_path FROM driver_payments WHERE driver_user_id = u.id AND status = 'pending' ORDER BY id DESC LIMIT 1) as payment_proof_path,
           (SELECT id FROM driver_payments WHERE driver_user_id = u.id AND status = 'pending' ORDER BY id DESC LIMIT 1) as payment_id
    FROM users u
    WHERE u.role = 'local'
      AND ((SELECT COUNT(*) FROM driver_payments WHERE driver_user_id = u.id AND status = 'pending') > 0 OR u.subscription_status = 'pending')
");

foreach ($pendingLocals as $l) {
    $pendingVerifications[] = $l;
}

// 4. Datos del gráfico semanal
$weeklyStats = app_all("
    SELECT DATE(created_at) as day_date, COUNT(*) as cnt
    FROM deliveries
    WHERE created_at >= DATE_SUB(DATE(NOW()), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) ASC
");

$chartDays = [];
$chartCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $dateStr = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime($dateStr));
    $chartDays[] = $label;
    
    $cnt = 0;
    foreach ($weeklyStats as $ws) {
        if ($ws['day_date'] === $dateStr) {
            $cnt = (int)$ws['cnt'];
            break;
        }
    }
    $chartCounts[] = $cnt;
}
$maxChartCount = max(5, max($chartCounts));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Panel de Administración Premium</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Mapbox GL CDN -->
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.js"></script>
    
    <!-- ApexCharts CDN -->
    <script src="<?= esc(delivery_app_url('assets/js/apexcharts.min.js')) ?>"></script>
    <!-- SweetAlert2 local copy (avoids Tracking‑Prevention blocks) -->
    <script src="<?= esc(delivery_app_url('assets/js/sweetalert2.min.js')) ?>"></script>
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-gradient: linear-gradient(135deg, #2563eb, #1d4ed8);
            --bg-slate: #eef2f6; /* Lavender Gray / Soft Slate background */
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-bg: #ffffff;
            
            /* Neumorphic Soft Shadow & Inner Highlights */
            --clay-shadow: 0 16px 36px rgba(100, 116, 139, 0.08), 
                          0 4px 12px rgba(100, 116, 139, 0.03);
            --clay-inner: inset 0 2px 4px rgba(255, 255, 255, 0.9),
                          inset 0 -2px 4px rgba(0, 0, 0, 0.01);
            
            /* High curvature */
            --radius-large: 24px;
            --radius-medium: 18px;
        }

        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            margin: 0;
            padding: 0;
            background: var(--bg-slate);
            color: var(--text-main);
            height: 100vh;
            overflow: hidden;
        }

        /* 3-Column Layout */
        .dashboard-wrapper {
            display: grid;
            grid-template-columns: 85px 1fr 340px;
            height: 100vh;
            width: 100vw;
            padding: 24px;
            gap: 24px;
        }

        /* Sidebar - Vertical floating blue bar */
        .sidebar {
            background: var(--primary);
            border-radius: var(--radius-large);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            padding: 32px 0;
            box-shadow: 0 20px 40px rgba(37, 99, 235, 0.22);
            position: relative;
        }

        .sidebar-top {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
        }

        /* Notification Icon Circle with Relief */
        .notif-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.2), 
                        inset 0 -2px 5px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            cursor: pointer;
            transition: all 0.3s;
        }
        .notif-circle:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Menu Icons with highlight on select */
        .menu-item {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.65);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
        }
        .menu-item svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.2;
        }
        .menu-item:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.1);
        }
        .menu-item.active {
            color: #ffffff; /* White color icon */
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.15);
            box-shadow: inset 0 2px 4px rgba(255, 255, 255, 0.15), 
                        0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .btn-logout {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        .btn-logout:hover {
            color: #ffffff;
            background: rgba(239, 68, 68, 0.2);
        }

        /* Center Column Content Panel */
        .center-column {
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            padding-right: 4px;
        }
        
        /* Hide scrollbars but keep functionality */
        .center-column::-webkit-scrollbar {
            width: 6px;
        }
        .center-column::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        /* Bento Grid Sections */
        .bento-section {
            display: none;
            flex-direction: column;
            gap: 20px;
        }
        .bento-section.active {
            display: flex;
            animation: slideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Header Title Row */
        .header-title-row {
            margin-bottom: 8px;
        }
        .header-title-row h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0 0 4px;
            letter-spacing: -0.5px;
        }
        .header-title-row p {
            margin: 0;
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Claymorphic Bento Stats cards */
        .overview-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .clay-card-stat {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 20px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.7);
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
        }
        .clay-card-stat::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: var(--radius-large);
            box-shadow: var(--clay-inner);
            pointer-events: none;
        }
        .stat-icon-container {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: inset 0 2px 4px rgba(255, 255, 255, 0.4);
        }
        .clay-blue { background: rgba(37, 99, 235, 0.08); color: var(--primary); }
        .clay-green { background: rgba(16, 185, 129, 0.08); color: #10b981; }
        .clay-orange { background: rgba(245, 158, 11, 0.08); color: #f59e0b; }

        .stat-meta {
            display: flex;
            flex-direction: column;
        }
        .stat-meta span {
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-meta b {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-main);
            margin-top: 2px;
        }

        .donut-filter-select {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
        }
        .donut-filter-select:hover {
            background: #e2e8f0;
        }

        /* Chart filter popover */
        .chart-filter-container {
            position: relative;
            display: inline-block;
        }
        .chart-date-popover {
            position: absolute;
            top: 100%;
            right: 0;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.12), 0 8px 10px -6px rgba(0,0,0,0.08);
            z-index: 200;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 6px;
            width: 200px;
            text-align: left;
            animation: popoverFadeIn 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes popoverFadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .chart-date-popover input[type="date"] {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 5px 8px;
            font-size: 12px;
            width: 100%;
            color: #1e293b;
            font-weight: 500;
            outline: none;
            box-sizing: border-box;
        }
        .chart-date-popover button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            margin-top: 4px;
            transition: background 0.2s;
        }
        .chart-date-popover button:hover { background: #1d4ed8; }

        /* Overview Graph Card with Warm/Cream Gradient */
        .overview-graph-card {
            background: linear-gradient(135deg, #fdf6ec 0%, #ffffff 100%);
            border-radius: 28px;
            padding: 24px;
            box-shadow: 0 20px 40px rgba(100, 110, 140, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.7);
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .graph-title-admin {
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.8px;
            margin-bottom: 4px;
        }

        /* Live Map Bento Card */
        .live-map-card {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 20px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.7);
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
        }
        .live-map-card::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: var(--radius-large);
            box-shadow: var(--clay-inner);
            pointer-events: none;
        }
        .map-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .map-title-row h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -0.2px;
        }
        .map-badge {
            background: rgba(16, 185, 129, 0.08);
            color: #10b981;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Map Container */
        #admin-mapbox {
            height: 380px;
            width: 100%;
            border-radius: var(--radius-medium);
            border: 1px solid #e2e8f0;
        }

        /* Custom circular profile photo driver markers */
        .driver-avatar-marker {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 3px solid #ffffff;
            box-shadow: 0 4px 15px rgba(100, 116, 139, 0.2);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            cursor: pointer;
            transition: all 0.25s;
        }
        .driver-avatar-marker:hover {
            transform: scale(1.15);
            z-index: 10;
            border-color: var(--primary);
        }
        .driver-avatar-marker::after {
            content: '';
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            border: 2px solid #ffffff;
        }
        .driver-avatar-marker.online::after {
            background: #10b981;
            box-shadow: 0 0 6px #10b981;
        }
        .driver-avatar-marker.delivering::after {
            background: #f59e0b;
            box-shadow: 0 0 8px #f59e0b;
            animation: markerPulse 1.5s infinite;
        }
        .driver-avatar-marker.delivering {
            border-color: #f59e0b;
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.4);
        }
        .driver-avatar-marker.offline::after {
            background: #94a3b8;
        }
        @keyframes markerPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        /* Right Panel - Verification lists */
        .right-panel {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 24px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.7);
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow-y: auto;
            position: relative;
        }
        .right-panel::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: var(--radius-large);
            box-shadow: var(--clay-inner);
            pointer-events: none;
        }
        
        .right-panel::-webkit-scrollbar {
            width: 4px;
        }
        .right-panel::-webkit-scrollbar-thumb {
            background: #e2e8f0;
            border-radius: 2px;
        }

        .panel-section-title {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.2px;
        }

        .verification-feed {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .verification-card {
            background: #ffffff;
            border-radius: var(--radius-medium);
            padding: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 12px;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
        }
        .verification-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            transform: translateY(-2px);
        }
        .verification-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .driver-mini-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .driver-mini-avatar {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            overflow: hidden;
        }
        .driver-mini-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .driver-text {
            display: flex;
            flex-direction: column;
        }
        .driver-text b {
            font-size: 12px;
            color: var(--text-main);
        }
        .driver-text span {
            font-size: 10px;
            color: #d97706;
            font-weight: 700;
            margin-top: 1px;
        }

        .btn-view-chevron {
            font-size: 16px;
            color: #94a3b8;
        }

        /* Sub-sections inside tables / lists */
        .table-card-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .table-row-item {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-medium);
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .table-row-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
            border-color: #cbd5e1;
        }
        
        /* Select sub status styling */
        .status-pill-select {
            padding: 6px 12px;
            border-radius: var(--radius-medium);
            font-size: 11px;
            font-weight: 700;
            border: 1px solid #cbd5e1;
            outline: none;
            cursor: pointer;
            background: #ffffff;
            transition: all 0.2s;
        }
        .status-pill-select:hover {
            border-color: #94a3b8;
        }

        .status-select-active {
            background: #dcfce7 !important;
            color: #15803d !important;
            border-color: #bbf7d0 !important;
        }
        .status-select-expired {
            background: #fee2e2 !important;
            color: #b91c1c !important;
            border-color: #fecaca !important;
        }
        .status-select-pending {
            background: #fef3c7 !important;
            color: #d97706 !important;
            border-color: #fde68a !important;
        }

        .modal-overlay, .admin-modal-overlay, .lightbox-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .admin-modal-overlay.active, .lightbox-overlay.active, .modal-overlay.active {
            display: flex !important;
        }

        .admin-modal-card {
            background: #ffffff;
            border-radius: 28px;
            width: 90%;
            max-width: 560px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 28px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
            animation: modalPop 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
        }

        .modal-header-admin {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e2e8f0;
        }
        .modal-header-admin h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }
        .modal-close-btn {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 14px;
            font-weight: 800;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .modal-close-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .driver-docs-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .doc-verify-row {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px;
        }
        .doc-verify-row h5 {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 10px;
            color: #0f172a;
        }
        .doc-images-flex {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }
        .doc-img-wrap {
            height: 120px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            background: #000;
        }
        .doc-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }
        .doc-img-wrap:hover img {
            transform: scale(1.05);
        }
        .doc-actions-admin {
            display: flex;
            gap: 10px;
        }
        .btn-action-admin {
            flex: 1;
            padding: 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 800;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-approve-admin {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }
        .btn-reject-admin {
            background: #ef4444;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        .lightbox-img {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }

        /* Indicador Online/Offline de Repartidores */
        .online-indicator-dot {
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            border: 2px solid #ffffff;
            z-index: 3;
        }
        .online-indicator-dot.is-online {
            background: #10b981;
            box-shadow: 0 0 0 2px #ffffff, 0 0 8px rgba(16, 185, 129, 0.7);
            animation: pulse-online-dot 2s infinite;
        }
        .online-indicator-dot.is-offline {
            background: #94a3b8;
            box-shadow: 0 0 0 2px #ffffff;
        }
        @keyframes pulse-online-dot {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7), 0 0 0 2px #ffffff; }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0), 0 0 0 2px #ffffff; }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0), 0 0 0 2px #ffffff; }
        }

        .live-status-tag {
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            line-height: 1.2;
            letter-spacing: -0.2px;
            transition: all 0.2s ease;
        }
        .live-status-tag.tag-online {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
            border: 1px solid rgba(37, 99, 235, 0.25);
        }
        .live-status-tag.tag-offline {
            background: rgba(148, 163, 184, 0.12);
            color: #64748b;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .live-status-tag.tag-delivering {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.15) 0%, rgba(29, 78, 216, 0.2) 100%);
            color: var(--primary);
            border: 1px solid rgba(37, 99, 235, 0.4);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
        }

        /* Success & Confirmation Modal Card (Estilo del Proyecto) */
        .success-modal-card {
            background: #ffffff;
            width: 90%;
            max-width: 340px;
            border-radius: 28px;
            padding: 40px 24px 30px;
            text-align: center;
            position: relative;
            border-top: 6px solid var(--primary);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.15);
            animation: modalPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        @keyframes modalPop { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .modal-close-top { 
            position: absolute; 
            top: 16px; right: 16px; 
            width: 32px; height: 32px; 
            background: #f1f5f9; 
            border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            cursor: pointer; 
            border: none; 
            font-weight: 800; 
            color: #94a3b8; 
            font-size: 14px;
            transition: all 0.2s;
        }
        .modal-close-top:hover { background: #e2e8f0; color: #475569; }
        .modal-close-top:active { transform: scale(0.9); }
        
        .status-icon-container { 
            width: 80px; height: 80px; 
            border-radius: 50%; 
            background: var(--primary-soft); 
            margin: 0 auto 20px; 
            display: flex; align-items: center; justify-content: center; 
            position: relative; 
        }
        .status-icon-waves { 
            position: absolute; 
            width: 100%; height: 100%; 
            border-radius: 50%; 
            border: 2px solid var(--primary-soft); 
            animation: waveRipple 2s infinite; 
        }
        @keyframes waveRipple { from { transform: scale(1); opacity: 1; } to { transform: scale(1.6); opacity: 0; } }
        .check-mark { font-size: 36px; color: var(--primary); font-weight: 800; z-index: 2; }

        .success-modal-card h2 { font-size: 20px; font-weight: 800; margin: 0 0 8px; color: var(--text-main); letter-spacing: -0.5px; }
        .success-modal-card p { font-size: 13.5px; color: var(--text-muted); margin: 0 0 24px; font-weight: 600; line-height: 1.4; }
        
        .btn-listo { 
            background: var(--primary); 
            color: #ffffff; 
            width: 100%; 
            padding: 14px; 
            border-radius: 16px; 
            font-weight: 800; 
            font-size: 14px;
            border: none; 
            cursor: pointer; 
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25); 
            transition: all 0.2s; 
        }

        /* Row subscription status borders & backgrounds */
        .table-row-item.row-expired {
            border-left: 4px solid #ef4444 !important;
            background: rgba(239, 68, 68, 0.02) !important;
        }
        .table-row-item.row-expired .driver-mini-avatar {
            border: 2.5px solid #ef4444 !important;
            background: #fee2e2 !important;
            color: #b91c1c !important;
        }

        .table-row-item.row-pending {
            border-left: 4px solid #f59e0b !important;
            background: rgba(245, 158, 11, 0.02) !important;
        }
        .table-row-item.row-pending .driver-mini-avatar {
            border: 2.5px solid #f59e0b !important;
            background: #fef3c7 !important;
            color: #d97706 !important;
            animation: pulse-avatar-glow 2s infinite;
        }

        .table-row-item.row-active {
            border-left: 4px solid #10b981 !important;
        }
        .table-row-item.row-active .driver-mini-avatar {
            border: 2.5px solid #10b981 !important;
            background: #dcfce7 !important;
            color: #15803d !important;
        }

        @keyframes pulse-avatar-glow {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }

        /* Document Dots / Badges in Lists */
        .doc-badges {
            display: flex;
            gap: 6px;
            margin-top: 5px;
        }
        .doc-dot {
            font-size: 9px;
            font-weight: 700;
            padding: 3px 6px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            display: inline-flex;
            align-items: center;
            line-height: 1;
        }
        .doc-dot.doc-approved {
            background: rgba(16, 185, 129, 0.08);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, 0.15);
        }
        .doc-dot.doc-pending {
            background: rgba(245, 158, 11, 0.08);
            color: #92400e;
            border: 1px solid rgba(245, 158, 11, 0.15);
        }
        .doc-dot.doc-rejected {
            background: rgba(239, 68, 68, 0.08);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.15);
        }
        .doc-dot.doc-none {
            background: rgba(148, 163, 184, 0.08);
            color: #475569;
            border: 1px solid rgba(148, 163, 184, 0.15);
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- A. Sidebar (Barra Lateral) -->
    <div class="sidebar">
        <div class="sidebar-top">
            <!-- Top: circular notifications signal icon -->
            <div class="notif-circle" title="Verificaciones Pendientes" onclick="scrollToPendingVerifications()" style="position:relative;">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0M3.124 7.5A8.969 8.969 0 015.292 3m13.416 0a8.969 8.969 0 012.168 4.5"></path></svg>
                <?php if (!empty($pendingVerifications)): ?>
                    <span style="position:absolute; top:-2px; right:-2px; background:#ef4444; color:#fff; font-size:10px; font-weight:800; width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #2563eb;"><?= count($pendingVerifications) ?></span>
                <?php endif; ?>
            </div>
            
            <div class="sidebar-menu">
                <div class="menu-item active" id="menu-overview" onclick="switchAdminTab('overview')" title="Inicio / Mapa en Vivo">
                    <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"></path></svg>
                </div>
                <div class="menu-item" id="menu-locales" onclick="switchAdminTab('locales')" title="Locales & Suscripciones">
                    <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72M6.75 18h3.5a.75.75 0 00.75-.75V14a.75.75 0 00-.75-.75h-3.5a.75.75 0 00-.75.75v3.25c0 .414.336.75.75.75z"></path></svg>
                </div>
                <div class="menu-item" id="menu-repartidores" onclick="switchAdminTab('repartidores')" title="Repartidores & Documentos">
                    <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>
                </div>
                <div class="menu-item" id="menu-pedidos" onclick="switchAdminTab('pedidos')" title="Pedidos en Tiempo Real">
                    <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                </div>
            </div>
        </div>
        
        <!-- Salir button -->
        <a href="../logout.php" class="btn-logout" title="Cerrar Sesión">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 01-3-3h4a3 3 0 013 3v1"></path></svg>
        </a>
    </div>
    
    <!-- B. Center Column (Contenido Principal) -->
    <div class="center-column">
        
        <!-- Tab 1: Overview -->
        <div id="tab-overview" class="bento-section active">
            <div class="header-title-row">
                <h1>Hola, Administrador</h1>
                <p>Resumen de operaciones y mapa interactivo en vivo.</p>
            </div>
            
            <!-- Bento Stats Grid -->
            <div class="overview-stats">
                <div class="clay-card-stat">
                    <div class="stat-icon-container clay-blue">📦</div>
                    <div class="stat-meta">
                        <span>Pedidos Hoy</span>
                        <b><?php echo $todayOrders ?></b>
                    </div>
                </div>
                <div class="clay-card-stat">
                    <div class="stat-icon-container clay-green">🛵</div>
                    <div class="stat-meta">
                        <span>Drivers Activos</span>
                        <b><?php echo $onlineDriversCount ?></b>
                    </div>
                </div>
                <div class="clay-card-stat">
                    <div class="stat-icon-container clay-orange">🏢</div>
                    <div class="stat-meta">
                        <span>Locales Activos</span>
                        <b><?php echo $activeLocalsCount ?></b>
                    </div>
                </div>
            </div>
            
            <!-- Spline Chart Card: FLUJO DE ACTIVIDAD -->
            <div class="overview-graph-card">
                <div class="graph-title-admin">FLUJO DE ACTIVIDAD TEMPORAL</div>
                <div id="flujo-actividad-chart" style="width: 100%; min-height: 250px;"></div>
            </div>
            
            <!-- Grid de 3 Columnas: Dona, Top Comercios, Repartidores Estrella -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; align-items: stretch; margin-top: 20px;">
                
                <!-- Donut Chart Card: RENDIMIENTO DE ENTREGAS -->
                <div class="live-map-card" style="margin-bottom: 0; justify-content: space-between; display: flex; flex-direction: column;">
                    <div class="map-title-row">
                        <h3>Rendimiento de Entregas</h3>
                        <div class="chart-filter-container">
                            <select id="filter-rendimiento" class="donut-filter-select" onchange="handleChartFilterChange('donut', this)">
                                <option value="day">Hoy</option>
                                <option value="week" selected>Esta Semana</option>
                                <option value="month">Este Mes</option>
                                <option value="custom">Rango Personalizado...</option>
                            </select>
                            <div class="chart-date-popover" id="popover-donut" style="display:none;">
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Desde:</span>
                                    <input type="date" id="donut-start" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;margin-top:4px;">
                                    <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Hasta:</span>
                                    <input type="date" id="donut-end" value="<?= date('Y-m-d') ?>">
                                </div>
                                <button type="button" onclick="applyChartCustomRange('donut')">Filtrar</button>
                            </div>
                        </div>
                    </div>
                    <div id="rendimiento-entregas-chart" style="width: 100%; min-height: 250px; display: flex; align-items: center; justify-content: center;"></div>
                </div>

                <!-- Card: Top Comercios -->
                <div class="live-map-card" style="margin-bottom: 0; justify-content: space-between; display: flex; flex-direction: column;">
                    <div class="map-title-row">
                        <h3>Top 5 Comercios (Más Demandados)</h3>
                        <div class="chart-filter-container">
                            <select id="filter-top-locales" class="donut-filter-select" onchange="handleChartFilterChange('locales', this)">
                                <option value="day">Hoy</option>
                                <option value="week" selected>Esta Semana</option>
                                <option value="month">Este Mes</option>
                                <option value="custom">Rango Personalizado...</option>
                            </select>
                            <div class="chart-date-popover" id="popover-locales" style="display:none;">
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Desde:</span>
                                    <input type="date" id="locales-start" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;margin-top:4px;">
                                    <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Hasta:</span>
                                    <input type="date" id="locales-end" value="<?= date('Y-m-d') ?>">
                                </div>
                                <button type="button" onclick="applyChartCustomRange('locales')">Filtrar</button>
                            </div>
                        </div>
                    </div>
                    <div id="top-locales-chart" style="width: 100%; min-height: 250px;"></div>
                </div>

                <!-- Card: Repartidores Estrella -->
                <div class="live-map-card" style="margin-bottom: 0; justify-content: space-between; display: flex; flex-direction: column;">
                    <div class="map-title-row">
                        <h3>Repartidores Estrella (Top Entregas)</h3>
                        <div class="chart-filter-container">
                            <select id="filter-top-repartidores" class="donut-filter-select" onchange="handleChartFilterChange('repartidores', this)">
                                <option value="day">Hoy</option>
                                <option value="week" selected>Esta Semana</option>
                                <option value="month">Este Mes</option>
                                <option value="custom">Rango Personalizado...</option>
                            </select>
                            <div class="chart-date-popover" id="popover-repartidores" style="display:none;">
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Desde:</span>
                                    <input type="date" id="repartidores-start" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;margin-top:4px;">
                                    <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Hasta:</span>
                                    <input type="date" id="repartidores-end" value="<?= date('Y-m-d') ?>">
                                </div>
                                <button type="button" onclick="applyChartCustomRange('repartidores')">Filtrar</button>
                            </div>
                        </div>
                    </div>
                    <div id="top-repartidores-chart" style="width: 100%; min-height: 250px;"></div>
                </div>

            </div>

            <!-- Live Map Card (Full Width at the bottom) -->
            <div class="live-map-card" style="margin-top: 20px;">
                <div class="map-title-row">
                    <h3>Mapa en Vivo (Ubicación de Drivers)</h3>
                    <div class="map-badge">
                        <span style="width:6px; height:6px; border-radius:50%; background:#10b981; display:inline-block; animation: pulse 1.5s infinite;"></span>
                        Live Tracking
                    </div>
                </div>
                <div id="admin-mapbox" style="height: 420px; width: 100%;"></div>
            </div>
        </div>

        <!-- Tab 2: Locales & Suscripciones -->
        <div id="tab-locales" class="bento-section">
            <div class="header-title-row">
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
                    <div>
                        <h1>Locales y Comercios</h1>
                        <p>Gestiona los accesos y estado de suscripción de los comercios.</p>
                    </div>
                    <button onclick="openCreateUserModal('local')" style="background:#10b981; color:#fff; border:none; border-radius:14px; padding:10px 18px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; box-shadow:0 4px 14px rgba(16,185,129,0.3); white-space:nowrap; transition:all 0.2s;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Nuevo Comercio
                    </button>
                </div>
            </div>
            
            <div class="table-card-list">
                <?php if (empty($activeLocals)): ?>
                    <div style="text-align:center; padding: 40px; color:var(--text-muted);">No hay comercios registrados.</div>
                <?php else: ?>
                    <?php foreach ($activeLocals as $l): ?>
                        <?php 
                            $status = $l['subscription_status'] ?? 'pending';
                        ?>
                        <div class="table-row-item row-<?= esc($status) ?>" onclick="if (event.target.tagName !== 'SELECT' && event.target.tagName !== 'OPTION') window.location.href='admin_local_detail.php?id=<?= (int)$l['id']; ?>'" style="cursor:pointer; position:relative; padding-right:50px;">
                            <div class="driver-mini-info">
                                <div class="driver-mini-avatar">
                                    <?php if ($l['logo_path']): ?>
                                        <img src="<?php echo esc(delivery_app_url($l['logo_path'])) ?>" alt="Logo">
                                    <?php else: ?>
                                        🏢
                                    <?php endif; ?>
                                </div>
                                <div class="driver-text">
                                    <b><?= esc($l['business_name'] ?: $l['name']) ?></b>
                                    <span style="color:var(--text-muted); font-size:10px;">Vence: <?= $l['subscription_expires_at'] ? date('d/m/Y', strtotime($l['subscription_expires_at'])) : 'N/A' ?></span>
                                </div>
                            </div>
                            
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div>
                                    <select class="status-pill-select status-select-<?= esc($status) ?>" onchange="updateSubscription(<?= $l['id']; ?>, this.value)">
                                        <option value="active" <?php echo $status === 'active' ? 'selected' : '' ?>>Activo (Mensual)</option>
                                        <option value="expired" <?php echo $status === 'expired' ? 'selected' : '' ?>>Expirado</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : '' ?>>Pendiente</option>
                                    </select>
                                </div>
                                <button type="button" onclick="quickDeleteLocal(<?= (int)$l['id']; ?>, '<?= esc($l['business_name'] ?: $l['name']); ?>', event)" title="Eliminar Comercio" style="background:transparent; color:#ef4444; border:none; padding:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; border-radius:8px; transition:all 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18"></path>
                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path>
                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                    </svg>
                                </button>
                                <div class="btn-view-chevron">&rsaquo;</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab 3: Repartidores & Documentos -->
        <div id="tab-repartidores" class="bento-section">
            <div class="header-title-row">
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
                    <div>
                        <h1>Repartidores Registrados</h1>
                        <p style="margin-bottom:4px;">Verifica y edita estados de conductores y sus documentos.</p>
                        <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                            <span class="live-status-tag tag-online" id="header-count-online" style="font-size:11px; padding:4px 10px;">🟢 Conectados: <?= $onlineDriversTotal ?></span>
                            <span class="live-status-tag tag-offline" id="header-count-offline" style="font-size:11px; padding:4px 10px;">⚪ Desconectados: <?= $offlineDriversTotal ?></span>
                            <span class="live-status-tag tag-delivering" id="header-count-delivering" style="font-size:11px; padding:4px 10px;">🛵 En Entrega: <?= $deliveringDriversTotal ?></span>
                        </div>
                    </div>
                    <button onclick="openCreateUserModal('repartidor')" style="background:var(--primary); color:#fff; border:none; border-radius:14px; padding:10px 18px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; box-shadow:0 4px 12px rgba(37,99,235,0.3); white-space:nowrap; transition:all 0.2s;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Nuevo Repartidor
                    </button>
                </div>
            </div>
            
            <div class="table-card-list">
                <?php if (empty($activeDrivers)): ?>
                    <div style="text-align:center; padding: 40px; color:var(--text-muted);">No hay repartidores registrados.</div>
                <?php else: ?>
                    <?php foreach ($activeDrivers as $d): ?>
                        <?php $dStatus = $d['subscription_status'] ?? 'pending'; ?>
                        <div class="table-row-item row-<?= esc($dStatus) ?>" data-driver-id="<?= (int)$d['id']; ?>" onclick="if (event.target.tagName !== 'SELECT' && event.target.tagName !== 'OPTION') window.location.href='admin_driver_detail.php?id=<?= (int)$d['id']; ?>'" style="cursor:pointer; position:relative; padding-right:50px;">
                            <div class="driver-mini-info">
                                <div class="driver-mini-avatar" style="position:relative;">
                                    <?php if ($d['avatar_path']): ?>
                                        <img src="<?= esc(delivery_app_url($d['avatar_path'])); ?>" alt="Avatar">
                                    <?php else: ?>
                                        👤
                                    <?php endif; ?>
                                    <span class="online-indicator-dot <?= ($d['is_online'] ?? 0) == 1 ? 'is-online' : 'is-offline' ?>" title="<?= ($d['is_online'] ?? 0) == 1 ? 'Conectado / En línea' : 'Desconectado / Apagado' ?>"></span>
                                </div>
                                <div class="driver-text">
                                     <div class="driver-tags-container" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                         <b><?= esc($d['name']) ?></b>
                                         <?php if (($d['is_online'] ?? 0) == 1): ?>
                                             <span class="live-status-tag tag-status-main tag-online">🟢 Conectado</span>
                                         <?php else: ?>
                                             <span class="live-status-tag tag-status-main tag-offline">⚪ Desconectado</span>
                                         <?php endif; ?>
                                         <?php if ((int)($d['active_delivery_count'] ?? 0) > 0): ?>
                                             <span class="live-status-tag tag-delivering">🛵 En Entrega (<?= (int)$d['active_delivery_count'] ?>)</span>
                                         <?php endif; ?>
                                     </div>
                                     <span style="color:var(--text-muted); font-size:11px; font-weight:600;">Vence: <?= !empty($d['subscription_expires_at']) ? date('d/m/Y', strtotime($d['subscription_expires_at'])) : 'Sin fecha' ?></span>
                                 </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div>
                                    <select class="status-pill-select status-select-<?= esc($dStatus) ?>" onchange="updateDriverSubscription(<?= (int)$d['id']; ?>, this.value); event.stopPropagation();">
                                        <option value="active"  <?= $dStatus === 'active'  ? 'selected' : '' ?>>Activo (Semanal)</option>
                                        <option value="expired" <?= $dStatus === 'expired' ? 'selected' : '' ?>>Expirado</option>
                                        <option value="pending" <?= $dStatus === 'pending' ? 'selected' : '' ?>>Pendiente</option>
                                    </select>
                                </div>
                                <button type="button" onclick="quickDeleteDriver(<?= (int)$d['id']; ?>, '<?= esc($d['name']); ?>', event)" title="Eliminar Repartidor" style="background:transparent; color:#ef4444; border:none; padding:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; border-radius:8px; transition:all 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18"></path>
                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path>
                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                    </svg>
                                </button>
                                <div class="btn-view-chevron">&rsaquo;</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab 4: Pedidos -->
        <div id="tab-pedidos" class="bento-section">
            <div class="header-title-row">
                <h1>Pedidos Activos</h1>
                <p>Supervisa todos los pedidos activos en tránsito.</p>
            </div>
            
            <div class="table-card-list" id="active-deliveries-list">
                <?php if (empty($activeDeliveries)): ?>
                    <div style="text-align:center; padding:40px; color:var(--text-muted);">No hay entregas activas en tránsito.</div>
                <?php else: ?>
                    <?php foreach ($activeDeliveries as $ad): 
                        $statusStr = strtolower($ad['status'] ?? 'pendiente');
                        $statusLabel = 'Pedido Recibido';
                        $statusClass = 'doc-pending';
                        $statusStyle = 'background:rgba(245, 158, 11, 0.1); color:#d97706; border:1px solid rgba(245, 158, 11, 0.3);';

                        if (in_array($statusStr, ['aceptado', 'asignado'], true)) {
                            $statusLabel = 'Camino al Local';
                            $statusClass = 'doc-approved';
                            $statusStyle = 'background:rgba(37, 99, 235, 0.1); color:#2563eb; border:1px solid rgba(37, 99, 235, 0.3);';
                        } else if (in_array($statusStr, ['repartidor_en_local', 'llegue_al_local'], true)) {
                            $statusLabel = 'En el Local / Retirando';
                            $statusClass = 'doc-approved';
                            $statusStyle = 'background:rgba(124, 58, 237, 0.1); color:#7c3aed; border:1px solid rgba(124, 58, 237, 0.3);';
                        } else if (in_array($statusStr, ['en_camino_al_cliente', 'en_camino', 'en_puerta'], true)) {
                            $statusLabel = 'Entregando Pedido';
                            $statusClass = 'doc-approved';
                            $statusStyle = 'background:rgba(16, 185, 129, 0.15); color:#047857; border:1px solid rgba(16, 185, 129, 0.4);';
                        }
                    ?>
                        <div class="table-row-item" style="flex-direction:column; align-items:flex-start;">
                            <div style="display:flex; justify-content:space-between; width:100%; border-bottom:1px solid #f1f5f9; padding-bottom:8px; margin-bottom:8px;">
                                <span style="font-weight:800;">Pedido #<?php echo $ad['id'] ?></span>
                                <span class="doc-dot <?php echo $statusClass ?>" style="<?php echo $statusStyle ?>"><?php echo $statusLabel ?></span>
                            </div>
                            <div style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px; width:100%;">
                                <div><b>Local:</b> <?php echo esc($ad['local_name'] ?: 'N/A') ?></div>
                                <div><b>Repartidor:</b> <span style="font-weight:800; <?php echo !empty($ad['driver_name']) ? 'color:var(--primary);' : '' ?>"><?php echo esc($ad['driver_name'] ?: 'No asignado') ?></span></div>
                                <div><b>Dirección:</b> <?php echo esc($ad['delivery_address']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
    
    <!-- C. Right Panel (Alertas y Verificaciones Rápidas) -->
    <div class="right-panel">
        <h3 class="panel-section-title">Verificaciones Pendientes</h3>
        
        <div class="verification-feed" id="pending-verifications-feed">
            <?php if (empty($pendingVerifications)): ?>
                <div style="text-align:center; padding: 40px 10px; color: var(--text-muted); font-size:12px;">
                    <span style="font-size:24px; display:block; margin-bottom:8px;">🎉</span>
                    Todos los usuarios están verificados.
                </div>
            <?php else: ?>
                <?php foreach ($pendingVerifications as $pv): ?>
                    <?php 
                    $isLocal = ($pv['user_type'] ?? 'repartidor') === 'local';
                    $targetUrl = $isLocal ? "admin_local_detail.php?id=" . (int)$pv['id'] : "admin_driver_detail.php?id=" . (int)$pv['id'];
                    $displayName = $isLocal ? ($pv['business_name'] ?: $pv['name']) : $pv['name'];
                    ?>
                    <div class="verification-card" onclick="window.location.href='<?= $targetUrl ?>'">
                        <div class="verification-card-header">
                            <div class="driver-mini-info">
                                <div class="driver-mini-avatar">
                                    <?php if ($pv['avatar_path']): ?>
                                        <img src="<?= esc(delivery_app_url($pv['avatar_path'])); ?>" alt="Avatar">
                                    <?php else: ?>
                                        <?= $isLocal ? '🏢' : '👤' ?>
                                    <?php endif; ?>
                                </div>
                                <div class="driver-text">
                                    <b><?= esc($displayName) ?></b>
                                    <span style="font-size:11px; margin-top:2px;">
                                        <?php if ($isLocal): ?>
                                            🏪 Local · 💳 Pago por verificar
                                        <?php elseif (($pv['subscription_status'] ?? '') === 'pending'): ?>
                                            🛵 Repartidor · 💳 Pago por verificar
                                        <?php else: ?>
                                            🛵 Repartidor · 📄 Documentación requerida
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="btn-view-chevron" style="position:static; font-size:20px;">&rsaquo;</div>
                        </div>

                        <!-- Imagen del comprobante de pago -->
                        <?php if (!empty($pv['payment_proof_path'])): ?>
                            <div class="payment-proof" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:10px; text-align:center;">
                                <img src="<?= esc(delivery_app_url($pv['payment_proof_path'])); ?>" alt="Comprobante" style="max-width:100%; max-height:160px; object-fit:contain; border-radius:10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <p style="margin:6px 0 0; font-size:11px; color:#64748b; font-weight:600;">
                                    Comprobante de pago <?= $isLocal ? 'del local' : 'del repartidor' ?> <?= esc($displayName); ?>
                                </p>
                            </div>
                            <div style="display:flex; gap:10px; margin-top:2px;" onclick="event.stopPropagation();">
                                <button type="button" style="flex:1; padding:10px; font-size:12px; font-weight:800; border:none; border-radius:12px; background:#10b981; color:#fff; cursor:pointer; box-shadow: 0 4px 12px rgba(16,185,129,0.25); transition:all 0.2s;" onclick="verifyDashboardSubscription('approved', <?= (int)$pv['id']; ?>, <?= (int)$pv['payment_id']; ?>)">Aprobar</button>
                                <button type="button" style="flex:1; padding:10px; font-size:12px; font-weight:800; border:none; border-radius:12px; background:#ef4444; color:#fff; cursor:pointer; box-shadow: 0 4px 12px rgba(239,68,68,0.25); transition:all 0.2s;" onclick="verifyDashboardSubscription('rejected', <?= (int)$pv['id']; ?>, <?= (int)$pv['payment_id']; ?>)">Rechazar</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <hr style="border:none; border-top:1px solid #f1f5f9; margin: 10px 0;">
        
        <h3 class="panel-section-title">Pedidos sin Conductor</h3>
        <div class="verification-feed" style="overflow-y:auto;">
            <?php 
            $unassigned = array_filter($activeDeliveries, function($a) { return empty($a['driver_name']); });
            if (empty($unassigned)):
            ?>
                <div style="text-align:center; padding: 20px; color: var(--text-muted); font-size:11px;">
                    No hay pedidos en cola de asignación.
                </div>
            <?php else: ?>
                <?php foreach (array_slice($unassigned, 0, 4) as $ua): ?>
                    <div class="verification-card" style="cursor:default;">
                        <div class="driver-text">
                            <b>#<?php echo $ua['id'] ?> · <?php echo esc($ua['local_name']) ?></b>
                            <span style="color:#d97706; font-size:10px;">Buscando repartidor...</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Document Verification Modal -->
<div id="admin-doc-modal" class="admin-modal-overlay" onclick="closeDriverModal(event)">
    <div class="admin-modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-admin">
            <h3 id="modal-driver-name">Verificación de Conductor</h3>
            <button class="modal-close-btn" onclick="closeDriverModal(null)">✕</button>
        </div>
        
        <div class="driver-docs-grid" id="modal-docs-container">
            <!-- Populated via Javascript -->
        </div>
    </div>
</div>

<!-- Image Lightbox -->
<div id="lightbox-modal" class="lightbox-overlay" onclick="closeLightbox()">
    <img id="lightbox-img" class="lightbox-img" src="" alt="Ampliado">
</div>

<!-- Modal Confirmación de Eliminación Repartidor (Estilo success-modal-card) -->
<div id="delete-driver-modal" class="modal-overlay" style="display: none; z-index: 9999;">
    <div class="modal-card success-modal-card" style="border-top: 6px solid #dc2626;">
        <button type="button" class="modal-close-top" onclick="closeDeleteDriverModal()">✕</button>
        <div class="status-icon-container" style="background:#fee2e2;">
            <div class="status-icon-waves" style="border-color:#fca5a5;"></div>
            <span class="check-mark" style="color:#dc2626; font-size:32px;">🗑️</span>
        </div>
        <h2 id="delete-driver-modal-title">¿Eliminar Repartidor?</h2>
        <p id="delete-driver-modal-msg">¿Estás seguro de que deseas eliminar permanentemente la cuenta de este repartidor? Una vez eliminado, no podrá volver a acceder a la aplicación.</p>
        
        <div style="display:flex; gap:10px; width:100%;">
            <button type="button" class="btn-listo" onclick="closeDeleteDriverModal()" style="background:#f1f5f9; color:#475569; box-shadow:none; flex:1;">
                Cancelar
            </button>
            <button type="button" id="btn-confirm-delete-driver-act" class="btn-listo" style="background:#dc2626; color:#ffffff; box-shadow:0 8px 20px rgba(220, 38, 38, 0.3); flex:1.2;">
                Sí, Eliminar
            </button>
        </div>
    </div>
</div>

<script>
    // Configuración de Mapbox
    const tokenPart1 = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.';
    const tokenPart2 = 'Jn4uXN5yX4DFIImQjw_R4w';
    mapboxgl.accessToken = tokenPart1 + tokenPart2;
    let map = null;
    let markers = [];
    let donutChart = null;
    let topLocalesChart = null;
    let topDriversChart = null;

    // ── Popover helpers ──────────────────────────────────────────────
    const chartLastRange = { donut: 'week', locales: 'week', repartidores: 'week' };

    function handleChartFilterChange(chartKey, sel) {
        const range = sel.value;
        const popover = document.getElementById('popover-' + chartKey);
        // Close all other popovers
        ['donut','locales','repartidores'].forEach(k => {
            if (k !== chartKey) document.getElementById('popover-' + k).style.display = 'none';
        });
        if (range === 'custom') {
            popover.style.display = 'flex';
        } else {
            popover.style.display = 'none';
            chartLastRange[chartKey] = range;
            // Reset custom label
            const customOpt = sel.querySelector('option[value="custom"]');
            if (customOpt) customOpt.textContent = 'Rango Personalizado...';
            if (chartKey === 'donut')        updateDonutFilter(range);
            if (chartKey === 'locales')      updateTopLocalesFilter(range);
            if (chartKey === 'repartidores') updateTopRepartidoresFilter(range);
        }
    }

    function applyChartCustomRange(chartKey) {
        const start = document.getElementById(chartKey + '-start').value;
        const end   = document.getElementById(chartKey + '-end').value;
        if (!start || !end) { alert('Selecciona ambas fechas.'); return; }
        if (new Date(start) > new Date(end)) { alert('La fecha inicio no puede ser posterior a la de fin.'); return; }
        // Close popover
        document.getElementById('popover-' + chartKey).style.display = 'none';
        // Update select label
        const selMap = { donut: 'filter-rendimiento', locales: 'filter-top-locales', repartidores: 'filter-top-repartidores' };
        const sel = document.getElementById(selMap[chartKey]);
        const sf = start.split('-').reverse().slice(0,2).join('/');
        const ef = end.split('-').reverse().slice(0,2).join('/');
        const customOpt = sel.querySelector('option[value="custom"]');
        if (customOpt) customOpt.textContent = `Rango: ${sf} - ${ef}`;
        sel.value = 'custom';
        chartLastRange[chartKey] = 'custom';
        if (chartKey === 'donut')        updateDonutFilter('custom', start, end);
        if (chartKey === 'locales')      updateTopLocalesFilter('custom', start, end);
        if (chartKey === 'repartidores') updateTopRepartidoresFilter('custom', start, end);
    }

    // Close all popovers when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.chart-filter-container')) {
            ['donut','locales','repartidores'].forEach(k => {
                const pop = document.getElementById('popover-' + k);
                if (pop) pop.style.display = 'none';
            });
            // Revert any 'custom' select that wasn't confirmed
            const selMap = { donut: 'filter-rendimiento', locales: 'filter-top-locales', repartidores: 'filter-top-repartidores' };
            Object.keys(selMap).forEach(k => {
                const sel = document.getElementById(selMap[k]);
                if (sel && sel.value === 'custom' && chartLastRange[k] !== 'custom') {
                    sel.value = chartLastRange[k];
                }
            });
        }
    });

    // ── Chart filter functions ───────────────────────────────────────
    function updateDonutFilter(range, startDate = '', endDate = '') {
        const formData = new FormData();
        formData.append('action', 'get_delivery_performance');
        formData.append('range', range);
        if (range === 'custom') {
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
        }
        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success && donutChart) {
                donutChart.updateSeries([data.completados, data.cancelados, data.en_curso]);
            }
        })
        .catch(err => console.error("Error al actualizar filtro:", err));
    }

    function updateTopLocalesFilter(range, startDate = '', endDate = '') {
        const formData = new FormData();
        formData.append('action', 'get_top_locals');
        formData.append('range', range);
        if (range === 'custom') {
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
        }
        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const chartEl  = document.getElementById('top-locales-chart');
            let   emptyMsg = document.getElementById('empty-msg-locales');
            if (data.success) {
                if (data.empty) {
                    chartEl.style.display = 'none';
                    if (!emptyMsg) {
                        emptyMsg = document.createElement('div');
                        emptyMsg.id = 'empty-msg-locales';
                        emptyMsg.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;color:#94a3b8;font-size:13px;font-weight:600;gap:8px;';
                        emptyMsg.innerHTML = '<span style="font-size:32px;">📭</span>Sin pedidos en este período';
                        chartEl.parentNode.appendChild(emptyMsg);
                    } else {
                        emptyMsg.style.display = 'flex';
                    }
                } else {
                    chartEl.style.display = '';
                    if (emptyMsg) emptyMsg.style.display = 'none';
                    if (topLocalesChart) {
                        topLocalesChart.updateOptions({ xaxis: { categories: data.categories } });
                        topLocalesChart.updateSeries([{ name: 'Pedidos', data: data.series }]);
                    }
                }
            }
        })
        .catch(err => console.error("Error al actualizar filtro locales:", err));
    }

    function updateTopRepartidoresFilter(range, startDate = '', endDate = '') {
        const formData = new FormData();
        formData.append('action', 'get_top_drivers');
        formData.append('range', range);
        if (range === 'custom') {
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
        }
        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const chartEl  = document.getElementById('top-repartidores-chart');
            let   emptyMsg = document.getElementById('empty-msg-repartidores');
            if (data.success) {
                if (data.empty) {
                    chartEl.style.display = 'none';
                    if (!emptyMsg) {
                        emptyMsg = document.createElement('div');
                        emptyMsg.id = 'empty-msg-repartidores';
                        emptyMsg.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;color:#94a3b8;font-size:13px;font-weight:600;gap:8px;';
                        emptyMsg.innerHTML = '<span style="font-size:32px;">🏍️</span>Sin entregas en este período';
                        chartEl.parentNode.appendChild(emptyMsg);
                    } else {
                        emptyMsg.style.display = 'flex';
                    }
                } else {
                    chartEl.style.display = '';
                    if (emptyMsg) emptyMsg.style.display = 'none';
                    if (topDriversChart) {
                        topDriversChart.updateOptions({ xaxis: { categories: data.categories } });
                        topDriversChart.updateSeries([{ name: 'Entregas', data: data.series }]);
                    }
                }
            }
        })
        .catch(err => console.error("Error al actualizar filtro repartidores:", err));
    }

    function refreshMapMarkers() {
        if (!map) return;
        
        const formData = new FormData();
        formData.append('action', 'get_active_drivers');
        
        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Eliminar marcadores existentes
                markers.forEach(m => m.remove());
                markers = [];
                
                // Agregar nuevos marcadores de conductores activos
                data.drivers.forEach(d => {
                    const el = document.createElement('div');
                    
                    const isDelivering = parseInt(d.active_delivery_count || 0) > 0;
                    el.className = 'driver-avatar-marker ' + (isDelivering ? 'delivering' : 'online');
                    
                    const avatarUrl = d.avatar_path ? '<?php echo delivery_app_url() ?>/' + d.avatar_path : 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=150&q=80';
                    el.style.backgroundImage = `url('${avatarUrl}')`;
                    
                    let statusLabel = '🟢 En Línea y Disponible';
                    if (isDelivering) {
                        statusLabel = '🟠 Llevando Pedido (En Curso)';
                    }
                    const popup = new mapboxgl.Popup({ offset: 15 }).setHTML(`
                        <div style="font-family:'Plus Jakarta Sans'; font-size:12px; padding:4px;">
                            <b style="font-size:13px; color:#0f172a;">${d.name}</b><br>
                            <span style="color:#64748b;">${statusLabel}</span>
                        </div>
                    `);
                    
                    const marker = new mapboxgl.Marker(el)
                        .setLngLat([parseFloat(d.longitude), parseFloat(d.latitude)])
                        .setPopup(popup)
                        .addTo(map);
                    
                    markers.push(marker);
                });
            }
        })
        .catch(err => console.error("Error al actualizar marcadores del mapa:", err));
    }

    let lastPendingCount = -1;

    function refreshPendingVerifications() {
        const feed = document.getElementById('pending-verifications-feed');
        if (feed && feed.children.length === 0 && window.SkeletonUI) {
            feed.innerHTML = window.SkeletonUI.renderCardGrid(2);
        }

        const formData = new FormData();
        formData.append('action', 'get_pending_verifications');

        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;

            // Reproducir sonido si hay un nuevo ítem pendiente
            if (lastPendingCount >= 0 && data.count > lastPendingCount) {
                const audio = new Audio('<?= delivery_app_url("assets/sounds/notification.mp3") ?>');
                audio.play().catch(e => console.log("Audio play error:", e));
            }
            lastPendingCount = data.count;

            // Actualizar badge en la campana
            const notifCircle = document.querySelector('.notif-circle');
            if (notifCircle) {
                let badge = notifCircle.querySelector('span');
                if (data.count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.style.cssText = 'position:absolute; top:-2px; right:-2px; background:#ef4444; color:#fff; font-size:10px; font-weight:800; width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #2563eb;';
                        notifCircle.appendChild(badge);
                    }
                    badge.textContent = data.count;
                } else if (badge) {
                    badge.remove();
                }
            }

            // Actualizar el HTML del feed
            const feed = document.getElementById('pending-verifications-feed');
            if (!feed) return;

            if (data.count === 0) {
                feed.innerHTML = `
                    <div style="text-align:center; padding: 40px 10px; color: var(--text-muted); font-size:12px;">
                        <span style="font-size:24px; display:block; margin-bottom:8px;">🎉</span>
                        Todos los usuarios están verificados.
                    </div>
                `;
                return;
            }

            let html = '';
            data.items.forEach(pv => {
                const isLocal = (pv.user_type || 'repartidor') === 'local';
                const targetUrl = isLocal ? `admin_local_detail.php?id=${pv.id}` : `admin_driver_detail.php?id=${pv.id}`;
                const displayName = isLocal ? (pv.business_name || pv.name) : pv.name;
                const avatarIcon = isLocal ? '🏢' : '👤';
                const baseUrl = '<?= delivery_app_url() ?>/';

                let avatarHtml = avatarIcon;
                if (pv.avatar_path) {
                    avatarHtml = `<img src="${baseUrl}${pv.avatar_path}" alt="Avatar">`;
                }

                let subtitle = '';
                if (isLocal) {
                    subtitle = '🏪 Local · 💳 Pago por verificar';
                } else if (pv.subscription_status === 'pending') {
                    subtitle = '🛵 Repartidor · 💳 Pago por verificar';
                } else {
                    subtitle = '🛵 Repartidor · 📄 Documentación requerida';
                }

                let proofHtml = '';
                if (pv.payment_proof_path) {
                    proofHtml = `
                        <div class="payment-proof" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:10px; text-align:center;">
                            <img src="${baseUrl}${pv.payment_proof_path}" alt="Comprobante" style="max-width:100%; max-height:160px; object-fit:contain; border-radius:10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                            <p style="margin:6px 0 0; font-size:11px; color:#64748b; font-weight:600;">
                                Comprobante de pago ${isLocal ? 'del local' : 'del repartidor'} ${displayName}
                            </p>
                        </div>
                        <div style="display:flex; gap:10px; margin-top:2px;" onclick="event.stopPropagation();">
                            <button type="button" style="flex:1; padding:10px; font-size:12px; font-weight:800; border:none; border-radius:12px; background:#10b981; color:#fff; cursor:pointer; box-shadow: 0 4px 12px rgba(16,185,129,0.25); transition:all 0.2s;" onclick="verifyDashboardSubscription('approved', ${pv.id}, ${pv.payment_id})">Aprobar</button>
                            <button type="button" style="flex:1; padding:10px; font-size:12px; font-weight:800; border:none; border-radius:12px; background:#ef4444; color:#fff; cursor:pointer; box-shadow: 0 4px 12px rgba(239,68,68,0.25); transition:all 0.2s;" onclick="verifyDashboardSubscription('rejected', ${pv.id}, ${pv.payment_id})">Rechazar</button>
                        </div>
                    `;
                }

                html += `
                    <div class="verification-card" onclick="window.location.href='${targetUrl}'">
                        <div class="verification-card-header">
                            <div class="driver-mini-info">
                                <div class="driver-mini-avatar">
                                    ${avatarHtml}
                                </div>
                                <div class="driver-text">
                                    <b>${displayName}</b>
                                    <span style="font-size:11px; margin-top:2px;">${subtitle}</span>
                                </div>
                            </div>
                            <div class="btn-view-chevron" style="position:static; font-size:20px;">&rsaquo;</div>
                        </div>
                        ${proofHtml}
                    </div>
                `;
            });

            feed.innerHTML = html;
        })
        .catch(err => console.error("Error al actualizar verificaciones pendientes:", err));
    }

    function refreshDriversListLiveStatus() {
        const formData = new FormData();
        formData.append('action', 'get_all_drivers_live_statuses');

        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.drivers) {
                // 1. Actualizar contadores generales en la cabecera de la pestaña Repartidores
                const headerOnline = document.getElementById('header-count-online');
                const headerOffline = document.getElementById('header-count-offline');
                const headerDelivering = document.getElementById('header-count-delivering');
                
                if (headerOnline) headerOnline.textContent = '🟢 Conectados: ' + data.online_count;
                if (headerOffline) headerOffline.textContent = '⚪ Desconectados: ' + data.offline_count;
                if (headerDelivering) headerDelivering.textContent = '🛵 En Entrega: ' + (data.delivering_count || 0);

                // 2. Actualizar cada repartidor en el DOM
                data.drivers.forEach(d => {
                    const driverRow = document.querySelector(`.table-row-item[data-driver-id="${d.id}"]`);
                    if (driverRow) {
                        const isOnline = parseInt(d.is_online) === 1;
                        const activeDeliveries = parseInt(d.active_delivery_count || 0);

                        // Indicador de avatar (Punto verde / gris)
                        const dot = driverRow.querySelector('.online-indicator-dot');
                        if (dot) {
                            dot.className = 'online-indicator-dot ' + (isOnline ? 'is-online' : 'is-offline');
                            dot.title = isOnline ? 'Conectado / En línea' : 'Desconectado / Apagado';
                        }

                        // Tag de estado (🟢 Conectado / ⚪ Desconectado)
                        const tagOnline = driverRow.querySelector('.live-status-tag.tag-status-main');
                        if (tagOnline) {
                            if (isOnline) {
                                tagOnline.className = 'live-status-tag tag-status-main tag-online';
                                tagOnline.textContent = '🟢 Conectado';
                            } else {
                                tagOnline.className = 'live-status-tag tag-status-main tag-offline';
                                tagOnline.textContent = '⚪ Desconectado';
                            }
                        }

                        // Tag de entrega activa (🛵 En Entrega X)
                        let tagDelivering = driverRow.querySelector('.live-status-tag.tag-delivering');
                        if (activeDeliveries > 0) {
                            if (!tagDelivering) {
                                tagDelivering = document.createElement('span');
                                tagDelivering.className = 'live-status-tag tag-delivering';
                                const tagContainer = driverRow.querySelector('.driver-tags-container');
                                if (tagContainer) tagContainer.appendChild(tagDelivering);
                            }
                            tagDelivering.textContent = '🛵 En Entrega (' + activeDeliveries + ')';
                        } else if (tagDelivering) {
                            tagDelivering.remove();
                        }
                    }
                });
            }
        })
        .catch(err => console.error("Error al actualizar estados de repartidores:", err));
    }

    function esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function refreshActiveDeliveriesFeed() {
        const container = document.getElementById('active-deliveries-list');
        if (!container) return;

        const formData = new FormData();
        formData.append('action', 'get_live_active_deliveries');

        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            if (!data.deliveries || data.deliveries.length === 0) {
                container.innerHTML = `<div style="text-align:center; padding:40px; color:var(--text-muted);">No hay entregas activas en tránsito.</div>`;
                return;
            }

            let html = '';
            data.deliveries.forEach(ad => {
                const statusStr = (ad.status || 'pendiente').toLowerCase();
                let statusLabel = 'Pedido Recibido';
                let statusClass = 'doc-pending';
                let statusStyle = 'background:rgba(245, 158, 11, 0.1); color:#d97706; border:1px solid rgba(245, 158, 11, 0.3);';

                if (['aceptado', 'asignado'].includes(statusStr)) {
                    statusLabel = 'Camino al Local';
                    statusClass = 'doc-approved';
                    statusStyle = 'background:rgba(37, 99, 235, 0.1); color:#2563eb; border:1px solid rgba(37, 99, 235, 0.3);';
                } else if (['repartidor_en_local', 'llegue_al_local'].includes(statusStr)) {
                    statusLabel = 'En el Local / Retirando';
                    statusClass = 'doc-approved';
                    statusStyle = 'background:rgba(124, 58, 237, 0.1); color:#7c3aed; border:1px solid rgba(124, 58, 237, 0.3);';
                } else if (['en_camino_al_cliente', 'en_camino', 'en_puerta'].includes(statusStr)) {
                    statusLabel = 'Entregando Pedido';
                    statusClass = 'doc-approved';
                    statusStyle = 'background:rgba(16, 185, 129, 0.15); color:#047857; border:1px solid rgba(16, 185, 129, 0.4);';
                }

                const driverName = ad.driver_name || 'No asignado';

                html += `
                    <div class="table-row-item" style="flex-direction:column; align-items:flex-start;">
                        <div style="display:flex; justify-content:space-between; width:100%; border-bottom:1px solid #f1f5f9; padding-bottom:8px; margin-bottom:8px;">
                            <span style="font-weight:800;">Pedido #${ad.id}</span>
                            <span class="doc-dot ${statusClass}" style="${statusStyle}">${statusLabel}</span>
                        </div>
                        <div style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px; width:100%;">
                            <div><b>Local:</b> ${esc(ad.local_name || 'N/A')}</div>
                            <div><b>Repartidor:</b> <span style="font-weight:800; ${ad.driver_name ? 'color:var(--primary);' : ''}">${esc(driverName)}</span></div>
                            <div><b>Dirección:</b> ${esc(ad.delivery_address || '')}</div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        })
        .catch(err => console.error("Error al actualizar pedidos activos:", err));
    }

    // Cambiar entre pestañas del panel lateral
    function switchAdminTab(tabId) {
        document.querySelectorAll('.menu-item').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.bento-section').forEach(el => el.classList.remove('active'));
        
        document.getElementById('menu-' + tabId).classList.add('active');
        document.getElementById('tab-' + tabId).classList.add('active');
        
        if (tabId === 'overview' && map) {
            // Resize Mapbox so it recalculates width/height inside grid container
            setTimeout(() => {
                map.resize();
            }, 100);
        }
    }

    // Inicializar mapa de Mapbox y marcadores de conductores
    window.onload = () => {
        map = new mapboxgl.Map({
            container: 'admin-mapbox',
            style: 'mapbox://styles/mapbox/light-v11',
            center: [-57.5759, -25.2637], // Asunción, Paraguay
            zoom: 12
        });
        
        map.addControl(new mapboxgl.NavigationControl());
        
        // Parse query parameter to auto-switch to a tab if requested
        const urlParams = new URLSearchParams(window.location.search);
        const requestedTab = urlParams.get('tab');
        if (requestedTab) {
            if (document.getElementById('menu-' + requestedTab)) {
                switchAdminTab(requestedTab);
            }
        }
        
        refreshMapMarkers();
        setInterval(refreshMapMarkers, 8000);

        refreshPendingVerifications();
        setInterval(refreshPendingVerifications, 4000);

        refreshDriversListLiveStatus();
        setInterval(refreshDriversListLiveStatus, 2500);

        refreshActiveDeliveriesFeed();
        setInterval(refreshActiveDeliveriesFeed, 2500);

        // Inicializar ApexCharts Spline Chart: FLUJO DE ACTIVIDAD
        const chartOptions = {
            series: [{
                name: 'Actividad',
                data: [11, 4, 12, 6, 5]
            }],
            chart: {
                height: 220,
                type: 'line',
                toolbar: {
                    show: false
                }
            },
            stroke: {
                width: 4,
                curve: 'smooth',
                colors: ['#2563eb']
            },
            markers: {
                size: 6,
                colors: ['#2563eb'],
                strokeColors: '#ffffff',
                strokeWidth: 3,
                hover: {
                    size: 8
                }
            },
            xaxis: {
                categories: ["abr 16", "abr 17", "abr 22", "abr 23", "abr 24"],
                labels: {
                    style: {
                        colors: '#94a3b8',
                        fontSize: '10px',
                        fontFamily: 'Plus Jakarta Sans, sans-serif',
                        fontWeight: 600
                    }
                },
                axisBorder: {
                    show: false
                },
                axisTicks: {
                    show: false
                }
            },
            yaxis: {
                min: 0,
                max: 12,
                tickAmount: 4,
                labels: {
                    style: {
                        colors: '#94a3b8',
                        fontSize: '10px',
                        fontFamily: 'Plus Jakarta Sans, sans-serif',
                        fontWeight: 600
                    }
                }
            },
            grid: {
                show: true,
                borderColor: 'rgba(148, 163, 184, 0.1)',
                strokeDashArray: 5,
                xaxis: {
                    lines: {
                        show: false
                    }
                },
                yaxis: {
                    lines: {
                        show: true
                    }
                }
            },
            tooltip: {
                theme: 'light'
            }
        };

        const flowChart = new ApexCharts(document.querySelector("#flujo-actividad-chart"), chartOptions);
        flowChart.render();

        // Inicializar ApexCharts Donut Chart: RENDIMIENTO DE ENTREGAS
        const donutTotal = <?= $completadosCount + $canceladosCount + $enCursoCount ?>;
        if (donutTotal > 0) {
            const donutOptions = {
                series: [<?= $completadosCount ?>, <?= $canceladosCount ?>, <?= $enCursoCount ?>],
                labels: ['Entregados', 'Cancelados', 'En Curso'],
                chart: { type: 'donut', height: 240 },
                colors: ['#10b981', '#ef4444', '#f59e0b'],
                legend: {
                    position: 'bottom', fontSize: '11px',
                    fontFamily: 'Plus Jakarta Sans, sans-serif', fontWeight: 600,
                    labels: { colors: '#475569' }
                },
                dataLabels: {
                    enabled: true,
                    style: { fontSize: '11px', fontFamily: 'Plus Jakarta Sans, sans-serif', fontWeight: 700 }
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '65%',
                            labels: {
                                show: true,
                                total: {
                                    show: true, label: 'Total', fontSize: '12px',
                                    fontFamily: 'Plus Jakarta Sans, sans-serif', fontWeight: 700, color: '#64748b',
                                    formatter: function (w) { return w.globals.seriesTotals.reduce((a, b) => a + b, 0) }
                                }
                            }
                        }
                    }
                }
            };
            donutChart = new ApexCharts(document.querySelector("#rendimiento-entregas-chart"), donutOptions);
            donutChart.render();
        } else {
            document.querySelector("#rendimiento-entregas-chart").innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;color:#94a3b8;font-size:13px;font-weight:600;gap:8px;"><span style="font-size:32px;">📊</span>Sin entregas en este período</div>';
        }

        // Inicializar ApexCharts: TOP LOCALES
        const topLocalesData = <?php echo json_encode(array_column($topLocals, 'count')); ?>;
        const topLocalesNames = <?php echo json_encode(array_column($topLocals, 'name')); ?>;
        if (topLocalesData.length > 0) {
            const topLocalsOptions = {
                series: [{ name: 'Pedidos', data: topLocalesData }],
                chart: { type: 'bar', height: 240, toolbar: { show: false } },
                plotOptions: { bar: { horizontal: true, barHeight: '20%', borderRadius: 10, borderRadiusApplication: 'end' } },
                colors: ['#2563eb'],
                xaxis: {
                    categories: topLocalesNames,
                    labels: { style: { colors: '#94a3b8', fontSize: '10px', fontFamily: 'Plus Jakarta Sans, sans-serif', fontWeight: 600 } }
                },
                yaxis: { labels: { style: { colors: '#475569', fontSize: '10px', fontFamily: 'Plus Jakarta Sans, sans-serif', fontWeight: 700 } } },
                grid: { borderColor: 'rgba(148, 163, 184, 0.1)', xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
                tooltip: { theme: 'light' }
            };
            topLocalesChart = new ApexCharts(document.querySelector("#top-locales-chart"), topLocalsOptions);
            topLocalesChart.render();
        } else {
            document.querySelector("#top-locales-chart").innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;color:#94a3b8;font-size:13px;font-weight:600;gap:8px;"><span style="font-size:32px;">📭</span>Sin pedidos en este período</div>';
        }

        // Inicializar ApexCharts: REPARTIDORES ESTRELLA
        const topDriversData = <?php echo json_encode(array_column($topDrivers, 'count')); ?>;
        const topDriversNames = <?php echo json_encode(array_column($topDrivers, 'name')); ?>;
        if (topDriversData.length > 0) {
            const topDriversOptions = {
                series: [{ name: 'Entregas', data: topDriversData }],
                chart: { type: 'bar', height: 240, toolbar: { show: false } },
                plotOptions: { bar: { horizontal: false, columnWidth: '12%', borderRadius: 8, borderRadiusApplication: 'end' } },
                colors: ['#10b981'],
                xaxis: {
                    categories: topDriversNames,
                    labels: { style: { colors: '#94a3b8', fontSize: '9px', fontFamily: 'Plus Jakarta Sans, sans-serif', fontWeight: 600 } }
                },
                yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px', fontFamily: 'Plus Jakarta Sans, sans-serif', fontWeight: 600 } } },
                grid: { borderColor: 'rgba(148, 163, 184, 0.1)', xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } },
                tooltip: { theme: 'light' }
            };
            topDriversChart = new ApexCharts(document.querySelector("#top-repartidores-chart"), topDriversOptions);
            topDriversChart.render();
        } else {
            document.querySelector("#top-repartidores-chart").innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;color:#94a3b8;font-size:13px;font-weight:600;gap:8px;"><span style="font-size:32px;">🏍️</span>Sin entregas en este período</div>';
        }
    };

    let activeDriverData = null;

    function openDriverModal(driver) {
        activeDriverData = driver;
        document.getElementById('modal-driver-name').textContent = 'Documentos: ' + driver.name;
        
        const container = document.getElementById('modal-docs-container');
        container.innerHTML = '';
        
        const docTypes = [
            { key: 'ci', label: 'Cédula de Identidad' },
            { key: 'licencia', label: 'Registro de Conducir' },
            { key: 'habilitacion', label: 'Habilitación' },
            { key: 'cedula_verde', label: 'Cédula Verde' }
        ];
        
        docTypes.forEach(doc => {
            const frontPath = driver['doc_' + doc.key + '_path'];
            const backPath = driver['doc_' + doc.key + '_back_path'];
            const status = driver['status_doc_' + doc.key];
            
            if (frontPath || backPath) {
                let statusLabel = '';
                if (status === 'approved') statusLabel = '<span style="color:#10b981; font-weight:800;">✓ Aprobado</span>';
                else if (status === 'rejected') statusLabel = '<span style="color:#ef4444; font-weight:800;">❌ Rechazado</span>';
                else statusLabel = '<span style="color:#f59e0b; font-weight:800;">⏳ Pendiente</span>';
                
                const row = document.createElement('div');
                row.className = 'doc-verify-row';
                row.innerHTML = `
                    <div style="display:flex; justify-content:space-between; margin-bottom:12px; align-items:center;">
                        <h5>${doc.label}</h5>
                        <div style="font-size:11px;">${statusLabel}</div>
                    </div>
                    <div class="doc-images-flex">
                        ${frontPath ? `<div class="doc-img-wrap" onclick="openLightbox('${frontPath}')"><img src="../${frontPath}"></div>` : '<div style="color:#94a3b8; font-size:11px; display:flex; align-items:center; justify-content:center; border:1px dashed #cbd5e1; border-radius:12px;">Sin frontal</div>'}
                        ${backPath ? `<div class="doc-img-wrap" onclick="openLightbox('${backPath}')"><img src="../${backPath}"></div>` : '<div style="color:#94a3b8; font-size:11px; display:flex; align-items:center; justify-content:center; border:1px dashed #cbd5e1; border-radius:12px;">Sin posterior</div>'}
                    </div>
                    <div class="doc-actions-admin">
                        <button class="btn-action-admin btn-approve-admin" onclick="verifyDocument(${driver.id}, '${doc.key}', 'approve')">Aprobar</button>
                        <button class="btn-action-admin btn-reject-admin" onclick="verifyDocument(${driver.id}, '${doc.key}', 'reject')">Rechazar</button>
                    </div>
                `;
                container.appendChild(row);
            }
        });
        
        if (container.children.length === 0) {
            container.innerHTML = '<div style="text-align:center; color:#94a3b8; font-weight:600; padding:20px;">Este conductor no ha subido ningún documento aún.</div>';
        }
        
        document.getElementById('admin-doc-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeDriverModal(e) {
        document.getElementById('admin-doc-modal').classList.remove('active');
        document.body.style.overflow = '';
    }
    
    function openLightbox(src) {
        document.getElementById('lightbox-img').src = '../' + src;
        document.getElementById('lightbox-modal').classList.add('active');
    }
    
    function closeLightbox() {
        document.getElementById('lightbox-modal').classList.remove('active');
    }
    
    function verifyDocument(driverId, docType, decision) {
        const action = decision === 'approve' ? 'approve_document' : 'reject_document';
        
        const formData = new FormData();
        formData.append('action', action);
        formData.append('driver_id', driverId);
        formData.append('doc_type', docType);
        
        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (activeDriverData) {
                    activeDriverData['status_doc_' + docType] = data.new_status;
                    openDriverModal(activeDriverData);
                }
                setTimeout(() => {
                    location.reload();
                }, 800);
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error de conexión.');
        });
    }
    
    function updateSubscription(localId, status) {
        const formData = new FormData();
        formData.append('action', 'update_subscription');
        formData.append('local_id', localId);
        formData.append('role', 'local');
        formData.append('status', status);
        
        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) { alert(data.message); location.reload(); }
            else { alert('Error: ' + data.error); }
        })
        .catch(err => { console.error(err); alert('Error de conexión.'); });
    }

    function updateDriverSubscription(driverId, status) {
        const formData = new FormData();
        formData.append('action', 'update_subscription');
        formData.append('user_id', driverId);
        formData.append('role', 'repartidor');
        formData.append('status', status);

        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) { alert(data.message); location.reload(); }
            else { alert('Error: ' + data.error); }
        })
        .catch(err => { console.error(err); alert('Error de conexión.'); });
    }

    let targetDriverToDeleteId = null;
    let targetLocalToDeleteId = null;

    function quickDeleteDriver(driverId, driverName, event) {
        if (event) event.stopPropagation();
        targetLocalToDeleteId = null;
        targetDriverToDeleteId = driverId;
        document.getElementById('delete-driver-modal-title').textContent = '¿Eliminar a ' + driverName + '?';
        document.getElementById('delete-driver-modal-msg').textContent = '¿Estás seguro de que deseas eliminar permanentemente la cuenta de "' + driverName + '"? Una vez eliminado, no podrá volver a acceder a la aplicación.';
        document.getElementById('delete-driver-modal').style.display = 'flex';

        if (window.playNotificationSound) {
            window.playNotificationSound('<?= delivery_app_url("assets/sounds/notification.mp3") ?>');
        }
    }

    function quickDeleteLocal(localId, localName, event) {
        if (event) event.stopPropagation();
        targetDriverToDeleteId = null;
        targetLocalToDeleteId = localId;
        document.getElementById('delete-driver-modal-title').textContent = '¿Eliminar a ' + localName + '?';
        document.getElementById('delete-driver-modal-msg').textContent = '¿Estás seguro de que deseas eliminar permanentemente la cuenta del comercio "' + localName + '"? Esta acción eliminará sus pedidos y datos del sistema.';
        document.getElementById('delete-driver-modal').style.display = 'flex';

        if (window.playNotificationSound) {
            window.playNotificationSound('<?= delivery_app_url("assets/sounds/notification.mp3") ?>');
        }
    }

    function closeDeleteDriverModal() {
        document.getElementById('delete-driver-modal').style.display = 'none';
    }

    const confirmDeleteActBtn = document.getElementById('btn-confirm-delete-driver-act');
    if (confirmDeleteActBtn) {
        confirmDeleteActBtn.onclick = function() {
            if (!targetDriverToDeleteId && !targetLocalToDeleteId) return;
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Eliminando...';

            const formData = new FormData();
            if (targetLocalToDeleteId) {
                formData.append('action', 'delete_local');
                formData.append('local_id', targetLocalToDeleteId);
            } else {
                formData.append('action', 'delete_driver');
                formData.append('driver_id', targetDriverToDeleteId);
            }

            fetch('api_admin_action.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    closeDeleteDriverModal();
                    alert(res.message);
                    location.reload();
                } else {
                    alert('Error: ' + (res.error || 'No se pudo completar la eliminación.'));
                    btn.disabled = false;
                    btn.textContent = 'Sí, Eliminar';
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error al conectar con el servidor.');
                btn.disabled = false;
                btn.textContent = 'Sí, Eliminar';
            });
        };
    }
</script>
<script>
    let paymentAlerted = false;
    let audioUnlocked = false;
    
    // Crear instancia de audio con ruta dinámica
    const notificationSound = new Audio('<?= esc(delivery_app_url('assets/sounds/delivered.mp3')) ?>');
    notificationSound.preload = 'auto';

    // Generar dinámicamente un banner flotante premium para habilitar el sonido
    const banner = document.createElement('div');
    banner.id = 'audio-unlock-banner';
    banner.style.cssText = `
        position: fixed; bottom: 24px; right: 24px; z-index: 999999;
        background: #2563eb; color: #ffffff; padding: 14px 24px; border-radius: 50px;
        box-shadow: 0 10px 30px rgba(37, 99, 235, 0.4); display: flex; align-items: center; gap: 10px;
        font-size: 13.5px; font-weight: 800; cursor: pointer; transition: all 0.3s ease;
        border: 2px solid rgba(255, 255, 255, 0.2);
    `;
    banner.innerHTML = '<span>🔔</span> Habilitar alertas sonoras';
    
    banner.onmouseenter = () => {
        banner.style.transform = 'scale(1.05)';
        banner.style.boxShadow = '0 12px 35px rgba(37, 99, 235, 0.5)';
    };
    banner.onmouseleave = () => {
        banner.style.transform = 'scale(1)';
        banner.style.boxShadow = '0 10px 30px rgba(37, 99, 235, 0.4)';
    };

    function unlockAudio() {
        if (audioUnlocked) return;
        notificationSound.play().then(() => {
            notificationSound.pause();
            notificationSound.currentTime = 0;
            audioUnlocked = true;
            
            // Animación de salida y remoción
            banner.style.opacity = '0';
            banner.style.transform = 'translateY(20px)';
            setTimeout(() => banner.remove(), 500);

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Alertas sonoras activadas con éxito.',
                timer: 2000,
                showConfirmButton: false
            });
        }).catch(err => {
            console.warn('Fallo al desbloquear audio:', err);
        });
    }

    banner.addEventListener('click', (e) => {
        e.stopPropagation();
        unlockAudio();
    });
    
    // También desbloquear si hacen click en cualquier parte
    document.addEventListener('click', () => {
        if (!audioUnlocked) unlockAudio();
    }, { once: true });

    // Append banner to body once page finishes loading
    window.addEventListener('load', () => {
        document.body.appendChild(banner);
        pollNewPayments();
    });

    function pollNewPayments() {
        const fd = new FormData();
        fd.append('action', 'check_new_payment');
        fetch('api_admin_action.php', {
            method: 'POST',
            body: fd,
            credentials: 'include'
        })
        .then(res => {
            if (!res.ok) { console.error('Polling failed with status', res.status); return null; }
            return res.text().then(txt => { console.log('Polling response:', txt); try { return JSON.parse(txt); } catch(e){ console.error('JSON parse error:', e); return null; } });
        })
        .then(data => {
            if (!data) return;
            // Reset flag when no new payments
            if (!data.new) {
                paymentAlerted = false;
                return;
            }
            if (data.new && !paymentAlerted) {
                notificationSound.volume = 1.0;
                notificationSound.play().catch(err => {
                    console.warn('Audio playback blocked or failed:', err);
                });
                paymentAlerted = true;

                // Crear y mostrar Toast flotante premium en el DOM
                const payments = data.payments || [];
                const firstPayment = payments[0] || {};
                const driverName = firstPayment.driver_name || 'Un repartidor';
                const driverId = firstPayment.driver_user_id || '';

                const toast = document.createElement('div');
                toast.id = 'floating-payment-toast';
                toast.style.cssText = `
                    position: fixed;
                    bottom: 24px;
                    right: 24px;
                    z-index: 9999;
                    background: #ffffff;
                    border-left: 5px solid #10b981;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
                    padding: 16px 20px;
                    border-radius: 20px;
                    display: flex;
                    align-items: center;
                    gap: 14px;
                    min-width: 320px;
                    max-width: 400px;
                    cursor: pointer;
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    transition: all 0.3s ease;
                    transform: translateY(20px);
                    opacity: 0;
                `;

                toast.innerHTML = `
                    <div style="font-size: 24px; flex-shrink: 0;">💰</div>
                    <div style="flex-grow: 1;">
                        <h4 style="margin: 0 0 4px 0; font-size: 13.5px; font-weight: 800; color: #0f172a;">¡Nuevo Pago Recibido!</h4>
                        <p style="margin: 0; font-size: 12px; font-weight: 600; color: #64748b; line-height: 1.4;">
                            ${driverName} ha subido su comprobante de suscripción.
                        </p>
                    </div>
                    <button style="background: none; border: none; font-size: 14px; cursor: pointer; color: #94a3b8; font-weight: bold; padding: 0 4px;">✕</button>
                `;

                // Agregar efectos hover
                toast.onmouseenter = () => { toast.style.transform = 'scale(1.02)'; };
                toast.onmouseleave = () => { toast.style.transform = 'scale(1)'; };

                document.body.appendChild(toast);

                // Forzar reflow para animación de entrada
                requestAnimationFrame(() => {
                    toast.style.transform = 'translateY(0)';
                    toast.style.opacity = '1';
                });

                const closeToast = () => {
                    toast.style.transform = 'translateY(20px)';
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        toast.remove();
                    }, 300);
                };

                // Redirigir o recargar al hacer click en el toast
                toast.addEventListener('click', (e) => {
                    if (e.target.tagName === 'BUTTON') return;
                    if (driverId) {
                        window.location.href = `admin_driver_detail.php?id=${driverId}`;
                    } else {
                        location.reload();
                    }
                });

                toast.querySelector('button').addEventListener('click', (e) => {
                    e.stopPropagation();
                    closeToast();
                });

                // Autodestruirse tras 5 segundos
                setTimeout(closeToast, 5000);
            }
        })
        .catch(err => console.error('Polling error:', err))
        .finally(() => {
            setTimeout(pollNewPayments, 10000);
        });
    }

    function verifyDashboardSubscription(status, driverId, paymentId) {
        let notes = '';
        if (status === 'rejected') {
            notes = prompt('Por favor, ingresa el motivo del rechazo del comprobante:');
            if (notes === null) return; // Canceló el prompt
        }

        const formData = new FormData();
        formData.append('action', 'verify_driver_payment');
        formData.append('driver_id', driverId);
        formData.append('payment_id', paymentId);
        formData.append('status', status);
        formData.append('notes', notes);

        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success || res.message) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: res.message || 'Comprobante verificado con éxito.',
                    timer: 3000,
                    timerProgressBar: true,
                    showConfirmButton: false
                });
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                alert('Error: ' + (res.error || 'No se pudo verificar.'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error al procesar la verificación.');
        });
    }
</script>

<!-- Modal: Crear Nuevo Usuario -->
<div id="create-user-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); z-index:5000; justify-content:center; align-items:center; padding:20px;">
    <div class="create-user-card" id="cu-card-container">
        <button type="button" onclick="closeCreateUserModal()" class="create-user-close-btn">✕</button>
        <div style="text-align:center; margin-bottom:20px;">
            <h2 id="create-user-title" style="font-size:20px; font-weight:800; color:#0f172a; margin:0 0 6px; letter-spacing:-0.5px;">Nuevo Comercio</h2>
            <p id="create-user-subtitle" style="font-size:13px; color:#64748b; font-weight:500; margin:0;">Completá los datos para dar de alta al comercio.</p>
        </div>
        
        <form id="create-user-form" onsubmit="submitCreateUser(event)" style="display:flex; flex-direction:column; gap:14px;">
            <input type="hidden" id="cu-role" name="role" value="local">
            
            <div id="cu-business-wrap">
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:block;">Nombre del Comercio / Local</label>
                <input type="text" id="cu-business" name="business_name" placeholder="Ej: Pizzería Don Carlos" style="border-radius:14px; border:1.5px solid #e2e8f0; padding:12px 16px; font-size:14px; font-weight:500; width:100%; transition:border 0.2s; background:#f8fafc;">
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:block;">Usuario / Nombre de Responsable</label>
                <input type="text" id="cu-name" name="name" placeholder="Ej: Juan Pérez" required style="border-radius:14px; border:1.5px solid #e2e8f0; padding:12px 16px; font-size:14px; font-weight:500; width:100%; transition:border 0.2s; background:#f8fafc;">
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:block;">Correo Electrónico (Login)</label>
                <input type="email" id="cu-email" name="email" placeholder="correo@ejemplo.com" required style="border-radius:14px; border:1.5px solid #e2e8f0; padding:12px 16px; font-size:14px; font-weight:500; width:100%; transition:border 0.2s; background:#f8fafc;">
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:block;">Teléfono (WhatsApp)</label>
                <input type="tel" id="cu-phone" name="phone" placeholder="Ej: 0981123456" style="border-radius:14px; border:1.5px solid #e2e8f0; padding:12px 16px; font-size:14px; font-weight:500; width:100%; transition:border 0.2s; background:#f8fafc;">
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:block;">Contraseña de Acceso</label>
                <div style="position:relative;">
                    <input type="password" id="cu-password" name="password" placeholder="Mínimo 6 caracteres" required minlength="6" style="border-radius:14px; border:1.5px solid #e2e8f0; padding:12px 16px; font-size:14px; font-weight:500; width:100%; transition:border 0.2s; padding-right:44px; background:#f8fafc;">
                    <button type="button" onclick="togglePasswordVisibility()" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#94a3b8; font-size:18px;" id="cu-eye">👁️</button>
                </div>
            </div>
            
            <div id="cu-error" style="display:none; background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:10px 14px; border-radius:12px; font-size:12px; font-weight:600;"></div>
            
            <button type="submit" id="cu-submit-btn" style="background:#10b981; color:#fff; border:none; border-radius:16px; padding:14px; font-size:15px; font-weight:800; cursor:pointer; margin-top:6px; box-shadow:0 8px 20px rgba(16,185,129,0.3); transition:all 0.2s;">
                Crear Comercio
            </button>
        </form>
    </div>
</div>

<style>
    .create-user-card {
        background: #ffffff;
        width: 100%;
        max-width: 400px;
        border-radius: 28px;
        padding: 32px 28px;
        position: relative;
        border-top: 6px solid #10b981;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.15);
        animation: modalPop 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .create-user-close-btn {
        position: absolute;
        top: 16px; right: 16px;
        background: #f1f5f9;
        border: none;
        width: 32px; height: 32px;
        border-radius: 50%;
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        color: #64748b;
        display: flex; align-items: center; justify-content: center;
        transition: all 0.2s;
    }
    .create-user-close-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
    }
    #create-user-modal input:focus {
        outline: none;
        border-color: #2563eb !important;
        background: #ffffff !important;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
</style>

<script>
    function scrollToPendingVerifications() {
        const feed = document.getElementById('pending-verifications-feed');
        if (feed) {
            feed.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function openCreateUserModal(role) {
        const modal = document.getElementById('create-user-modal');
        const title = document.getElementById('create-user-title');
        const subtitle = document.getElementById('create-user-subtitle');
        const businessWrap = document.getElementById('cu-business-wrap');
        const roleInput = document.getElementById('cu-role');
        const submitBtn = document.getElementById('cu-submit-btn');
        
        const cardContainer = document.getElementById('cu-card-container');
        
        roleInput.value = role;
        document.getElementById('create-user-form').reset();
        document.getElementById('cu-error').style.display = 'none';
        
        if (role === 'local') {
            title.textContent = 'Nuevo Comercio';
            subtitle.textContent = 'Completá los datos para dar de alta al comercio.';
            businessWrap.style.display = 'block';
            cardContainer.style.borderTop = '6px solid #10b981';
            submitBtn.style.background = '#10b981';
            submitBtn.style.boxShadow = '0 8px 20px rgba(16,185,129,0.3)';
            submitBtn.textContent = 'Crear Comercio';
        } else {
            title.textContent = 'Nuevo Repartidor';
            subtitle.textContent = 'Completá los datos para dar de alta al repartidor.';
            businessWrap.style.display = 'none';
            cardContainer.style.borderTop = '6px solid #2563eb';
            submitBtn.style.background = 'var(--primary)';
            submitBtn.style.boxShadow = '0 8px 20px rgba(37,99,235,0.25)';
            submitBtn.textContent = 'Crear Repartidor';
        }
        
        modal.style.display = 'flex';
    }
    
    function closeCreateUserModal() {
        document.getElementById('create-user-modal').style.display = 'none';
    }
    
    function togglePasswordVisibility() {
        const input = document.getElementById('cu-password');
        const eye = document.getElementById('cu-eye');
        if (input.type === 'password') {
            input.type = 'text';
            eye.textContent = '🙈';
        } else {
            input.type = 'password';
            eye.textContent = '👁️';
        }
    }
    
    function submitCreateUser(e) {
        e.preventDefault();
        const errorDiv = document.getElementById('cu-error');
        const submitBtn = document.getElementById('cu-submit-btn');
        errorDiv.style.display = 'none';
        
        const formData = new FormData();
        formData.append('action', 'create_user');
        formData.append('role', document.getElementById('cu-role').value);
        formData.append('name', document.getElementById('cu-name').value.trim());
        formData.append('email', document.getElementById('cu-email').value.trim());
        formData.append('phone', document.getElementById('cu-phone').value.trim());
        formData.append('password', document.getElementById('cu-password').value);
        
        const role = document.getElementById('cu-role').value;
        if (role === 'local') {
            formData.append('business_name', document.getElementById('cu-business').value.trim());
        }
        
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creando...';
        
        fetch('api_admin_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeCreateUserModal();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: role === 'local' ? 'Comercio creado' : 'Repartidor creado',
                        html: `<b>${document.getElementById('cu-name').value}</b><br><small style="color:#64748b;">Email: ${document.getElementById('cu-email').value}</small>`,
                        timer: 2500,
                        timerProgressBar: true,
                        showConfirmButton: false
                    });
                } else {
                    alert(data.message);
                }
                setTimeout(() => window.location.reload(), 1500);
            } else {
                errorDiv.textContent = data.error || 'Error desconocido.';
                errorDiv.style.display = 'block';
            }
        })
        .catch(err => {
            console.error(err);
            errorDiv.textContent = 'Error de conexión con el servidor.';
            errorDiv.style.display = 'block';
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.textContent = role === 'local' ? 'Crear Comercio' : 'Crear Repartidor';
        });
    }
    
    // Close modal on backdrop click
    document.getElementById('create-user-modal').addEventListener('click', function(e) {
        if (e.target === this) closeCreateUserModal();
    });
</script>

</body>
</html>
