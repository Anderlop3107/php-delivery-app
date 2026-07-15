<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] !== 'admin') {
    header('Location: ' . delivery_app_url('dashboard.php'));
    exit;
}

$driverId = (int)($_GET['id'] ?? 0);
if ($driverId <= 0) {
    die("ID de repartidor inválido.");
}

// Obtener datos del repartidor
$driverData = app_one("SELECT * FROM users WHERE id = ? AND role = 'repartidor'", "i", [$driverId]);
if (!$driverData) {
    die("Repartidor no encontrado.");
}

// Obtener último comprobante subido
$latestPayment = app_one("
    SELECT * FROM driver_payments 
    WHERE driver_user_id = ? 
    ORDER BY id DESC LIMIT 1
", "i", [$driverId]);

// Obtener historial de últimos 4 pagos
$paymentHistory = app_all("
    SELECT * FROM driver_payments 
    WHERE driver_user_id = ? 
    ORDER BY id DESC LIMIT 4
", "i", [$driverId]);

// Verificar si hay alguna notificación pendiente de pago para este driver
$hasPendingNotification = app_one("SELECT COUNT(*) as count FROM driver_payments WHERE driver_user_id = ? AND status = 'pending'", "i", [$driverId])['count'] > 0 ? 1 : 0;

// Obtener cantidad de pedidos activos
$activeCountRow = app_one("
    SELECT COUNT(*) as count 
    FROM deliveries 
    WHERE repartidor_user_id = ? 
      AND status NOT IN ('entregado', 'cancelado')
", "i", [$driverId]);
$activeCount = (int)($activeCountRow['count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Detalle de Repartidor: <?= esc($driverData['name']) ?></title>
    
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
            --primary-soft: rgba(37, 99, 235, 0.1);
            --primary-gradient: linear-gradient(135deg, #2563eb, #1d4ed8);
            --bg-slate: #eef2f6;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-bg: #ffffff;
            --clay-shadow: 0 16px 36px rgba(100, 116, 139, 0.08), 
                          0 4px 12px rgba(100, 116, 139, 0.03);
            --clay-inner: inset 0 2px 4px rgba(255, 255, 255, 0.9);
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

        .detail-layout {
            display: grid;
            grid-template-columns: 85px 1fr;
            height: 100vh;
            width: 100vw;
            padding: 24px;
            gap: 24px;
        }

        /* Sidebar - Identical to admin_dashboard */
        .sidebar {
            background: var(--primary);
            border-radius: var(--radius-large);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 30px 0;
            box-shadow: 0 20px 40px rgba(37, 99, 235, 0.15);
        }
        .sidebar-logo {
            width: 44px;
            height: 44px;
            background: #ffffff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            color: var(--primary);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .menu-item {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .menu-item svg {
            width: 24px;
            height: 24px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.2;
        }
        .menu-item:hover, .menu-item.active {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        .btn-logout {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-logout svg {
            width: 24px;
            height: 24px;
        }
        .btn-logout:hover {
            color: #ffffff;
            background: rgba(239, 68, 68, 0.2);
        }

        /* Scrollable main view */
        .scrollable-content {
            overflow-y: auto;
            padding-right: 8px;
            height: calc(100vh - 48px);
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        /* Custom scrollbar */
        .scrollable-content::-webkit-scrollbar {
            width: 6px;
        }
        .scrollable-content::-webkit-scrollbar-track {
            background: transparent;
        }
        .scrollable-content::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        /* Top Welcome Bar */
        .welcome-bar {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .welcome-title-row h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }
        .welcome-title-row p {
            font-size: 14px;
            color: var(--text-muted);
            margin: 4px 0 0;
            font-weight: 600;
        }
        .quick-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .action-circle-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            position: relative;
        }
        .action-circle-btn:hover {
            background: var(--primary);
            color: #ffffff;
            transform: scale(1.05);
        }
        .action-circle-btn svg {
            width: 22px;
            height: 22px;
            stroke-width: 2.2;
        }
        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: #ffffff;
            font-size: 9px;
            font-weight: 800;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2.2px solid #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            line-height: 1;
        }
        .btn-back-admin {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            color: var(--text-main);
            padding: 12px 20px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-back-admin:hover {
            background: #e2e8f0;
        }

        /* KPIs Row */
        .kpis-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .kpi-card {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 24px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .kpi-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kpi-select {
            border: none;
            background: #f1f5f9;
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-main);
            outline: none;
            cursor: pointer;
        }
        .kpi-body {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .kpi-value-box b {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-main);
        }
        .kpi-value-box span {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 4px;
        }
        .kpi-chart-box {
            width: 110px;
            height: 60px;
        }

        /* Central Detail Grid */
        .central-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 24px;
        }
        .bento-card {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 28px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .bento-card h3 {
            font-size: 18px;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Card Account styling */
        .avatar-uploader-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 20px;
        }
        .driver-profile-avatar {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            border: 4px solid #f1f5f9;
            object-fit: cover;
            margin: 0 auto 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        }
        .account-inputs {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .input-group label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
        }
        .input-group input {
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            font-size: 13px;
            outline: none;
            font-weight: 600;
            background: #f8fafc;
        }
        .input-group input:focus {
            border-color: var(--primary);
            background: #ffffff;
        }

        /* Card Documents styling */
        .docs-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .doc-item-row {
            background: #f8fafc;
            border-radius: 14px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
        }
        .doc-item-row:hover {
            border-color: var(--primary);
            background: #f1f5f9;
        }
        .doc-meta b {
            font-size: 13px;
            color: var(--text-main);
        }
        .doc-meta span {
            display: block;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .status-pill {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .status-pill.approved { background: #d1fae5; color: #065f46; }
        .status-pill.pending { background: #fef3c7; color: #92400e; }
        .status-pill.rejected { background: #fee2e2; color: #991b1b; }
        .status-pill.none { background: #f1f5f9; color: #64748b; }

        /* Card Subscription styling */
        .sub-proof-preview {
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 140px;
            background: #f8fafc;
            cursor: pointer;
            position: relative;
        }
        .sub-proof-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .sub-proof-placeholder {
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
        }
        .sub-controls {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .btn-sub-verify {
            padding: 12px;
            border: none;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .btn-sub-verify.btn-approve {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-sub-verify.btn-reject {
            background: #ef4444;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }
        .btn-sub-verify:hover {
            transform: translateY(-1px);
            opacity: 0.95;
        }
        
        .prorroga-box {
            background: #f1f5f9;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .prorroga-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .prorroga-row {
            display: flex;
            gap: 10px;
        }
        .prorroga-select {
            flex: 1;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 12px;
            font-weight: 700;
            outline: none;
        }
        .btn-prorroga {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        
        .sub-history-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
        }
        .sub-history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 6px;
        }

        /* Card Map styling */
        .map-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .map-visual-toggle {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 2px;
            display: flex;
            gap: 2px;
        }
        .visual-btn {
            border: none;
            background: transparent;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }
        .visual-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .map-container-box {
            height: 240px;
            width: 100%;
            border-radius: 18px;
            overflow: hidden;
            position: relative;
        }
        #driver-detail-map {
            width: 100%;
            height: 100%;
        }
        .map-floating-controls {
            position: absolute;
            bottom: 12px;
            right: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            z-index: 10;
        }
        .map-control-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            color: var(--text-main);
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        .map-control-btn:hover {
            background: #f8fafc;
        }
        .map-bottom-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
        }

        /* History styling */
        .history-card {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 28px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            margin-bottom: 24px;
        }
        .history-card h3 {
            font-size: 18px;
            font-weight: 800;
            margin: 0 0 20px;
            color: var(--text-main);
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        .history-table th {
            padding: 12px 16px;
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 700;
            border-bottom: 1px solid #e2e8f0;
        }
        .history-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-weight: 600;
        }
        .history-table tr:last-child td {
            border-bottom: none;
        }
        .btn-load-more {
            display: block;
            width: 100%;
            text-align: center;
            background: #f1f5f9;
            color: var(--text-main);
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.2s;
        }
        .btn-load-more:hover {
            background: #e2e8f0;
        }

        /* Modal / Document Dialog */
        .doc-modal-overlay {
            position: fixed; inset: 0; z-index: 100001;
            background: rgba(15,23,42,0.6); backdrop-filter: blur(10px);
            display: none; align-items: center; justify-content: center;
            padding: 20px;
        }
        .doc-modal-card {
            background: #ffffff; border-radius: 24px; padding: 28px;
            width: 100%; max-width: 600px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.15);
            display: flex; flex-direction: column; gap: 20px;
            animation: scaleUp 0.3s cubic-bezier(0.34,1.56,0.64,1);
        }
        @keyframes scaleUp { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .doc-modal-title {
            font-size: 18px; font-weight: 800; margin: 0; color: #0f172a;
            display: flex; justify-content: space-between; align-items: center;
        }
        .doc-modal-close {
            background: transparent; border: none; font-size: 20px; cursor: pointer; color: #64748b;
        }
        .doc-modal-images {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }
        .doc-img-container {
            border-radius: 12px; overflow: hidden; height: 160px; background: #f8fafc;
            border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center;
            cursor: pointer;
        }
        .doc-img-container img { width: 100%; height: 100%; object-fit: cover; }
        .doc-modal-actions {
            display: flex; gap: 12px; justify-content: flex-end;
        }
        
        /* Lightbox overlay */
        .lightbox-overlay {
            position: fixed; inset: 0; z-index: 100002;
            background: rgba(15,23,42,0.9);
            display: none; align-items: center; justify-content: center;
            cursor: pointer;
        }
        .lightbox-img {
            max-width: 90%; max-height: 90%; border-radius: 12px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>

    <div class="detail-layout">
        <!-- Sidebar - identical design -->
        <div class="sidebar">
            <div class="sidebar-logo">🛵</div>
            
            <div class="sidebar-menu">
                <a href="admin_dashboard.php" class="menu-item active" title="Volver al Panel Principal">
                    <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"></path></svg>
                </a>
            </div>
            
            <a href="../logout.php" class="btn-logout" title="Cerrar Sesión">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 01-3-3h4a3 3 0 013 3v1"></path></svg>
            </a>
        </div>

        <!-- Scrollable Main Content -->
        <div class="scrollable-content">
            
            <!-- Welcome / Header Bar -->
            <div class="welcome-bar">
                <div class="welcome-title-row">
                    <h1>Repartidor: <?= esc($driverData['name']) ?></h1>
                    <p>Detalle de actividad, documentación y estado de suscripción.</p>
                </div>
                <div class="quick-actions">
                    <!-- Campanita de comprobantes pendientes -->
                    <button id="bell-notif-btn" class="action-circle-btn" title="Notificaciones" onclick="scrollToSubscriptionCard()">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0M3.124 7.5A8.969 8.969 0 015.292 3m13.416 0a8.969 8.969 0 012.168 4.5"></path></svg>
                        <?php if ($hasPendingNotification): ?>
                            <span class="notif-badge">1</span>
                        <?php endif; ?>
                    </button>
                    <!-- Burbuja de Chat/Soporte -->
                    <a href="https://wa.me/<?= esc($driverData['whatsapp'] ?: $driverData['phone']) ?>" target="_blank" class="action-circle-btn" title="Enviar WhatsApp / Soporte">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 18l-.153-.055A8.96 8.96 0 013 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"></path></svg>
                    </a>
                    <a href="admin_dashboard.php" class="btn-back-admin">
                        &larr; Volver al Panel
                    </a>
                </div>
            </div>

            <!-- KPIs Row -->
            <div class="kpis-grid">
                <!-- KPI 1: Pedidos Entregados -->
                <div class="kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-title">Pedidos Entregados</span>
                        <select class="kpi-select" onchange="updateKPIsRange(this.value)">
                            <option value="day">Hoy</option>
                            <option value="week" selected>Esta Semana</option>
                            <option value="month">Este Mes</option>
                        </select>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-value-box">
                            <b id="kpi-delivered-val">0</b>
                            <span>📦 Envíos Totales</span>
                        </div>
                        <div class="kpi-chart-box">
                            <div id="chart-delivered"></div>
                        </div>
                    </div>
                </div>

                <!-- KPI 2: Ingresos / Balance -->
                <div class="kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-title">Balance / Ingresos</span>
                        <select class="kpi-select" onchange="updateKPIsRange(this.value)">
                            <option value="day">Hoy</option>
                            <option value="week" selected>Esta Semana</option>
                            <option value="month">Este Mes</option>
                        </select>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-value-box">
                            <b id="kpi-earnings-val">0 Gs.</b>
                            <span id="kpi-hours-val">⏱️ 0 hrs Conectado</span>
                        </div>
                        <div class="kpi-chart-box">
                            <div id="chart-earnings"></div>
                        </div>
                    </div>
                </div>

                <!-- KPI 3: Cancelados -->
                <div class="kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-title">Pedidos Cancelados</span>
                        <select class="kpi-select" onchange="updateKPIsRange(this.value)">
                            <option value="day">Hoy</option>
                            <option value="week" selected>Esta Semana</option>
                            <option value="month">Este Mes</option>
                        </select>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-value-box">
                            <b id="kpi-cancelled-val">0</b>
                            <span>❌ Entregas Fallidas</span>
                        </div>
                        <div class="kpi-chart-box">
                            <div id="chart-cancelled"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bento Detail Grid -->
            <div class="central-grid">
                
                <!-- Columna Izquierda: Cuenta -->
                <div class="bento-card">
                    <h3>👤 Cuenta de Acceso</h3>
                    <div class="avatar-uploader-section">
                        <?php if ($driverData['logo_path']): ?>
                            <img class="driver-profile-avatar" src="<?= esc(delivery_app_url($driverData['logo_path'])) ?>?v=<?= time() ?>" alt="Avatar">
                        <?php else: ?>
                            <div class="driver-profile-avatar" style="background:#cbd5e1; display:flex; align-items:center; justify-content:center; font-size:32px; margin:0 auto 12px;">👤</div>
                        <?php endif; ?>
                        <b style="font-size:16px; color:#0f172a;"><?= esc($driverData['name']) ?></b>
                        <div style="font-size:12px; color:#64748b; margin-top:2px;">Rol: Repartidor</div>
                    </div>
                    
                    <div class="account-inputs">
                        <div class="input-group">
                            <label>Email</label>
                            <input type="text" value="<?= esc($driverData['email']) ?>" readonly>
                        </div>
                        <div class="input-group">
                            <label>Teléfono</label>
                            <input type="text" value="<?= esc($driverData['phone']) ?>" readonly>
                        </div>
                        <div class="input-group">
                            <label>Whatsapp</label>
                            <input type="text" value="<?= esc($driverData['whatsapp'] ?: $driverData['phone']) ?>" readonly>
                        </div>
                        <div class="input-group">
                            <label>Contraseña Hashed</label>
                            <input type="text" value="••••••••••••" readonly>
                        </div>
                    </div>
                </div>

                <!-- Columna Central: Documentos & Suscripción -->
                <div class="bento-card" style="gap:24px;">
                    <!-- Sub-Card: Documentación -->
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <h3>📄 Documentación Registrada</h3>
                        <div class="docs-list">
                            <!-- CI -->
                            <div class="doc-item-row" onclick="openDocModal('ci', 'Cédula de Identidad', '<?= esc($driverData['doc_ci_path']) ?>', '<?= esc($driverData['doc_ci_back_path']) ?>', '<?= $driverData['status_doc_ci'] ?>')">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="doc-mini-preview" style="width: 40px; height: 30px; border-radius: 6px; overflow: hidden; background: #e2e8f0; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center;">
                                        <?php if ($driverData['doc_ci_path']): ?>
                                            <img src="<?= esc(delivery_app_url($driverData['doc_ci_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <span style="font-size:12px;">📄</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-meta">
                                        <b>Cédula de Identidad</b>
                                        <span>Frente y Dorso</span>
                                    </div>
                                </div>
                                <span class="status-pill <?= $driverData['status_doc_ci'] ?>"><?= $driverData['status_doc_ci'] ?></span>
                            </div>
                            
                            <!-- Licencia -->
                            <div class="doc-item-row" onclick="openDocModal('licencia', 'Licencia de Conducir', '<?= esc($driverData['doc_licencia_path']) ?>', '<?= esc($driverData['doc_licencia_back_path']) ?>', '<?= $driverData['status_doc_licencia'] ?>')">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="doc-mini-preview" style="width: 40px; height: 30px; border-radius: 6px; overflow: hidden; background: #e2e8f0; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center;">
                                        <?php if ($driverData['doc_licencia_path']): ?>
                                            <img src="<?= esc(delivery_app_url($driverData['doc_licencia_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <span style="font-size:12px;">🪪</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-meta">
                                        <b>Licencia de Conducir</b>
                                        <span>Registro Profesional</span>
                                    </div>
                                </div>
                                <span class="status-pill <?= $driverData['status_doc_licencia'] ?>"><?= $driverData['status_doc_licencia'] ?></span>
                            </div>
                            
                            <!-- Habilitación -->
                            <div class="doc-item-row" onclick="openDocModal('habilitacion', 'Habilitación Vehicular', '<?= esc($driverData['doc_habilitacion_path']) ?>', '<?= esc($driverData['doc_habilitacion_back_path']) ?>', '<?= $driverData['status_doc_habilitacion'] ?>')">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="doc-mini-preview" style="width: 40px; height: 30px; border-radius: 6px; overflow: hidden; background: #e2e8f0; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center;">
                                        <?php if ($driverData['doc_habilitacion_path']): ?>
                                            <img src="<?= esc(delivery_app_url($driverData['doc_habilitacion_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <span style="font-size:12px;">🚗</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-meta">
                                        <b>Habilitación Municipal</b>
                                        <span>Patente de Tránsito</span>
                                    </div>
                                </div>
                                <span class="status-pill <?= $driverData['status_doc_habilitacion'] ?>"><?= $driverData['status_doc_habilitacion'] ?></span>
                            </div>
                            
                            <!-- Cédula Verde -->
                            <div class="doc-item-row" onclick="openDocModal('cedula_verde', 'Cédula Verde', '<?= esc($driverData['doc_cedula_verde_path']) ?>', '<?= esc($driverData['doc_cedula_verde_back_path']) ?>', '<?= $driverData['status_doc_cedula_verde'] ?>')">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="doc-mini-preview" style="width: 40px; height: 30px; border-radius: 6px; overflow: hidden; background: #e2e8f0; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center;">
                                        <?php if ($driverData['doc_cedula_verde_path']): ?>
                                            <img src="<?= esc(delivery_app_url($driverData['doc_cedula_verde_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <span style="font-size:12px;">💚</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-meta">
                                        <b>Cédula Verde</b>
                                        <span>Propiedad del Vehículo</span>
                                    </div>
                                </div>
                                <span class="status-pill <?= $driverData['status_doc_cedula_verde'] ?>"><?= $driverData['status_doc_cedula_verde'] ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-Card: Suscripción -->
                    <div id="subscription-card-box" style="display:flex; flex-direction:column; gap:14px; border-top:1px solid #f1f5f9; padding-top:20px;">
                        <h3>💳 Control de Suscripción</h3>
                        
                        <div style="font-size:12px; color:var(--text-muted); font-weight:700;">
                            Estado: 
                            <span class="status-pill <?= $driverData['subscription_status'] ?>" style="display:inline-block; font-size:10px;"><?= $driverData['subscription_status'] ?></span>
                            <?php if ($driverData['subscription_expires_at']): ?>
                                <span style="display:block; margin-top:4px; font-weight:500;">📅 Vence: <?= date('d/m/Y H:i', strtotime($driverData['subscription_expires_at'])) ?> (UTC-3)</span>
                            <?php else: ?>
                                <span style="display:block; margin-top:4px; font-weight:500;">📅 Vence: Sin registro</span>
                            <?php endif; ?>
                        </div>

                        <!-- Comprobante subido -->
                        <div class="sub-proof-preview" onclick="openReceiptLightbox()">
                            <?php if (!empty($latestPayment) && !empty($latestPayment['payment_proof_path'])): ?>
                                <img src="<?= esc(delivery_app_url($latestPayment['payment_proof_path'])) ?>" alt="Comprobante">
                                <div style="position:absolute; bottom:6px; right:6px; background:rgba(0,0,0,0.6); color:#fff; font-size:9px; padding:3px 6px; border-radius:4px; font-weight:700;">AMPLIAR</div>
                                <button class="btn-delete-proof" style="position:absolute; top:6px; right:6px; background:#e11d48; color:#fff; border:none; border-radius:4px; padding:2px 6px; font-size:10px;" onclick="deleteReceipt(<?= $latestPayment['id'] ?>); event.stopPropagation();">Eliminar</button>
                            <?php else: ?>
                                <div class="sub-proof-placeholder" style="background:#f1f5f9; display:flex; align-items:center; justify-content:center; height:200px; border-radius:8px;">
                                    <span style="color:#64748b; font-size:14px;">Pendiente de carga</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($latestPayment && $latestPayment['status'] === 'pending'): ?>
                            <div class="sub-controls">
                                <button class="btn-sub-verify btn-approve" onclick="verifySubscription('approved', <?= $latestPayment['id'] ?>)">✅ Aprobar y Habilitar</button>
                                <button class="btn-sub-verify btn-reject" onclick="promptRejectSubscription(<?= $latestPayment['id'] ?>)">❌ Rechazar Pago</button>
                            </div>
                        <?php endif; ?>

                        <!-- Caja de Prórroga -->
                        <div class="prorroga-box">
                            <span class="prorroga-title">Prórroga / Grace Period</span>
                            <div class="prorroga-row">
                                <select class="prorroga-select" id="grace-hours">
                                    <option value="8">8 Horas de Gracia</option>
                                    <option value="9">9 Horas de Gracia</option>
                                    <option value="10">10 Horas de Gracia</option>
                                    <option value="11">11 Horas de Gracia</option>
                                    <option value="12" selected>12 Horas de Gracia</option>
                                    <option value="24">24 Horas de Gracia</option>
                                    <option value="48">48 Horas de Gracia</option>
                                </select>
                                <button class="btn-prorroga" onclick="grantGracePeriod()">Sumar</button>
                            </div>
                        </div>

                        <!-- Historial de comprobantes anteriores -->
                        <div>
                            <span class="prorroga-title" style="margin-bottom:6px; display:block;">Historial de Pagos</span>
                            <div class="sub-history-list">
                                <?php if (empty($paymentHistory)): ?>
                                    <div style="font-size:11px; color:var(--text-muted); text-align:center;">Sin pagos anteriores registrados.</div>
                                <?php else: ?>
                                    <?php foreach ($paymentHistory as $ph): ?>
                                        <div class="sub-history-item">
                                            <span>📅 <?= date('d/m/y', strtotime($ph['uploaded_at'])) ?></span>
                                            <span class="status-pill <?= $ph['status'] ?>" style="font-size:8px; padding:2px 6px;"><?= $ph['status'] ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha: Mapa Mapbox Overview -->
                <div class="bento-card">
                    <div class="map-card-header">
                        <h3>🗺️ Entregas en Mapa</h3>
                        <div class="map-visual-toggle">
                            <button class="visual-btn active" id="btn-toggle-route" onclick="setMapVisual('route')">Rutas</button>
                            <button class="visual-btn" id="btn-toggle-heat" onclick="setMapVisual('heat')">Calor</button>
                        </div>
                    </div>

                    <div class="map-container-box">
                        <div id="driver-detail-map"></div>
                        <div class="map-floating-controls">
                            <button class="map-control-btn" onclick="zoomMap(1)">+</button>
                            <button class="map-control-btn" onclick="zoomMap(-1)">-</button>
                            <button class="map-control-btn" onclick="toggleMapFullscreen()" title="Pantalla Completa">⛶</button>
                        </div>
                    </div>

                    <div class="map-bottom-meta">
                        <span>Distancia total estimada:</span>
                        <b id="map-total-distance">0.0 km</b>
                    </div>
                    
                    <div style="font-size:12px; font-weight:700; color:var(--text-muted); border-top:1px solid #f1f5f9; padding-top:10px; display:flex; justify-content:space-between;">
                        <span>Conexión Actual:</span>
                        <span id="driver-live-status-badge" class="status-pill none">Cargando...</span>
                    </div>
                </div>

            </div>

            <!-- Bottom Section: Historial de Entregas -->
            <div class="history-card">
                <h3>📦 Historial de Entregas</h3>
                <div style="overflow-x:auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Pedido ID</th>
                                <th>Local / Origen</th>
                                <th>Dirección de Entrega</th>
                                <th>Ganancia</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody id="driver-history-table-body">
                            <!-- Populated dynamically via Javascript -->
                        </tbody>
                    </table>
                </div>
                <button class="btn-load-more" id="btn-history-load-more" onclick="loadMoreHistory()">Cargar más entregas</button>
            </div>

        </div>
    </div>

    <!-- Document Approval Dialog/Modal -->
    <div class="doc-modal-overlay" id="driver-doc-modal">
        <div class="doc-modal-card">
            <div class="doc-modal-title">
                <span id="doc-modal-title-text">Documentos del Conductor</span>
                <button class="doc-modal-close" onclick="closeDocModal()">✕</button>
            </div>
            <div class="doc-modal-images">
                <div class="input-group">
                    <label>Frente</label>
                    <div class="doc-img-container" onclick="openLightboxFromDoc('front')">
                        <img id="doc-modal-img-front" src="" alt="Frente del documento">
                    </div>
                </div>
                <div class="input-group">
                    <label>Dorso / Reverso</label>
                    <div class="doc-img-container" onclick="openLightboxFromDoc('back')">
                        <img id="doc-modal-img-back" src="" alt="Dorso del documento">
                    </div>
                </div>
            </div>
            
            <div id="doc-verification-status-panel" style="display:flex; align-items:center; justify-content:space-between; background:#f8fafc; padding:12px; border-radius:12px; font-size:13px; font-weight:700;">
                <span>Estado Actual: <span id="doc-modal-status-badge" class="status-pill none">None</span></span>
            </div>

            <div class="doc-modal-actions">
                <button class="btn-sub-verify btn-approve" id="btn-approve-doc" style="padding:10px 20px; font-size:12px;">Aprobar Documento</button>
                <button class="btn-sub-verify btn-reject" id="btn-reject-doc" style="padding:10px 20px; font-size:12px;">Rechazar Documento</button>
            </div>
        </div>
    </div>

    <!-- Lightbox Modal -->
    <div id="lightbox-modal" class="lightbox-overlay" onclick="closeLightbox()">
        <img id="lightbox-img" class="lightbox-img" src="" alt="Ampliado">
    </div>

    <script>
        mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
        
        const driverId = <?= $driverId ?>;
        let map = null;
        let mapPoints = [];
        let mapVisualMode = 'route'; // 'route' or 'heat'
        let routeSourceAdded = false;
        let heatmapSourceAdded = false;
        
        // ApexCharts Instances
        let chartDelivered = null;
        let chartEarnings = null;
        let chartCancelled = null;
        
        // Paginación Historial
        let historyOffset = 0;
        const historyLimit = 15;

        // Documentos modal variables
        let currentDocType = '';

        window.onload = () => {
            // Inicializar Mapa
            initMap();
            
            // Inicializar Gráficos de KPIs vacíos y hacer la primera carga por rango (Esta Semana)
            initCharts();
            updateKPIsRange('week');

            // Cargar historial inicial
            loadMoreHistory(true);
        };

        // --- MAPA LOGICA ---
        function initMap() {
            map = new mapboxgl.Map({
                container: 'driver-detail-map',
                style: 'mapbox://styles/mapbox/light-v11',
                center: [-57.5759, -25.2637], // Asunción
                zoom: 11,
                attributionControl: false
            });
        }

        function zoomMap(delta) {
            if (map) {
                map.setZoom(map.getZoom() + delta);
            }
        }

        function toggleMapFullscreen() {
            const mapContainer = document.querySelector('.map-container-box');
            if (!document.fullscreenElement) {
                mapContainer.requestFullscreen().catch(err => console.log(err));
            } else {
                document.exitFullscreen();
            }
        }

        function updateMapPoints(points) {
            if (!map) return;
            
            // Eliminar marcadores previos de la interfaz del mapa
            const markers = document.querySelectorAll('.mapboxgl-marker');
            markers.forEach(m => m.remove());

            // Limpiar fuentes de rutas y calor si ya existían
            if (map.isStyleLoaded()) {
                if (map.getLayer('route-line')) map.removeLayer('route-line');
                if (map.getSource('route-coords')) map.removeSource('route-coords');
                if (map.getLayer('heatmap-layer')) map.removeLayer('heatmap-layer');
                if (map.getSource('heatmap-coords')) map.removeSource('heatmap-coords');
            }

            routeSourceAdded = false;
            heatmapSourceAdded = false;

            if (points.length === 0) {
                map.setCenter([-57.5759, -25.2637]);
                map.setZoom(11);
                return;
            }

            const features = [];
            const routeCoordinates = [];
            const bounds = new mapboxgl.LngLatBounds();

            points.forEach(p => {
                const destLng = parseFloat(p.delivery_longitude);
                const destLat = parseFloat(p.delivery_latitude);
                const localLng = parseFloat(p.local_lng);
                const localLat = parseFloat(p.local_lat);

                if (!isNaN(destLng) && !isNaN(destLat)) {
                    bounds.extend([destLng, destLat]);
                    features.push({
                        type: 'Feature',
                        geometry: { type: 'Point', coordinates: [destLng, destLat] },
                        properties: { weight: 1 }
                    });
                    
                    // Colocar marcador en el destino
                    const el = document.createElement('div');
                    el.style.width = '12px';
                    el.style.height = '12px';
                    el.style.borderRadius = '50%';
                    el.style.background = p.status === 'entregado' ? '#10b981' : '#f59e0b';
                    el.style.border = '2px solid #fff';
                    el.style.boxShadow = '0 2px 5px rgba(0,0,0,0.2)';

                    const popup = new mapboxgl.Popup({ offset: 10 }).setHTML(`
                        <div style="font-size:11px; font-family:'Plus Jakarta Sans'; font-weight:700;">
                            <b>Local:</b> ${p.local_name}<br>
                            <b>Destino:</b> ${p.delivery_address}<br>
                            <b>Estado:</b> ${p.status}<br>
                            <a href="https://www.google.com/maps/search/?api=1&query=${destLat},${destLng}" target="_blank" style="color:var(--primary); text-decoration:none; display:block; margin-top:6px;">Ver en Google Maps &rarr;</a>
                        </div>
                    `);

                    new mapboxgl.Marker(el)
                        .setLngLat([destLng, destLat])
                        .setPopup(popup)
                        .addTo(map);
                }

                if (!isNaN(localLng) && !isNaN(localLat) && !isNaN(destLng) && !isNaN(destLat)) {
                    // Agregar coordenadas para la línea de ruta (Origen -> Destino)
                    routeCoordinates.push([localLng, localLat], [destLng, destLat]);
                }
            });

            // Ajustar cámara para englobar todos los puntos
            map.fitBounds(bounds, { padding: 30, maxZoom: 14 });

            // Dibujar capa visual elegida
            drawMapVisuals(routeCoordinates, features);
        }

        function drawMapVisuals(routeCoords, heatFeatures) {
            if (!map.isStyleLoaded()) return;

            if (mapVisualMode === 'route') {
                if (routeCoords.length > 0) {
                    map.addSource('route-coords', {
                        type: 'geojson',
                        data: {
                            type: 'Feature',
                            geometry: { type: 'MultiLineString', coordinates: [routeCoords] }
                        }
                    });
                    map.addLayer({
                        id: 'route-line',
                        type: 'line',
                        source: 'route-coords',
                        paint: {
                            'line-color': '#2563eb',
                            'line-width': 3,
                            'line-opacity': 0.6
                        }
                    });
                    routeSourceAdded = true;
                }
            } else if (mapVisualMode === 'heat') {
                if (heatFeatures.length > 0) {
                    map.addSource('heatmap-coords', {
                        type: 'geojson',
                        data: { type: 'FeatureCollection', features: heatFeatures }
                    });
                    map.addLayer({
                        id: 'heatmap-layer',
                        type: 'heatmap',
                        source: 'heatmap-coords',
                        paint: {
                            'heatmap-weight': {
                                property: 'weight',
                                type: 'exponential',
                                stops: [[1, 0], [62, 1]]
                            },
                            'heatmap-intensity': 1,
                            'heatmap-color': [
                                'interpolate',
                                ['linear'],
                                ['heatmap-density'],
                                0, 'rgba(0, 0, 255, 0)',
                                0.2, 'rgba(37, 99, 235, 0.2)',
                                0.4, 'rgba(16, 185, 129, 0.5)',
                                0.6, 'rgba(245, 158, 11, 0.7)',
                                0.8, 'rgba(239, 68, 68, 0.8)'
                            ],
                            'heatmap-radius': 25,
                            'heatmap-opacity': 0.7
                        }
                    });
                    heatmapSourceAdded = true;
                }
            }
        }

        function setMapVisual(mode) {
            mapVisualMode = mode;
            document.getElementById('btn-toggle-route').classList.toggle('active', mode === 'route');
            document.getElementById('btn-toggle-heat').classList.toggle('active', mode === 'heat');
            
            // Recargar datos y forzar repintado del mapa
            const currentRange = document.querySelector('.kpi-select').value;
            updateKPIsRange(currentRange);
        }


        // --- GRAFICOS & KPIS LOGICA ---
        function initCharts() {
            // Configuración general simplificada para los mini-gráficos
            const baseOptions = {
                chart: {
                    type: 'area',
                    sparkline: { enabled: true }
                },
                stroke: { curve: 'smooth', width: 2.5 },
                fill: { opacity: 0.15 },
                tooltip: { fixed: { enabled: false }, x: { show: false } }
            };

            // Gráfico Entregados (Barras pequeñas)
            chartDelivered = new ApexCharts(document.querySelector("#chart-delivered"), {
                ...baseOptions,
                chart: { type: 'bar', sparkline: { enabled: true } },
                colors: ['#2563eb'],
                series: [{ name: 'Entregados', data: [] }]
            });
            chartDelivered.render();

            // Gráfico Ganancias
            chartEarnings = new ApexCharts(document.querySelector("#chart-earnings"), {
                ...baseOptions,
                colors: ['#10b981'],
                series: [{ name: 'Ganancias', data: [] }]
            });
            chartEarnings.render();

            // Gráfico Cancelados
            chartCancelled = new ApexCharts(document.querySelector("#chart-cancelled"), {
                ...baseOptions,
                colors: ['#ef4444'],
                series: [{ name: 'Cancelados', data: [] }]
            });
            chartCancelled.render();
        }

        function updateKPIsRange(range) {
            // Sincronizar todos los selects de la fila
            document.querySelectorAll('.kpi-select').forEach(sel => sel.value = range);

            const formData = new FormData();
            formData.append('action', 'get_driver_kpis');
            formData.append('driver_id', driverId);
            formData.append('range', range);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                const contentType = res.headers.get('content-type') || '';
                if (!res.ok || !contentType.includes('application/json')) {
                    return res.text().then(txt => {
                        console.error('Unexpected response:', txt);
                        throw new Error('Server returned non-JSON response');
                    });
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    // Defensive defaults
                    const earnings = data.earnings ?? 0;
                    const labels = data.labels ?? [];
                    const seriesDelivered = data.series_delivered ?? [];
                    const seriesEarnings = data.series_earnings ?? [];
                    const seriesCancelled = data.series_cancelled ?? [];
                    const points = Array.isArray(data.points) ? data.points : [];

                    // Update numeric header values
                    document.getElementById('kpi-delivered-val').innerText = data.completados;
                    document.getElementById('kpi-earnings-val').innerText = Number(earnings).toLocaleString('de-DE') + ' Gs.';
                    document.getElementById('kpi-hours-val').innerHTML = '⏱️ ' + data.horas_conectadas + ' hrs Conectado';
                    document.getElementById('kpi-cancelled-val').innerText = data.cancelados;
                    document.getElementById('map-total-distance').innerText = data.distancia_km + ' km';

                    // Update ApexCharts with safe data
                    chartDelivered.updateSeries([{ data: seriesDelivered }]);
                    chartDelivered.updateOptions({ xaxis: { categories: labels } });

                    chartEarnings.updateSeries([{ data: seriesEarnings }]);
                    chartEarnings.updateOptions({ xaxis: { categories: labels } });

                    chartCancelled.updateSeries([{ data: seriesCancelled }]);
                    chartCancelled.updateOptions({ xaxis: { categories: labels } });

                    // Update map points
                    updateMapPoints(points);
                }
            })
            .catch(err => console.error("Error al actualizar KPIs de repartidor:", err));
        }

        // --- HISTORIAL PAGINADO ---
        function loadMoreHistory(reset = false) {
            if (reset) {
                historyOffset = 0;
                document.getElementById('driver-history-table-body').innerHTML = '';
            }

            const btn = document.getElementById('btn-history-load-more');
            btn.disabled = true;
            btn.innerText = 'Cargando...';

            const formData = new FormData();
            formData.append('action', 'get_driver_history');
            formData.append('driver_id', driverId);
            formData.append('limit', historyLimit);
            formData.append('offset', historyOffset);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.history) && data.history.length > 0) {
                    const tbody = document.getElementById('driver-history-table-body');
                    
                    data.history.forEach(order => {
                        const dateFormatted = new Date(order.created_at).toLocaleString('es-PY', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
                        const tr = document.createElement('tr');
                        
                        let pillClass = 'status-pill none';
                        if (order.status === 'entregado') pillClass = 'status-pill approved';
                        else if (order.status === 'cancelado') pillClass = 'status-pill rejected';
                        else pillClass = 'status-pill pending';

                        tr.innerHTML = `
                            <td>#${order.id}</td>
                            <td>${escapeHtml(order.local_name)}</td>
                            <td>${escapeHtml(order.delivery_address)}</td>
                            <td>${order.delivery_cost.toLocaleString('de-DE')} Gs.</td>
                            <td><span class="${pillClass}">${order.status}</span></td>
                            <td>${dateFormatted}</td>
                        `;
                        tbody.appendChild(tr);
                    });

                    historyOffset += data.history.length;
                    btn.disabled = false;
                    btn.innerText = 'Cargar más entregas';

                    if (data.history.length < historyLimit) {
                        btn.style.display = 'none';
                    } else {
                        btn.style.display = 'block';
                    }
                } else {
                    btn.disabled = true;
                    btn.innerText = 'Sin más registros';
                    btn.style.display = 'none';
                }
            })
            .catch(err => {
                console.error(err);
                btn.disabled = false;
                btn.innerText = 'Error al cargar';
            });
        }


        // --- VERIFICACION SUSCRIPCION ---
        function verifySubscription(status, paymentId) {
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
                alert(res.message);
                window.location.reload();
            })
            .catch(err => {
                console.error(err);
                alert('Error al procesar la verificación.');
            });
        }

        function promptRejectSubscription(paymentId) {
            verifySubscription('rejected', paymentId);
        }

        function grantGracePeriod() {
            const selectHours = document.getElementById('grace-hours').value;
            
            const formData = new FormData();
            formData.append('action', 'extend_driver_grace_period');
            formData.append('driver_id', driverId);
            formData.append('hours', selectHours);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    alert(`${res.message} Vencimiento postergado hasta: ${res.expires_at}`);
                    window.location.reload();
                } else {
                    alert(res.error || 'Error al extender la prórroga.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error al otorgar prórroga.');
            });
        }

        // --- VERIFICACION DOCUMENTACION REPARTIDOR ---
        function openDocModal(type, title, pathFront, pathBack, currentStatus) {
            currentDocType = type;
            document.getElementById('doc-modal-title-text').innerText = 'Documentación: ' + title;
            
            const imgFront = document.getElementById('doc-modal-img-front');
            const imgBack = document.getElementById('doc-modal-img-back');
            const baseUrl = '<?= delivery_app_url() ?>/';
            
            imgFront.src = pathFront ? baseUrl + pathFront : 'https://placehold.co/300x160/f1f5f9/cbd5e1?text=No+Cargado';
            imgBack.src = pathBack ? baseUrl + pathBack : 'https://placehold.co/300x160/f1f5f9/cbd5e1?text=No+Cargado';

            const statusBadge = document.getElementById('doc-modal-status-badge');
            statusBadge.innerText = currentStatus;
            statusBadge.className = 'status-pill ' + currentStatus;

            // Configurar manejadores de clicks para los botones
            document.getElementById('btn-approve-doc').onclick = () => updateDocStatus('approve_document');
            document.getElementById('btn-reject-doc').onclick = () => updateDocStatus('reject_document');

            document.getElementById('driver-doc-modal').style.display = 'flex';
        }

        function closeDocModal() {
            document.getElementById('driver-doc-modal').style.display = 'none';
        }

        function updateDocStatus(action) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('driver_id', driverId);
            formData.append('doc_type', currentDocType);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert(data.error || 'Error al actualizar documento.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error al comunicarse con el servidor.');
            });
        }


        // --- LIGHTBOX IMAGENES ---
        function openReceiptLightbox() {
            const img = document.querySelector('.sub-proof-preview img');
            if (img && img.src) {
                openLightbox(img.src);
            }
        }

        // --- DELETE RECEIPT ---
        function deleteReceipt(paymentId) {
            if (!confirm('¿Estás seguro de eliminar el comprobante?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_payment_proof');
            formData.append('payment_id', paymentId);
            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert(data.error || 'Error al eliminar el comprobante.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error al comunicarse con el servidor.');
            });
        }

        function openLightboxFromDoc(side) {
            const imgEl = document.getElementById(side === 'front' ? 'doc-modal-img-front' : 'doc-modal-img-back');
            if (imgEl && imgEl.src && !imgEl.src.includes('placehold.co')) {
                openLightbox(imgEl.src);
            }
        }

        function openLightbox(src) {
            const modal = document.getElementById('lightbox-modal');
            const img = document.getElementById('lightbox-img');
            img.src = src;
            modal.style.display = 'flex';
        }

        function closeLightbox() {
            document.getElementById('lightbox-modal').style.display = 'none';
        }

        // --- AUX HELPER ---
        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function scrollToSubscriptionCard() {
            document.getElementById('subscription-card-box').scrollIntoView({ behavior: 'smooth' });
        }

        // Chequear estado en vivo del conductor
        // Chequear estado en vivo del conductor en tiempo real
        function updateDriverLiveStatus() {
            const formData = new FormData();
            formData.append('action', 'get_driver_live_status');
            formData.append('driver_id', driverId);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    const badge = document.getElementById('driver-live-status-badge');
                    if (res.is_online === 1) {
                        if (res.active_delivery_count > 0) {
                            badge.innerHTML = 'En curso 🟠';
                            badge.className = 'status-pill pending';
                        } else {
                            badge.innerHTML = 'Conectado 🟢';
                            badge.className = 'status-pill approved';
                        }
                    } else {
                        badge.innerHTML = 'Desconectado 🔴';
                        badge.className = 'status-pill rejected';
                    }
                }
            })
            .catch(err => console.error("Error al consultar estado en vivo:", err));
        }
        
        // Ejecutar inmediatamente y programar cada 3 segundos
        updateDriverLiveStatus();
        setInterval(updateDriverLiveStatus, 3000);
    </script>
    
    <!-- SweetAlert2 local copy -->
    <script src="<?= esc(delivery_app_url('assets/js/sweetalert2.min.js')) ?>"></script>
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
        
        // También desbloquear si hacen click en cualquier parte del documento
        document.addEventListener('click', () => {
            if (!audioUnlocked) unlockAudio();
        }, { once: true });

        // Append banner once loaded
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

                    // Si el pago pertenece a este conductor actual, agregar dinámicamente el badge de notificación
                    const payments = data.payments || [];
                    const currentDriverId = parseInt(driverId);
                    const isForThisDriver = payments.some(p => parseInt(p.driver_user_id) === currentDriverId);
                    if (isForThisDriver) {
                        const bellBtn = document.getElementById('bell-notif-btn');
                        if (bellBtn && !bellBtn.querySelector('.notif-badge')) {
                            const badge = document.createElement('span');
                            badge.className = 'notif-badge';
                            badge.innerText = '1';
                            bellBtn.appendChild(badge);
                        }
                    }

                    // Crear y mostrar Toast flotante premium en el DOM
                    const firstPayment = payments[0] || {};
                    const newDriverName = firstPayment.driver_name || 'Un repartidor';
                    const newDriverId = firstPayment.driver_user_id || '';

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
                                ${newDriverName} ha subido su comprobante de suscripción.
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
                            if (isForThisDriver) {
                                location.reload();
                            }
                        }, 300);
                    };

                    // Redirigir o recargar al hacer click en el toast
                    toast.addEventListener('click', (e) => {
                        if (e.target.tagName === 'BUTTON') return;
                        if (newDriverId && parseInt(newDriverId) !== currentDriverId) {
                            window.location.href = `admin_driver_detail.php?id=${newDriverId}`;
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
    </script>
</body>
</html>
