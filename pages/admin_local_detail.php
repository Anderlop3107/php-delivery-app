<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] !== 'admin') {
    header('Location: ' . delivery_app_url('dashboard.php'));
    exit;
}

$localId = (int)($_GET['id'] ?? 0);
if ($localId <= 0) {
    die("ID de local inválido.");
}

// Obtener datos del local
$localData = app_one("SELECT * FROM users WHERE id = ? AND role = 'local'", "i", [$localId]);
if (!$localData) {
    die("Local no encontrado.");
}

// Obtener último comprobante subido
$latestPayment = app_one("
    SELECT * FROM driver_payments 
    WHERE driver_user_id = ? 
    ORDER BY id DESC LIMIT 1
", "i", [$localId]);

// Obtener historial de últimos 12 pagos
$paymentHistory = app_all("
    SELECT * FROM driver_payments 
    WHERE driver_user_id = ? 
    ORDER BY id DESC LIMIT 12
", "i", [$localId]);

// Verificar si hay alguna notificación pendiente de pago para este local
$hasPendingNotification = app_one("SELECT COUNT(*) as count FROM driver_payments WHERE driver_user_id = ? AND status = 'pending'", "i", [$localId])['count'] > 0 ? 1 : 0;

// Obtener cantidad de pedidos activos del local
$activeCountRow = app_one("
    SELECT COUNT(*) as count 
    FROM deliveries 
    WHERE local_user_id = ? 
      AND status NOT IN ('entregado', 'cancelado')
", "i", [$localId]);
$activeCount = (int)($activeCountRow['count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Detalle de Local: <?= esc($localData['business_name'] ?: $localData['name']) ?></title>
    
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

        /* Sidebar - Identical design */
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

        /* Welcome Bar */
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
        }
        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .kpi-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kpi-select {
            background: #f1f5f9;
            border: none;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-main);
            outline: none;
            cursor: pointer;
        }
        .kpi-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .kpi-value-box {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .kpi-value-box b {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -1px;
        }
        .kpi-value-box span {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .kpi-chart-box {
            flex-grow: 1;
            max-width: 120px;
            height: 65px;
        }

        /* Bento Grid Layout */
        .central-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr 1fr;
            gap: 20px;
        }
        .bento-card {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 24px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .bento-card h3 {
            font-size: 16px;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
            letter-spacing: -0.3px;
        }

        /* Access details */
        .avatar-uploader-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 20px;
        }
        .driver-profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-soft);
            margin: 0 auto 12px;
            box-shadow: var(--clay-shadow);
        }
        .account-inputs {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .input-group label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .input-group input {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            outline: none;
        }

        /* Document list items */
        .docs-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .doc-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .doc-item-row:hover {
            border-color: var(--primary);
            background: var(--primary-soft);
        }
        .doc-meta b {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-main);
            display: block;
        }
        .doc-meta span {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .status-pill {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 8px;
            display: inline-block;
        }
        .status-pill.approved, .status-pill.active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-pill.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-pill.rejected, .status-pill.expired {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-pill.none {
            background: #f1f5f9;
            color: #475569;
        }

        /* Subscription controls */
        .sub-proof-preview {
            width: 100%;
            height: 140px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px dashed #cbd5e1;
            position: relative;
            cursor: pointer;
        }
        .sub-proof-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .sub-controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .btn-sub-verify {
            border: none;
            padding: 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .btn-approve {
            background: #10b981;
            color: #fff;
        }
        .btn-approve:hover { background: #059669; }
        .btn-reject {
            background: #ef4444;
            color: #fff;
        }
        .btn-reject:hover { background: #dc2626; }

        /* Prorroga */
        .prorroga-box {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 12px;
        }
        .prorroga-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 8px;
            display: block;
        }
        .prorroga-row {
            display: flex;
            gap: 8px;
        }
        .prorroga-select {
            flex-grow: 1;
            background: #fff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-main);
            outline: none;
        }
        .btn-prorroga {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        /* History payment list */
        .sub-history-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .sub-history-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 10px;
            background: #f8fafc;
            border-radius: 8px;
        }

        /* Map box */
        .map-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .map-visual-toggle {
            display: flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 10px;
        }
        .visual-btn {
            background: none;
            border: none;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .visual-btn.active {
            background: #fff;
            color: var(--text-main);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .map-container-box {
            position: relative;
            width: 100%;
            height: 240px;
            border-radius: var(--radius-medium);
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: var(--clay-inner);
        }
        #driver-detail-map {
            width: 100%;
            height: 100%;
        }
        .map-floating-controls {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            z-index: 1;
        }
        .map-control-btn {
            width: 28px;
            height: 28px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .map-bottom-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
        }

        /* Bottom History Table */
        .history-card {
            background: var(--card-bg);
            border-radius: var(--radius-large);
            padding: 24px;
            box-shadow: var(--clay-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            margin-bottom: 24px;
        }
        .history-card h3 {
            font-size: 16px;
            font-weight: 800;
            margin: 0 0 16px 0;
            color: var(--text-main);
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .history-table th {
            text-align: left;
            padding: 12px 14px;
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 700;
            border-bottom: 1.5px solid #e2e8f0;
        }
        .history-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-main);
            font-weight: 600;
        }
        .history-table tr:last-child td {
            border-bottom: none;
        }

        /* Pagination buttons */
        .pagination-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
        }
        .pagination-btn {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            color: var(--text-main);
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .pagination-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        .pagination-btn:hover:not(.active) {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        /* Modales */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 999;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-card {
            background: #fff;
            border-radius: var(--radius-large);
            padding: 24px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.8);
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h4 {
            font-size: 16px;
            font-weight: 800;
            margin: 0;
        }
        .btn-close-modal {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-muted);
        }
        .doc-preview-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .doc-preview-box {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .doc-preview-box span {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .doc-preview-box img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            cursor: zoom-in;
        }
        
        /* Lightbox */
        .lightbox-overlay {
            position: fixed;
            top:0; left:0; width:100%; height:100%;
            background: rgba(15,23,42,0.95);
            display: none; align-items: center; justify-content: center;
            cursor: pointer; z-index: 10000;
        }
        .lightbox-img {
            max-width: 90%; max-height: 90%; border-radius: 12px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>

    <div class="detail-layout">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-logo">🏢</div>
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
                    <h1>Local: <?= esc($localData['business_name'] ?: $localData['name']) ?></h1>
                    <p>Detalle de actividad, documentación de propietario y estado de suscripción.</p>
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
                    <a href="https://wa.me/<?= esc($localData['whatsapp'] ?: $localData['phone']) ?>" target="_blank" class="action-circle-btn" title="Enviar WhatsApp / Soporte">
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
                            <span>📦 Pedidos Totales</span>
                        </div>
                        <div class="kpi-chart-box">
                            <div id="chart-delivered"></div>
                        </div>
                    </div>
                </div>

                <!-- KPI 2: Grafico para saber en qué horario hace más pedidos -->
                <div class="kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-title">Pedidos por Horario</span>
                        <select class="kpi-select" onchange="updateKPIsRange(this.value)">
                            <option value="day">Hoy</option>
                            <option value="week" selected>Esta Semana</option>
                            <option value="month">Este Mes</option>
                        </select>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-value-box">
                            <b style="font-size: 15px; font-weight:800; color:var(--primary);" id="kpi-peak-hour">--:--</b>
                            <span>⏱️ Hora Pico de Pedidos</span>
                        </div>
                        <div class="kpi-chart-box" style="max-width: 140px;">
                            <div id="chart-hourly-distribution"></div>
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
                            <span>❌ Pedidos Fallidos</span>
                        </div>
                        <div class="kpi-chart-box">
                            <div id="chart-cancelled"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bento Central Grid -->
            <div class="central-grid">
                
                <!-- Columna Izquierda: Cuenta del Local -->
                <div class="bento-card">
                    <h3>👤 Cuenta de Acceso</h3>
                    <div class="avatar-uploader-section">
                        <?php if ($localData['logo_path']): ?>
                            <img class="driver-profile-avatar" src="<?= esc(delivery_app_url($localData['logo_path'])) ?>?v=<?= time() ?>" alt="Logo">
                        <?php else: ?>
                            <div class="driver-profile-avatar" style="background:#cbd5e1; display:flex; align-items:center; justify-content:center; font-size:32px; margin:0 auto 12px;">🏢</div>
                        <?php endif; ?>
                        <b style="font-size:16px; color:#0f172a;"><?= esc($localData['business_name'] ?: $localData['name']) ?></b>
                        <div style="font-size:12px; color:#64748b; margin-top:2px;">Rol: Comercio / Local</div>
                    </div>
                    
                    <div class="account-inputs">
                        <div class="input-group">
                            <label>Email</label>
                            <input type="text" value="<?= esc($localData['email']) ?>" readonly>
                        </div>
                        <div class="input-group">
                            <label>Teléfono</label>
                            <input type="text" value="<?= esc($localData['phone']) ?>" readonly>
                        </div>
                        <div class="input-group">
                            <label>Whatsapp</label>
                            <input type="text" value="<?= esc($localData['whatsapp'] ?: $localData['phone']) ?>" readonly>
                        </div>
                        <div class="input-group">
                            <label>Contraseña Hashed</label>
                            <input type="text" value="••••••••••••" readonly>
                        </div>
                    </div>
                </div>

                <!-- Columna Central: Documentación & Suscripción -->
                <div class="bento-card" style="gap:24px;">
                    <!-- Sub-Card: Documentación -->
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <h3>📄 Documentación del Dueño</h3>
                        <div class="docs-list">
                            <!-- CI -->
                            <div class="doc-item-row" onclick="openDocModal('ci', 'Cédula de Identidad', '<?= esc($localData['doc_ci_path']) ?>', '<?= esc($localData['doc_ci_back_path']) ?>', '<?= $localData['status_doc_ci'] ?>')">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="doc-mini-preview" style="width: 40px; height: 30px; border-radius: 6px; overflow: hidden; background: #e2e8f0; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center;">
                                        <?php if ($localData['doc_ci_path']): ?>
                                            <img src="<?= esc(delivery_app_url($localData['doc_ci_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <span style="font-size:12px;">📄</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-meta">
                                        <b>Cédula de Identidad</b>
                                        <span>Frente y Dorso</span>
                                    </div>
                                </div>
                                <span class="status-pill <?= $localData['status_doc_ci'] ?>"><?= $localData['status_doc_ci'] ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-Card: Control de Suscripción -->
                    <div id="subscription-card-box" style="display:flex; flex-direction:column; gap:14px; border-top:1px solid #f1f5f9; padding-top:20px;">
                        <h3>💳 Control de Suscripción</h3>
                        
                        <div style="font-size:12px; color:var(--text-muted); font-weight:700;">
                            Estado: 
                            <span class="status-pill <?= $localData['subscription_status'] ?>" style="display:inline-block; font-size:10px;"><?= $localData['subscription_status'] ?></span>
                            <?php if ($localData['subscription_expires_at']): ?>
                                <span style="display:block; margin-top:4px; font-weight:500;">📅 Vence: <?= date('d/m/Y H:i', strtotime($localData['subscription_expires_at'])) ?> (UTC-3)</span>
                            <?php else: ?>
                                <span style="display:block; margin-top:4px; font-weight:500;">📅 Vence: Sin registro (Se vence el 01 de cada mes)</span>
                            <?php endif; ?>
                        </div>

                        <!-- Comprobante subido -->
                        <div class="sub-proof-preview" onclick="openReceiptLightbox()">
                            <?php if (!empty($latestPayment) && !empty($latestPayment['payment_proof_path'])): ?>
                                <img src="<?= esc(delivery_app_url($latestPayment['payment_proof_path'])) ?>" alt="Comprobante">
                                <div style="position:absolute; bottom:6px; right:6px; background:rgba(0,0,0,0.6); color:#fff; font-size:9px; padding:3px 6px; border-radius:4px; font-weight:700;">AMPLIAR</div>
                                <button class="btn-delete-proof" style="position:absolute; top:6px; right:6px; background:#e11d48; color:#fff; border:none; border-radius:4px; padding:2px 6px; font-size:10px;" onclick="deleteReceipt(<?= $latestPayment['id'] ?>); event.stopPropagation();">Eliminar</button>
                            <?php else: ?>
                                <div class="sub-proof-placeholder" style="background:#f1f5f9; display:flex; align-items:center; justify-content:center; height:120px; border-radius:8px;">
                                    <span style="color:#64748b; font-size:13px;">Pendiente de carga</span>
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
                                    <?php foreach ($paymentHistory as $index => $ph): ?>
                                        <div class="sub-history-item <?= $index >= 4 ? 'hidden-payment-item' : '' ?>" style="<?= $index >= 4 ? 'display: none;' : '' ?>">
                                            <span>📅 <?= date('d/m/y', strtotime($ph['uploaded_at'])) ?></span>
                                            <span class="status-pill <?= $ph['status'] ?>" style="font-size:8px; padding:2px 6px;"><?= $ph['status'] ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($paymentHistory) > 4): ?>
                                        <button id="btn-show-all-payments" onclick="showAllPayments()" style="background:none; border:none; color:var(--primary); font-size:11px; font-weight:700; cursor:pointer; padding:6px 0; display:block; text-align:center; width:100%;">Mostrar todos (<?= count($paymentHistory) ?>)</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha: Mapa de Entregas del Local -->
                <div class="bento-card">
                    <div class="map-card-header">
                        <h3>🗺️ Entregas en Mapa</h3>
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
                        <b id="map-total-distance">0 km</b>
                    </div>
                    
                    <div style="font-size:12px; font-weight:700; color:var(--text-muted); border-top:1px solid #f1f5f9; padding-top:10px; display:flex; justify-content:space-between;">
                        <span>Conexión Actual:</span>
                        <span id="driver-live-status-badge" class="status-pill <?= $localData['is_online'] ? 'approved' : 'rejected' ?>">
                            <?= $localData['is_online'] ? 'Conectado 🟢' : 'Desconectado 🔴' ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Bottom Section: Historial de Entregas del Local -->
            <div class="history-card">
                <h3>📦 Historial de Entregas del Local</h3>
                <div style="overflow-x:auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Pedido ID</th>
                                <th>Comercio de Origen</th>
                                <th>Dirección de Entrega</th>
                                <th>Repartidor</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody id="local-history-table-body">
                            <!-- Dinámico vía JS -->
                        </tbody>
                    </table>
                </div>
                <!-- Pagination wrapper -->
                <div class="pagination-container" id="history-pagination">
                    <!-- Dinámico vía JS -->
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Documentación -->
    <div class="modal-overlay" id="driver-doc-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h4 id="doc-modal-title-text">Documentación</h4>
                <button class="btn-close-modal" onclick="closeDocModal()">✕</button>
            </div>
            
            <div class="doc-preview-container">
                <div class="doc-preview-box">
                    <span>Frente</span>
                    <img id="doc-modal-img-front" src="" alt="Frente" onclick="openLightbox(this.src)">
                </div>
                <div class="doc-preview-box">
                    <span>Dorso</span>
                    <img id="doc-modal-img-back" src="" alt="Dorso" onclick="openLightbox(this.src)">
                </div>
            </div>

            <!-- Cargar/Actualizar desde el Admin -->
            <div style="border-top: 1px solid #f1f5f9; padding-top: 16px; display: flex; flex-direction: column; gap: 8px;">
                <span style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Subir / Actualizar Cédula</span>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button class="btn-sub-verify" style="background:#f1f5f9; color:var(--text-main); border:1.5px solid #cbd5e1; font-size:11px; padding:6px 12px; border-radius:8px;" onclick="document.getElementById('admin-upload-front').click()">📷 Frente</button>
                    <button class="btn-sub-verify" style="background:#f1f5f9; color:var(--text-main); border:1.5px solid #cbd5e1; font-size:11px; padding:6px 12px; border-radius:8px;" onclick="document.getElementById('admin-upload-back').click()">📷 Dorso</button>
                    <span id="admin-upload-status-text" style="font-size:11px; color:#10b981; font-weight:700;"></span>
                </div>
                <input type="file" id="admin-upload-front" accept="image/*" style="display:none;" onchange="handleAdminDocSelect('front')">
                <input type="file" id="admin-upload-back" accept="image/*" style="display:none;" onchange="handleAdminDocSelect('back')">
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f1f5f9; padding-top:16px;">
                <div style="font-size:12px; font-weight:700;">
                    Estado: <span id="doc-modal-status-badge" class="status-pill">none</span>
                </div>
                <div style="display:flex; gap:10px;">
                    <button id="btn-reject-doc" class="btn-sub-verify btn-reject" style="width:auto; padding:8px 16px;">❌ Rechazar</button>
                    <button id="btn-approve-doc" class="btn-sub-verify btn-approve" style="width:auto; padding:8px 16px;">✅ Aprobar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="image-lightbox" onclick="closeLightbox()">
        <img class="lightbox-img" id="lightbox-target-img" src="" alt="Ampliado">
    </div>

    <!-- Audio para sonido de notificaciones -->
    <audio id="realtime-notification-sound" src="<?= esc(delivery_app_url('assets/sounds/notification.mp3')) ?>" preload="auto"></audio>

    <script>
        // Variables globales
        const localId = <?= $localId ?>;
        const localName = "<?= esc($localData['business_name'] ?: $localData['name']) ?>";
        let map;
        let chartDelivered, chartHourlyDistribution, chartCancelled;
        let currentDocType = '';
        let paymentAlerted = false;

        const notificationSound = document.getElementById('realtime-notification-sound');

        // Configuración de Mapbox
        mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';

        window.onload = function() {
            initMap();
            initCharts();
            updateKPIsRange('week');
            loadLocalHistory(1);

            // Iniciar sondeo en tiempo real
            pollNewPayments();
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
            
            // Eliminar marcadores previos
            const markers = document.querySelectorAll('.mapboxgl-marker');
            markers.forEach(m => m.remove());

            // Limpiar capa de rutas si existía
            if (map.isStyleLoaded() && map.getLayer('route-line')) {
                map.removeLayer('route-line');
                map.removeSource('route-coords');
            }

            if (points.length === 0) {
                map.setCenter([-57.5759, -25.2637]);
                map.setZoom(11);
                return;
            }

            const bounds = new mapboxgl.LngLatBounds();
            const routeCoordinates = [];
            let localCoordinateAdded = false;

            points.forEach(p => {
                const destLng = parseFloat(p.lng);
                const destLat = parseFloat(p.lat);
                const localLng = parseFloat(p.local_lng);
                const localLat = parseFloat(p.local_lat);

                // Agregar marcador del comercio local (solo una vez)
                if (!localCoordinateAdded && !isNaN(localLng) && !isNaN(localLat)) {
                    bounds.extend([localLng, localLat]);
                    
                    const localMarkerEl = document.createElement('div');
                    localMarkerEl.style.width = '24px';
                    localMarkerEl.style.height = '24px';
                    localMarkerEl.style.borderRadius = '50%';
                    localMarkerEl.style.background = '#2563eb';
                    localMarkerEl.style.border = '3px solid #fff';
                    localMarkerEl.style.boxShadow = '0 2px 10px rgba(37, 99, 235, 0.4)';
                    localMarkerEl.style.display = 'flex';
                    localMarkerEl.style.alignItems = 'center';
                    localMarkerEl.style.justifyContent = 'center';
                    localMarkerEl.innerHTML = '<span style="font-size:11px; color:#fff;">🏢</span>';

                    new mapboxgl.Marker(localMarkerEl)
                        .setLngLat([localLng, localLat])
                        .setPopup(new mapboxgl.Popup({ offset: 10 }).setHTML(`<b>Local:</b> ${localName}`))
                        .addTo(map);

                    localCoordinateAdded = true;
                }

                // Agregar marcador del destino
                if (!isNaN(destLng) && !isNaN(destLat)) {
                    bounds.extend([destLng, destLat]);
                    
                    const destEl = document.createElement('div');
                    destEl.style.width = '12px';
                    destEl.style.height = '12px';
                    destEl.style.borderRadius = '50%';
                    destEl.style.background = p.status === 'entregado' ? '#10b981' : '#f59e0b';
                    destEl.style.border = '2px solid #fff';
                    destEl.style.boxShadow = '0 2px 5px rgba(0,0,0,0.2)';

                    const popup = new mapboxgl.Popup({ offset: 10 }).setHTML(`
                        <div style="font-size:11px; font-family:'Plus Jakarta Sans'; font-weight:700;">
                            <b>Pedido ID:</b> #${p.id}<br>
                            <b>Destino:</b> ${p.address}<br>
                            <b>Estado:</b> ${p.status}<br>
                            <a href="https://www.google.com/maps/search/?api=1&query=${destLat},${destLng}" target="_blank" style="color:var(--primary); text-decoration:none; display:block; margin-top:6px;">Ver en Google Maps &rarr;</a>
                        </div>
                    `);

                    new mapboxgl.Marker(destEl)
                        .setLngLat([destLng, destLat])
                        .setPopup(popup)
                        .addTo(map);

                    // Agregar línea desde el local a esta entrega
                    if (!isNaN(localLng) && !isNaN(localLat)) {
                        routeCoordinates.push([[localLng, localLat], [destLng, destLat]]);
                    }
                }
            });

            // Ajustar cámara
            map.fitBounds(bounds, { padding: 40, maxZoom: 14 });

            // Dibujar líneas radiales (hub-and-spoke)
            if (map.isStyleLoaded() && routeCoordinates.length > 0) {
                map.addSource('route-coords', {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        geometry: {
                            type: 'MultiLineString',
                            coordinates: routeCoordinates
                        }
                    }
                });
                map.addLayer({
                    id: 'route-line',
                    type: 'line',
                    source: 'route-coords',
                    paint: {
                        'line-color': '#2563eb',
                        'line-width': 2,
                        'line-opacity': 0.45,
                        'line-dasharray': [2, 2] // Dashed lines
                    }
                });
            }
        }

        // --- CHARTS LOGICA ---
        function initCharts() {
            // Chart 1: Entregados
            chartDelivered = new ApexCharts(document.querySelector("#chart-delivered"), {
                chart: { type: 'bar', height: '100%', sparkline: { enabled: true } },
                colors: ['#2563eb'],
                plotOptions: { bar: { columnWidth: '60%', borderRadius: 3 } },
                series: [{ data: [] }],
                xaxis: { categories: [] },
                tooltip: { fixed: { enabled: false }, x: { show: false } }
            });
            chartDelivered.render();

            // Chart 2: Distribución por Horario (Hora Pico)
            chartHourlyDistribution = new ApexCharts(document.querySelector("#chart-hourly-distribution"), {
                chart: { type: 'area', height: '100%', sparkline: { enabled: true } },
                colors: ['#2563eb'],
                stroke: { width: 2, curve: 'smooth' },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1, stops: [0, 90, 100] }
                },
                series: [{ name: 'Pedidos', data: [] }],
                xaxis: { categories: [] },
                tooltip: { fixed: { enabled: false }, x: { show: true } }
            });
            chartHourlyDistribution.render();

            // Chart 3: Cancelados
            chartCancelled = new ApexCharts(document.querySelector("#chart-cancelled"), {
                chart: { type: 'area', height: '100%', sparkline: { enabled: true } },
                colors: ['#ef4444'],
                stroke: { width: 2, curve: 'smooth' },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1, stops: [0, 90, 100] }
                },
                series: [{ data: [] }],
                xaxis: { categories: [] },
                tooltip: { fixed: { enabled: false }, x: { show: false } }
            });
            chartCancelled.render();
        }

        function updateKPIsRange(range) {
            document.querySelectorAll('.kpi-select').forEach(sel => sel.value = range);

            const formData = new FormData();
            formData.append('action', 'get_local_kpis');
            formData.append('local_id', localId);
            formData.append('range', range);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const completados = data.completados ?? 0;
                    const cancelados = data.cancelados ?? 0;
                    const labels = data.labels ?? [];
                    const seriesDelivered = data.series_delivered ?? [];
                    const seriesCancelled = data.series_cancelled ?? [];
                    const hourlyLabels = data.hourly_labels ?? [];
                    const hourlyData = data.hourly_data ?? [];
                    const points = data.points ?? [];

                    // Actualizar métricas numéricas
                    document.getElementById('kpi-delivered-val').innerText = completados;
                    document.getElementById('kpi-cancelled-val').innerText = cancelados;
                    document.getElementById('map-total-distance').innerText = data.distancia_km + ' km';

                    // Calcular y mostrar Hora Pico
                    let maxVal = -1;
                    let peakHourIndex = -1;
                    for (let i = 0; i < hourlyData.length; i++) {
                        if (hourlyData[i] > maxVal) {
                            maxVal = hourlyData[i];
                            peakHourIndex = i;
                        }
                    }
                    if (peakHourIndex !== -1 && maxVal > 0) {
                        document.getElementById('kpi-peak-hour').innerText = hourlyLabels[peakHourIndex] + ` (${maxVal} ped.)`;
                    } else {
                        document.getElementById('kpi-peak-hour').innerText = 'Sin registros';
                    }

                    // Actualizar ApexCharts
                    chartDelivered.updateSeries([{ data: seriesDelivered }]);
                    chartDelivered.updateOptions({ xaxis: { categories: labels } });

                    chartCancelled.updateSeries([{ data: seriesCancelled }]);
                    chartCancelled.updateOptions({ xaxis: { categories: labels } });

                    chartHourlyDistribution.updateSeries([{ data: hourlyData }]);
                    chartHourlyDistribution.updateOptions({ xaxis: { categories: hourlyLabels } });

                    // Actualizar puntos del mapa
                    updateMapPoints(points);
                }
            })
            .catch(err => console.error("Error al actualizar KPIs de local:", err));
        }

        // --- HISTORIAL PAGINADO ---
        function loadLocalHistory(page = 1) {
            const tbody = document.getElementById('local-history-table-body');
            const paginationContainer = document.getElementById('history-pagination');

            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px;">Cargando...</td></tr>';

            const formData = new FormData();
            formData.append('action', 'get_local_history');
            formData.append('local_id', localId);
            formData.append('page', page);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    tbody.innerHTML = '';
                    if (data.history.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:25px; color:var(--text-muted);">Sin entregas registradas.</td></tr>';
                        paginationContainer.innerHTML = '';
                        return;
                    }

                    data.history.forEach(item => {
                        const tr = document.createElement('tr');
                        
                        // Formatear fecha
                        const dateObj = new Date(item.created_at);
                        const day = dateObj.getDate();
                        const month = dateObj.getMonth() + 1;
                        const hours = dateObj.getHours();
                        const minutes = String(dateObj.getMinutes()).padStart(2, '0');
                        const ampm = hours >= 12 ? 'p. m.' : 'a. m.';
                        const displayHours = hours % 12 || 12;
                        const dateStr = `${day}/${month}, ${displayHours}:${minutes} ${ampm}`;

                        tr.innerHTML = `
                            <td>#${item.id}</td>
                            <td>${esc(item.local_name || localName)}</td>
                            <td>${esc(item.delivery_address)}</td>
                            <td>${esc(item.driver_name || 'Sin asignar')}</td>
                            <td><span class="status-pill ${item.status === 'entregado' ? 'approved' : (item.status === 'cancelado' ? 'rejected' : 'pending')}">${item.status}</span></td>
                            <td>${dateStr}</td>
                        `;
                        tbody.appendChild(tr);
                    });

                    // Renderizar botones de paginación
                    renderPagination(data.page, data.total_pages);
                }
            })
            .catch(err => {
                console.error(err);
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#ef4444;">Error al cargar historial.</td></tr>';
            });
        }

        function renderPagination(currentPage, totalPages) {
            const container = document.getElementById('history-pagination');
            container.innerHTML = '';

            if (totalPages <= 1) return;

            // Botón Anterior
            if (currentPage > 1) {
                const prevBtn = document.createElement('button');
                prevBtn.className = 'pagination-btn';
                prevBtn.innerText = '«';
                prevBtn.onclick = () => loadLocalHistory(currentPage - 1);
                container.appendChild(prevBtn);
            }

            // Páginas numéricas
            for (let i = 1; i <= totalPages; i++) {
                // Limitar cantidad de botones si son demasiados
                if (totalPages > 5 && Math.abs(i - currentPage) > 2 && i !== 1 && i !== totalPages) {
                    if (i === 2 || i === totalPages - 1) {
                        const span = document.createElement('span');
                        span.innerText = '...';
                        span.style.padding = '0 6px';
                        container.appendChild(span);
                    }
                    continue;
                }

                const pageBtn = document.createElement('button');
                pageBtn.className = 'pagination-btn' + (i === currentPage ? ' active' : '');
                pageBtn.innerText = i;
                pageBtn.onclick = () => loadLocalHistory(i);
                container.appendChild(pageBtn);
            }

            // Botón Siguiente
            if (currentPage < totalPages) {
                const nextBtn = document.createElement('button');
                nextBtn.className = 'pagination-btn';
                nextBtn.innerText = '»';
                nextBtn.onclick = () => loadLocalHistory(currentPage + 1);
                container.appendChild(nextBtn);
            }
        }

        // --- VERIFICACIÓN SUSCRIPCIÓN ---
        function verifySubscription(status, paymentId) {
            let notes = '';
            if (status === 'rejected') {
                notes = prompt('Por favor, ingresa el motivo del rechazo del comprobante:');
                if (notes === null) return;
            }

            const formData = new FormData();
            formData.append('action', 'verify_driver_payment');
            formData.append('driver_id', localId); // Compartido bajo el driver_id/user_id
            formData.append('payment_id', paymentId);
            formData.append('status', status);
            formData.append('notes', notes);

            fetch('api_admin_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message || 'Verificación procesada con éxito.');
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
            formData.append('driver_id', localId);
            formData.append('hours', selectHours);

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
                    alert(data.error || 'Error al procesar prórroga.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error al otorgar prórroga.');
            });
        }

        function deleteReceipt(paymentId) {
            if (!confirm("¿Está seguro de que desea eliminar permanentemente este comprobante?")) return;
            
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
                    alert(data.error || 'Error al eliminar comprobante.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error en el servidor al eliminar comprobante.');
            });
        }

        // --- VERIFICACIÓN DOCUMENTACIÓN ---
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

        let selectedFrontFile = null;
        let selectedBackFile = null;

        function closeDocModal() {
            document.getElementById('driver-doc-modal').style.display = 'none';
            selectedFrontFile = null;
            selectedBackFile = null;
            const statusText = document.getElementById('admin-upload-status-text');
            if (statusText) statusText.innerText = '';
        }

        function handleAdminDocSelect(side) {
            const fileInput = document.getElementById('admin-upload-' + side);
            if (fileInput.files && fileInput.files[0]) {
                const file = fileInput.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('doc-modal-img-' + side).src = e.target.result;
                };
                reader.readAsDataURL(file);

                if (side === 'front') selectedFrontFile = file;
                if (side === 'back') selectedBackFile = file;

                document.getElementById('admin-upload-status-text').innerText = 'Foto de ' + (side === 'front' ? 'frente' : 'dorso') + ' seleccionada.';
            }
        }

        function updateDocStatus(action) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('driver_id', localId);
            formData.append('doc_type', currentDocType);

            if (selectedFrontFile) {
                formData.append('doc_ci_front', selectedFrontFile);
            }
            if (selectedBackFile) {
                formData.append('doc_ci_back', selectedBackFile);
            }

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

        // --- LIGHTBOX Y DESPLIEGUE ---
        function openReceiptLightbox() {
            const img = document.querySelector('.sub-proof-preview img');
            if (img && img.src) {
                openLightbox(img.src);
            }
        }

        function openLightbox(src) {
            if (src && !src.includes('No+Cargado')) {
                document.getElementById('lightbox-target-img').src = src;
                document.getElementById('image-lightbox').style.display = 'flex';
            }
        }

        function closeLightbox() {
            document.getElementById('image-lightbox').style.display = 'none';
        }

        // Scrolling to subscription
        function scrollToSubscriptionCard() {
            const el = document.getElementById('subscription-card-box');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth' });
            }
        }

        function showAllPayments() {
            document.querySelectorAll('.hidden-payment-item').forEach(el => el.style.display = 'flex');
            const showBtn = document.getElementById('btn-show-all-payments');
            if (showBtn) showBtn.style.display = 'none';
        }

        // --- SONDEO EN TIEMPO REAL ---
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
                return res.text().then(txt => { try { return JSON.parse(txt); } catch(e){ return null; } });
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

                    // Si el pago pertenece a este local actual, agregar dinámicamente el badge de notificación
                    const payments = data.payments || [];
                    const isForThisLocal = payments.some(p => parseInt(p.driver_user_id) === localId);
                    if (isForThisLocal) {
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
                    const newDriverName = firstPayment.driver_name || 'Un comercio';
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

                    toast.onmouseenter = () => { toast.style.transform = 'scale(1.02)'; };
                    toast.onmouseleave = () => { toast.style.transform = 'scale(1)'; };

                    document.body.appendChild(toast);

                    requestAnimationFrame(() => {
                        toast.style.transform = 'translateY(0)';
                        toast.style.opacity = '1';
                    });

                    const closeToast = () => {
                        toast.style.transform = 'translateY(20px)';
                        toast.style.opacity = '0';
                        setTimeout(() => {
                            toast.remove();
                            if (isForThisLocal) {
                                location.reload();
                            }
                        }, 300);
                    };

                    toast.addEventListener('click', (e) => {
                        if (e.target.tagName === 'BUTTON') return;
                        if (newDriverId && parseInt(newDriverId) !== localId) {
                            window.location.href = `admin_local_detail.php?id=${newDriverId}`;
                        } else {
                            location.reload();
                        }
                    });

                    toast.querySelector('button').addEventListener('click', (e) => {
                        e.stopPropagation();
                        closeToast();
                    });

                    setTimeout(closeToast, 5000);
                }
            })
            .catch(err => console.error('Polling error:', err))
            .finally(() => {
                setTimeout(pollNewPayments, 10000);
            });
        }

        // Función de escape básica
        function esc(str) {
            if (!str) return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>
