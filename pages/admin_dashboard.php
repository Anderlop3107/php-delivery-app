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
    SELECT id, name, avatar_path, latitude, longitude, is_online, 
           status_doc_ci, status_doc_licencia, status_doc_habilitacion, status_doc_cedula_verde,
           doc_ci_path, doc_ci_back_path, doc_licencia_path, doc_licencia_back_path,
           doc_habilitacion_path, doc_habilitacion_back_path, doc_cedula_verde_path, doc_cedula_verde_back_path,
           phone, email,
           (SELECT COUNT(*) FROM deliveries WHERE repartidor_user_id = users.id AND status NOT IN ('entregado', 'cancelado')) as active_delivery_count
    FROM users 
    WHERE role = 'repartidor'
");

$onlineDriversCount = 0;
foreach ($activeDrivers as $d) {
    // Si reportó en los últimos 60 segundos
    if ($d['is_online'] == 1 && $d['latitude'] && $d['longitude']) {
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

// Estadísticas del Donut: Rendimiento de Entregas
$deliveryStats = app_one("
    SELECT 
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as completados,
        COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelados,
        COUNT(CASE WHEN status NOT IN ('entregado', 'cancelado') THEN 1 END) as en_curso
    FROM deliveries
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

// Top Comercios (Demanda) y Repartidores Estrella
$topLocals = app_all("
    SELECT COALESCE(u.business_name, u.name) as name, COUNT(d.id) as count
    FROM deliveries d
    JOIN users u ON u.id = d.local_user_id
    GROUP BY d.local_user_id
    ORDER BY count DESC
    LIMIT 5
");
$topDrivers = app_all("
    SELECT u.name, COUNT(d.id) as count
    FROM deliveries d
    JOIN users u ON u.id = d.repartidor_user_id
    WHERE d.status = 'entregado'
    GROUP BY d.repartidor_user_id
    ORDER BY count DESC
    LIMIT 5
");

// Previews de datos ficticios si la base de datos está vacía para pruebas
if (empty($topLocals)) {
    $topLocals = [
        ['name' => 'Pizza Hut', 'count' => 15],
        ['name' => 'Burger King', 'count' => 12],
        ['name' => 'Lomitos El Gordito', 'count' => 8],
        ['name' => 'Farmacia Catedral', 'count' => 6],
        ['name' => 'Supermercado Stock', 'count' => 4]
    ];
}
if (empty($topDrivers)) {
    $topDrivers = [
        ['name' => 'Juan Perez', 'count' => 14],
        ['name' => 'Carlos Gomez', 'count' => 11],
        ['name' => 'Maria Benitez', 'count' => 9],
        ['name' => 'Lucas Silva', 'count' => 6],
        ['name' => 'Jose Cardozo', 'count' => 4]
    ];
}

// 3. Conductores con verificaciones pendientes
$pendingVerifications = [];
foreach ($activeDrivers as $d) {
    if (
        $d['status_doc_ci'] === 'pending' ||
        $d['status_doc_licencia'] === 'pending' ||
        $d['status_doc_habilitacion'] === 'pending' ||
        $d['status_doc_cedula_verde'] === 'pending'
    ) {
        $pendingVerifications[] = $d;
    }
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
    <title>Panel de Administración Premium</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Mapbox GL CDN -->
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.js"></script>
    
    <!-- ApexCharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
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
            background: #f8fafc;
            border-radius: var(--radius-medium);
            padding: 14px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.2s;
        }
        .verification-card:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
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
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- A. Sidebar (Barra Lateral) -->
    <div class="sidebar">
        <div class="sidebar-top">
            <!-- Top: circular notifications signal icon -->
            <div class="notif-circle" title="Notificaciones del Sistema">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
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
                        <b><?= $todayOrders ?></b>
                    </div>
                </div>
                <div class="clay-card-stat">
                    <div class="stat-icon-container clay-green">🛵</div>
                    <div class="stat-meta">
                        <span>Drivers Activos</span>
                        <b><?= $onlineDriversCount ?></b>
                    </div>
                </div>
                <div class="clay-card-stat">
                    <div class="stat-icon-container clay-orange">🏢</div>
                    <div class="stat-meta">
                        <span>Locales Activos</span>
                        <b><?= $activeLocalsCount ?></b>
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
                        <select id="filter-rendimiento" class="donut-filter-select" onchange="updateDonutFilter(this.value)">
                            <option value="day">Hoy</option>
                            <option value="week" selected>Esta Semana</option>
                            <option value="month">Este Mes</option>
                        </select>
                    </div>
                    <div id="rendimiento-entregas-chart" style="width: 100%; min-height: 250px; display: flex; align-items: center; justify-content: center;"></div>
                </div>

                <!-- Card: Top Comercios -->
                <div class="live-map-card" style="margin-bottom: 0; justify-content: space-between; display: flex; flex-direction: column;">
                    <div class="map-title-row">
                        <h3>Top 5 Comercios (Más Demandados)</h3>
                    </div>
                    <div id="top-locales-chart" style="width: 100%; min-height: 250px;"></div>
                </div>

                <!-- Card: Repartidores Estrella -->
                <div class="live-map-card" style="margin-bottom: 0; justify-content: space-between; display: flex; flex-direction: column;">
                    <div class="map-title-row">
                        <h3>Repartidores Estrella (Top Entregas)</h3>
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
                <h1>Locales y Comercios</h1>
                <p>Gestiona los accesos y estado de suscripción de los comercios.</p>
            </div>
            
            <div class="table-card-list">
                <?php if (empty($activeLocals)): ?>
                    <div style="text-align:center; padding: 40px; color:var(--text-muted);">No hay comercios registrados.</div>
                <?php else: ?>
                    <?php foreach ($activeLocals as $l): ?>
                        <div class="table-row-item">
                            <div class="driver-mini-info">
                                <div class="driver-mini-avatar">
                                    <?php if ($l['logo_path']): ?>
                                        <img src="<?= esc(delivery_app_url($l['logo_path'])) ?>" alt="Logo">
                                    <?php else: ?>
                                        🏢
                                    <?php endif; ?>
                                </div>
                                <div class="driver-text">
                                    <b><?= esc($l['business_name'] ?: $l['name']) ?></b>
                                    <span style="color:var(--text-muted); font-size:10px;">Vence: <?= $l['subscription_expires_at'] ? date('d/m/Y', strtotime($l['subscription_expires_at'])) : 'N/A' ?></span>
                                </div>
                            </div>
                            
                            <div>
                                <select class="status-pill-select" onchange="updateSubscription(<?= $l['id'] ?>, this.value)">
                                    <option value="active" <?= $l['subscription_status'] === 'active' ? 'selected' : '' ?>>Activo (+30d)</option>
                                    <option value="expired" <?= $l['subscription_status'] === 'expired' ? 'selected' : '' ?>>Expirado</option>
                                    <option value="pending" <?= $l['subscription_status'] === 'pending' ? 'selected' : '' ?>>Pendiente</option>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab 3: Repartidores & Documentos -->
        <div id="tab-repartidores" class="bento-section">
            <div class="header-title-row">
                <h1>Repartidores Registrados</h1>
                <p>Verifica y edita estados de conductores y sus documentos.</p>
            </div>
            
            <div class="table-card-list">
                <?php if (empty($activeDrivers)): ?>
                    <div style="text-align:center; padding: 40px; color:var(--text-muted);">No hay repartidores registrados.</div>
                <?php else: ?>
                    <?php foreach ($activeDrivers as $d): ?>
                        <div class="table-row-item" onclick='openDriverModal(<?= json_encode($d) ?>)' style="cursor:pointer;">
                            <div class="driver-mini-info">
                                <div class="driver-mini-avatar">
                                    <?php if ($d['avatar_path']): ?>
                                        <img src="<?= esc(delivery_app_url($d['avatar_path'])) ?>" alt="Avatar">
                                    <?php else: ?>
                                        👤
                                    <?php endif; ?>
                                </div>
                                <div class="driver-text">
                                    <b><?= esc($d['name']) ?></b>
                                    <div class="doc-badges">
                                        <span class="doc-dot doc-<?= $d['status_doc_ci'] ?>">CI: <?= $d['status_doc_ci'] ?></span>
                                        <span class="doc-dot doc-<?= $d['status_doc_licencia'] ?>">Lic: <?= $d['status_doc_licencia'] ?></span>
                                        <span class="doc-dot doc-<?= $d['status_doc_habilitacion'] ?>">Hab: <?= $d['status_doc_habilitacion'] ?></span>
                                        <span class="doc-dot doc-<?= $d['status_doc_cedula_verde'] ?>">Verde: <?= $d['status_doc_cedula_verde'] ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="btn-view-chevron">&rsaquo;</div>
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
            
            <div class="table-card-list">
                <?php if (empty($activeDeliveries)): ?>
                    <div style="text-align:center; padding:40px; color:var(--text-muted);">No hay entregas activas en tránsito.</div>
                <?php else: ?>
                    <?php foreach ($activeDeliveries as $ad): ?>
                        <div class="table-row-item" style="flex-direction:column; align-items:flex-start;">
                            <div style="display:flex; justify-content:space-between; width:100%; border-bottom:1px solid #f1f5f9; padding-bottom:8px; margin-bottom:8px;">
                                <span style="font-weight:800;">Pedido #<?= $ad['id'] ?></span>
                                <span class="doc-dot doc-pending"><?= strtoupper($ad['status']) ?></span>
                            </div>
                            <div style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px; width:100%;">
                                <div><b>Local:</b> <?= esc($ad['local_name'] ?: 'N/A') ?></div>
                                <div><b>Repartidor:</b> <?= esc($ad['driver_name'] ?: 'No asignado') ?></div>
                                <div><b>Dirección:</b> <?= esc($ad['delivery_address']) ?></div>
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
        
        <div class="verification-feed">
            <?php if (empty($pendingVerifications)): ?>
                <div style="text-align:center; padding: 40px 10px; color: var(--text-muted); font-size:12px;">
                    <span style="font-size:24px; display:block; margin-bottom:8px;">🎉</span>
                    Todos los conductores están verificados.
                </div>
            <?php else: ?>
                <?php foreach ($pendingVerifications as $pv): ?>
                    <div class="verification-card" onclick='openDriverModal(<?= json_encode($pv) ?>)'>
                        <div class="driver-mini-info">
                            <div class="driver-mini-avatar">
                                <?php if ($pv['avatar_path']): ?>
                                    <img src="<?= esc(delivery_app_url($pv['avatar_path'])) ?>" alt="Avatar">
                                <?php else: ?>
                                    👤
                                <?php endif; ?>
                            </div>
                            <div class="driver-text">
                                <b><?= esc($pv['name']) ?></b>
                                <span>Verificación requerida</span>
                            </div>
                        </div>
                        <div class="btn-view-chevron">&rsaquo;</div>
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
                            <b>#<?= $ua['id'] ?> · <?= esc($ua['local_name']) ?></b>
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

<script>
    // Configuración de Mapbox
    const tokenPart1 = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.';
    const tokenPart2 = 'Jn4uXN5yX4DFIImQjw_R4w';
    mapboxgl.accessToken = tokenPart1 + tokenPart2;
    let map = null;
    let markers = [];
    let donutChart = null;

    function updateDonutFilter(range) {
        const formData = new FormData();
        formData.append('action', 'get_delivery_performance');
        formData.append('range', range);
        
        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && donutChart) {
                donutChart.updateSeries([data.completados, data.cancelados, data.en_curso]);
            }
        })
        .catch(err => console.error("Error al actualizar filtro:", err));
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
        
        // Agregar marcadores de conductores
        const drivers = <?= json_encode($activeDrivers) ?>;
        drivers.forEach(d => {
            // MOSTRAR SÓLO SI EL DELIVERY ESTÁ ACTIVO (is_online == 1) Y TIENE COORDENADAS
            if (d.is_online == 1 && d.latitude && d.longitude) {
                const el = document.createElement('div');
                
                // Identificar si tiene un pedido activo para ponerle la clase 'delivering'
                const isDelivering = parseInt(d.active_delivery_count || 0) > 0;
                el.className = 'driver-avatar-marker ' + (isDelivering ? 'delivering' : 'online');
                
                const avatarUrl = d.avatar_path ? '../' + d.avatar_path : 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=150&q=80';
                el.style.backgroundImage = `url('${avatarUrl}')`;
                
                // Popup al hacer clic identificando estado de pedido
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
            }
        });

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
        const donutOptions = {
            series: [<?= $completadosCount ?>, <?= $canceladosCount ?>, <?= $enCursoCount ?>],
            labels: ['Entregados', 'Cancelados', 'En Curso'],
            chart: {
                type: 'donut',
                height: 240
            },
            colors: ['#10b981', '#ef4444', '#f59e0b'],
            legend: {
                position: 'bottom',
                fontSize: '11px',
                fontFamily: 'Plus Jakarta Sans, sans-serif',
                fontWeight: 600,
                labels: {
                    colors: '#475569'
                }
            },
            dataLabels: {
                enabled: true,
                style: {
                    fontSize: '11px',
                    fontFamily: 'Plus Jakarta Sans, sans-serif',
                    fontWeight: 700
                }
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Total',
                                fontSize: '12px',
                                fontFamily: 'Plus Jakarta Sans, sans-serif',
                                fontWeight: 700,
                                color: '#64748b',
                                formatter: function (w) {
                                    return w.globals.seriesTotals.reduce((a, b) => a + b, 0)
                                }
                            }
                        }
                    }
                }
            }
        };

        donutChart = new ApexCharts(document.querySelector("#rendimiento-entregas-chart"), donutOptions);
        donutChart.render();

        // Inicializar ApexCharts Donut Chart: TOP LOCALES
        const topLocalsOptions = {
            series: [{
                name: 'Pedidos',
                data: <?= json_encode(array_column($topLocals, 'count')) ?>
            }],
            chart: {
                type: 'bar',
                height: 240,
                toolbar: { show: false }
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    barHeight: '20%',
                    borderRadius: 10,
                    borderRadiusApplication: 'end'
                }
            },
            colors: ['#2563eb'],
            xaxis: {
                categories: <?= json_encode(array_column($topLocals, 'name')) ?>,
                labels: {
                    style: {
                        colors: '#94a3b8',
                        fontSize: '10px',
                        fontFamily: 'Plus Jakarta Sans, sans-serif',
                        fontWeight: 600
                    }
                }
            },
            yaxis: {
                labels: {
                    style: {
                        colors: '#475569',
                        fontSize: '10px',
                        fontFamily: 'Plus Jakarta Sans, sans-serif',
                        fontWeight: 700
                    }
                }
            },
            grid: {
                borderColor: 'rgba(148, 163, 184, 0.1)',
                xaxis: { lines: { show: true } },
                yaxis: { lines: { show: false } }
            },
            tooltip: { theme: 'light' }
        };
        const topLocalsChart = new ApexCharts(document.querySelector("#top-locales-chart"), topLocalsOptions);
        topLocalsChart.render();

        // Inicializar ApexCharts Donut Chart: REPARTIDORES ESTRELLA
        const topDriversOptions = {
            series: [{
                name: 'Entregas',
                data: <?= json_encode(array_column($topDrivers, 'count')) ?>
            }],
            chart: {
                type: 'bar',
                height: 240,
                toolbar: { show: false }
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '12%',
                    borderRadius: 8,
                    borderRadiusApplication: 'end'
                }
            },
            colors: ['#10b981'],
            xaxis: {
                categories: <?= json_encode(array_column($topDrivers, 'name')) ?>,
                labels: {
                    style: {
                        colors: '#94a3b8',
                        fontSize: '9px',
                        fontFamily: 'Plus Jakarta Sans, sans-serif',
                        fontWeight: 600
                    }
                }
            },
            yaxis: {
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
                borderColor: 'rgba(148, 163, 184, 0.1)',
                xaxis: { lines: { show: false } },
                yaxis: { lines: { show: true } }
            },
            tooltip: { theme: 'light' }
        };
        const topDriversChart = new ApexCharts(document.querySelector("#top-repartidores-chart"), topDriversOptions);
        topDriversChart.render();
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
        formData.append('status', status);
        formData.append('days', 30);
        
        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error de conexión.');
        });
    }
</script>

</body>
</html>
