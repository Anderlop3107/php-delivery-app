<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['repartidor']);

$sessionUser = current_user();
$userData = app_one("SELECT * FROM users WHERE id = ?", "i", [(int)$sessionUser['id']]);

// Generar iniciales para avatar (ej: Anderson López -> AL)
$driverNameParts = explode(' ', trim($userData['name'] ?? ''));
$firstInitial = !empty($driverNameParts[0]) ? mb_substr($driverNameParts[0], 0, 1, 'UTF-8') : 'D';
$lastInitial = count($driverNameParts) > 1 ? mb_substr(end($driverNameParts), 0, 1, 'UTF-8') : '';
$driverInitials = mb_strtoupper($firstInitial . $lastInitial, 'UTF-8');

// Verificar pedidos activos actuales
$activeCountRow = app_one("
    SELECT COUNT(*) as count
    FROM deliveries
    WHERE repartidor_user_id = ?
      AND status NOT IN ('entregado', 'cancelado', 'rechazado')
", "i", [(int)$sessionUser['id']]);
$activeCount = (int)($activeCountRow['count'] ?? 0);

// Verificar estado de documentación
$docsApproved = (
    ($userData['status_doc_ci'] ?? 'none') === 'approved' &&
    ($userData['status_doc_licencia'] ?? 'none') === 'approved' &&
    ($userData['status_doc_habilitacion'] ?? 'none') === 'approved' &&
    ($userData['status_doc_cedula_verde'] ?? 'none') === 'approved'
);

// Verificar estado de suscripción
$subscriptionExpired = true;
if ($userData['subscription_status'] === 'active' && !empty($userData['subscription_expires_at'])) {
    if (strtotime($userData['subscription_expires_at']) >= time()) {
        $subscriptionExpired = false;
    }
}

$latestPayment = null;
if ($subscriptionExpired) {
    $latestPayment = app_one("
        SELECT * FROM driver_payments
        WHERE driver_user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ", "i", [(int)$sessionUser['id']]);
}

$title = 'Escáner de Pedidos';
require __DIR__ . '/_header.php';
?>

<!-- SweetAlert2 local copy -->
<script src="<?= esc(delivery_app_url('assets/js/sweetalert2.min.js')) ?>"></script>

<!-- Mapbox GL JS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.js"></script>

<style>
    body, .driver-scanner-view {
        background-color: #ffffff !important;
    }
    .driver-scanner-view {
        min-height: calc(100vh - 120px);
        display: flex;
        flex-direction: column;
        align-items: center;
        padding-top: 30px;
    }

    /* PROFILE HEADER SECTION */
    .profile-header-tech {
        text-align: center;
        margin-bottom: 40px;
        animation: fadeInDown 0.6s ease-out;
    }
    @keyframes fadeInDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

    .avatar-wrapper-tech {
        position: relative;
        width: 88px;
        height: 88px;
        margin: 0 auto 18px;
    }
    .avatar-img-tech {
        width: 100%; height: 100%;
        border-radius: 50%;
        border: 3.5px solid #fff;
        background: #fff;
        box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        overflow: hidden;
        display: flex; align-items: center; justify-content: center;
    }
    .avatar-img-tech img { width: 100%; height: 100%; object-fit: cover; }
    .avatar-initials-tech {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #ffffff;
        font-size: 28px;
        font-weight: 800;
        font-family: 'Plus Jakarta Sans', sans-serif;
        display: flex;
        align-items: center;
        justify-content: center;
        letter-spacing: -0.5px;
    }
    .radar-center-initials {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        color: #ffffff;
        font-size: 30px;
        font-weight: 800;
        font-family: 'Plus Jakarta Sans', sans-serif;
        display: flex;
        align-items: center;
        justify-content: center;
        letter-spacing: -0.5px;
    }

    .verified-badge-tech {
        position: absolute;
        bottom: 2px;
        right: 2px;
        background: var(--primary);
        color: #fff;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 12px;
        border: 2.5px solid #fff;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
        z-index: 3;
    }

    .welcome-title-tech { font-size: 26px; font-weight: 800; color: var(--text); margin: 0; letter-spacing: -0.5px; }
    .subtitle-tech { font-size: 14px; font-weight: 600; color: var(--muted); margin-top: 6px; }

    /* RADAR */
    .radar-wrapper {
        position: relative;
        width: 240px; height: 240px;
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 35px;
        transition: all 0.5s ease;
    }
    .radar-wrapper.paused { filter: grayscale(1); opacity: 0.4; }
    .radar-center-circle { width: 72px; height: 72px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border-radius: 50%; border: none; z-index: 10; box-shadow: 0 0 30px rgba(37, 99, 235, 0.5); }
    .radar-center-circle img { width: 100%; height: 100%; object-fit: cover; }
    .radar-ring-arc { position: absolute; border: 3px solid transparent; border-top-color: rgba(37, 99, 235, 0.4); border-radius: 50%; animation: rotate-arc linear infinite; }
    .arc-1 { width: 150px; height: 150px; animation-duration: 2s; }
    .arc-2 { width: 195px; height: 195px; animation-duration: 4s; border-right-color: rgba(37, 99, 235, 0.2); }
    .arc-3 { width: 240px; height: 240px; animation-duration: 6s; border-bottom-color: rgba(37, 99, 235, 0.1); }
    .paused .radar-ring-arc { animation-play-state: paused; border-color: #cbd5e1 !important; border-top-color: #94a3b8 !important; }
    .radar-pulse-wave { position: absolute; width: 100px; height: 100px; background: var(--primary-soft); border-radius: 50%; animation: pulse-wave 2s infinite; z-index: 5; }
    .paused .radar-pulse-wave { display: none; }
    @keyframes rotate-arc { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    @keyframes pulse-wave { 0% { transform: scale(1); opacity: 1; } 100% { transform: scale(2.8); opacity: 0; } }

    /* RADAR LASER SWEEP BEAM */
    .radar-sweep-beam {
        position: absolute;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: conic-gradient(from 0deg, rgba(37, 99, 235, 0.35) 0deg, rgba(37, 99, 235, 0) 60deg, transparent 360deg);
        animation: rotate-sweep 3s linear infinite;
        z-index: 4;
        pointer-events: none;
    }
    .paused .radar-sweep-beam {
        display: none;
    }
    @keyframes rotate-sweep {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    /* TOGGLE */
    .availability-toggle-box { text-align: center; }
    .status-label-text { margin-top: 15px; font-size: 14px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
    .status-label-text.active { color: var(--primary); }
    .ios-switch { position: relative; display: inline-block; width: 64px; height: 34px; }
    .ios-switch input { opacity: 0; width: 0; height: 0; }
    .ios-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #e2e8f0; transition: .4s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 34px; }
    .ios-slider:before { position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 50%; box-shadow: 0 3px 8px rgba(0,0,0,0.15); }
    input:checked + .ios-slider { background-color: var(--primary); }
    input:checked + .ios-slider:before { transform: translateX(30px); }

    /* BROADCAST MODAL PREMIUM REDESIGN */
    .broadcast-overlay { 
        position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
        background: rgba(15, 23, 42, 0.55); 
        backdrop-filter: blur(12px) saturate(180%); 
        z-index: 4000; display: none; align-items: center; justify-content: center; padding: 20px; 
    }
    .broadcast-card { 
        background: #fff; width: 100%; max-width: 410px; border-radius: 32px; 
        padding: 32px 28px; 
        border-top: 6px solid var(--primary, #2563eb);
        box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.25), 0 0 20px rgba(37, 99, 235, 0.18), 0 0 0 1px rgba(255, 255, 255, 0.6) inset; 
        animation: modalPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), techPulseGlow 3s ease-in-out infinite; 
    }
    @keyframes modalPop { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    @keyframes techPulseGlow {
        0%, 100% { box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.25), 0 0 20px rgba(37, 99, 235, 0.15), 0 0 0 1px rgba(255, 255, 255, 0.6) inset; }
        50% { box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.35), 0 0 40px rgba(37, 99, 235, 0.32), 0 0 0 1px rgba(255, 255, 255, 0.8) inset; }
    }

    .map-laser-scanner {
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent 0%, rgba(37, 99, 235, 0.85) 50%, transparent 100%);
        box-shadow: 0 0 10px rgba(37, 99, 235, 0.9);
        z-index: 10;
        pointer-events: none;
        border-radius: 2px;
        animation: laserScan 2.5s ease-in-out infinite alternate;
    }
    @keyframes laserScan {
        0% { top: 4px; opacity: 0.2; }
        50% { opacity: 1; }
        100% { top: calc(100% - 6px); opacity: 0.2; }
    }

    .shop-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
    .shop-avatar { 
        width: 54px; height: 54px; 
        background: linear-gradient(135deg, var(--primary-soft) 0%, rgba(37,99,235,0.15) 100%); 
        border-radius: 16px; 
        display: flex; align-items: center; justify-content: center; 
        font-size: 26px; overflow: hidden; 
        border: 1.5px solid rgba(37, 99, 235, 0.15); 
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
    }
    .shop-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .shop-info h3 { font-size: 19px; margin: 0; color: var(--text); font-weight: 800; letter-spacing: -0.3px; }
    .shop-info p { font-size: 11px; color: var(--muted); margin: 2px 0 0; font-weight: 750; letter-spacing: 0.5px; }

    #mini-route-map { height: 170px; border-radius: 22px; border: 1.5px solid var(--border); box-shadow: 0 8px 20px rgba(0,0,0,0.02); }

    .money-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
    .money-box { 
        background: #ffffff; 
        padding: 10px 12px; 
        border-radius: 14px; 
        border: 1.5px solid rgba(0,0,0,0.05); 
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        min-height: 56px;
        transition: all 0.2s ease;
    }
    .money-box:hover {
        transform: translateY(-2px);
    }
    .money-box.earnings { 
        background: rgba(16, 185, 129, 0.06); 
        border-color: rgba(16, 185, 129, 0.15); 
    }
    .money-box.local-pay {
        background: rgba(37, 99, 235, 0.06);
        border-color: rgba(37, 99, 235, 0.15);
    }
    .money-box small { 
        display: block; font-size: 9px; font-weight: 850; color: var(--muted); 
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 3px; 
    }
    .money-box.earnings small { color: #10b981; }
    .money-box.local-pay small { color: var(--primary, #2563eb); }
    .money-box b { font-size: 16px; color: var(--text); font-weight: 800; letter-spacing: -0.3px; }
    .money-box.earnings b { color: #10b981; }
    .money-box.local-pay b { color: var(--primary, #2563eb); }

    .btn-submit-payment {
        width: 100%;
        padding: 16px;
        border: none;
        border-radius: 20px;
        background: var(--primary, #2563eb);
        color: #ffffff;
        font-size: 15px;
        font-weight: 800;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
        transition: all 0.2s;
        box-sizing: border-box;
        max-width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        height: 56px; /* consistent height on mobile */
    }

    @media (max-width: 480px) {
        .btn-submit-payment {
            font-size: 14px;
            padding: 14px;
            border-radius: 16px;
        }
    }
    .btn-submit-payment:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .btn-accept-now { 
        width: 100%; 
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); 
        color: #fff; border: none; border-radius: 20px; 
        padding: 18px; font-weight: 800; font-size: 16px; 
        display: flex; align-items: center; 
        justify-content: center; gap: 10px; cursor: pointer; transition: all 0.3s;
        box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25);
        letter-spacing: 0.5px;
    }
    .btn-accept-now:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 30px rgba(37, 99, 235, 0.35);
    }
    .btn-accept-now:active { transform: scale(0.97); }
    
    /* Estilos del slider Deslizar para Aceptar */
    .swipe-track {
        position: relative;
        width: 100%;
        height: 58px;
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border: none;
        border-radius: 29px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.1), 0 10px 25px rgba(37, 99, 235, 0.2);
        user-select: none;
        margin-top: 10px;
    }
    .swipe-track::after {
        content: '';
        position: absolute;
        top: 0; left: -100%;
        width: 60%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
        transform: skewX(-20deg);
        pointer-events: none;
        animation: shimmerSweep 3.5s infinite;
        z-index: 2;
    }
    @keyframes shimmerSweep {
        0% { left: -100%; }
        30%, 100% { left: 180%; }
    }
    .swipe-bg-fill {
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 0;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border-radius: 29px;
        z-index: 1;
    }
    .swipe-text {
        position: absolute;
        width: 100%;
        text-align: center;
        font-weight: 800;
        font-size: 11.5px;
        color: #fff;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 1px;
        z-index: 2;
        pointer-events: none;
        transition: all 0.2s;
    }
    .swipe-handle {
        position: absolute;
        left: 4px;
        top: 4px;
        width: 50px;
        height: 50px;
        background: #fff;
        color: var(--primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: grab;
        z-index: 3;
        box-shadow: 0 4px 10px rgba(0,0,0,0.12);
        transition: background-color 0.2s, color 0.2s;
    }
    .swipe-handle:active {
        cursor: grabbing;
    }
    .swipe-handle svg {
        color: var(--primary) !important;
        opacity: 1 !important;
        transition: transform 0.2s;
        animation: chevronSlide 1.4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    }

    @keyframes chevronSlide {
        0%, 100% {
            transform: translateX(0);
            opacity: 0.85;
        }
        50% {
            transform: translateX(5px);
            opacity: 1;
        }
    }
    
    /* Estado cuando se desliza completamente y se procesa */
    .swipe-track.processing .swipe-text {
        color: #fff;
        opacity: 1;
    }
    .swipe-track.processing .swipe-handle {
        background: #10b981;
        color: #fff;
    }
    .swipe-track.processing .swipe-handle svg {
        color: #fff !important;
        transform: rotate(360deg);
    }
    
    .btn-ignore { 
        width: 100%; margin-top: 16px; background: transparent; color: var(--muted); border: none; 
        font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s;
        letter-spacing: 0.5px;
    }
    .btn-ignore:hover { color: var(--text); }

    @keyframes pulse-dot {
        0% { opacity: 0.4; }
        50% { opacity: 1; }
        100% { opacity: 0.4; }
    }

    /* Success Modal */
    .modal-overlay { 
        position: fixed; 
        top: 0; left: 0; right: 0; bottom: 0; 
        background: rgba(15, 23, 42, 0.4); 
        backdrop-filter: blur(8px); 
        -webkit-backdrop-filter: blur(8px);
        z-index: 5000; 
        display: none; 
        align-items: center; 
        justify-content: center; 
        padding: 20px; 
    }
    .modal-card-success { 
        background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%); 
        width: 100%; 
        max-width: 320px; 
        border-radius: 28px; 
        padding: 40px 24px 30px; 
        text-align: center; 
        position: relative; 
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
        animation: modalPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
    }
    .modal-close-top { 
        position: absolute; 
        top: -16px; left: 50%; 
        transform: translateX(-50%); 
        width: 32px; height: 32px; 
        background: #ffffff; 
        border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; 
        box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
        cursor: pointer; 
        border: none; 
        font-weight: 800; 
        color: var(--primary); 
        transition: transform 0.2s;
    }
    .modal-close-top:active { transform: translateX(-50%) scale(0.9); }
    
    .status-icon-container { 
        width: 80px; height: 80px; 
        border-radius: 50%; 
        background: rgba(255, 255, 255, 0.15); 
        margin: 0 auto 25px; 
        display: flex; align-items: center; justify-content: center; 
        position: relative; 
    }
    .status-icon-waves { 
        position: absolute; 
        width: 100%; height: 100%; 
        border-radius: 50%; 
        border: 2px solid rgba(255, 255, 255, 0.25); 
        animation: waveRipple 2s infinite; 
    }
    @keyframes waveRipple { from { transform: scale(1); opacity: 1; } to { transform: scale(1.6); opacity: 0; } }
    .check-mark { font-size: 36px; color: #ffffff; font-weight: 800; z-index: 2; }

    .modal-card-success h2 { font-size: 22px; font-weight: 800; margin: 0 0 8px; color: #ffffff; letter-spacing: -0.5px; }
    .modal-card-success p { font-size: 14px; color: rgba(255, 255, 255, 0.85); margin: 0 0 30px; font-weight: 600; }
    
    .btn-listo { 
        background: #ffffff; 
        color: var(--primary); 
        width: 100%; 
        padding: 16px; 
        border-radius: 16px; 
        font-weight: 800; 
        border: none; 
        cursor: pointer; 
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15); 
        transition: all 0.2s; 
    }
    .btn-listo:active { transform: scale(0.97); opacity: 0.95; }

    /* Estilos Premium para las pastillas de estado */
    .status-pill.approved { background: #d1fae5 !important; color: #065f46 !important; }
    .status-pill.pending { background: #fef3c7 !important; color: #92400e !important; }
    .status-pill.rejected { background: #fee2e2 !important; color: #991b1b !important; }
    .status-pill.none { background: #f1f5f9 !important; color: #64748b !important; }

    @keyframes pulseGlow {
    0% { transform: scale(1); box-shadow: 0 0 20px rgba(245, 158, 11, 0.12); }
    50% { transform: scale(1.05); box-shadow: 0 0 30px rgba(245, 158, 11, 0.25); }
    100% { transform: scale(1); box-shadow: 0 0 20px rgba(245, 158, 11, 0.12); }
}
/* Premium modal styling */
.premium-modal {
    background: rgba(255,255,255,0.2);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.3);
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    border-radius: 28px;
    padding: 40px 30px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    animation: scaleIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
</style>

<div class="driver-scanner-view">

<?php if (!$docsApproved): ?>
<div class="subscription-block-overlay" id="docs-block-modal" style="
    position: fixed; inset: 0; z-index: 100000;
    background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(15px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
">
    <div style="
        background: #ffffff; border-radius: 28px; padding: 40px 30px;
        width: 100%; max-width: 420px; text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        border: 1px solid rgba(255, 255, 255, 0.2);
    ">
        <div style="
            width: 80px; height: 80px; border-radius: 50%;
            background: rgba(59, 130, 246, 0.1); color: #3b82f6;
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; margin: 0 auto 24px;
        ">
            📄
        </div>

        <h2 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 12px;">Documentación pendiente</h2>
        <p style="font-size: 15px; color: #64748b; margin: 0 0 24px; line-height: 1.6; font-weight: 500;">
            Para recibir pedidos, el administrador debe verificar y aprobar tu documentación obligatoria.
        </p>

        <div style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-bottom: 30px;">
            <?php
            $docStatuses = [
                'ci' => ['Cédula de Identidad', $userData['status_doc_ci'] ?? 'none'],
                'licencia' => ['Licencia de Conducir', $userData['status_doc_licencia'] ?? 'none'],
                'habilitacion' => ['Habilitación Municipal', $userData['status_doc_habilitacion'] ?? 'none'],
                'cedula_verde' => ['Cédula Verde', $userData['status_doc_cedula_verde'] ?? 'none']
            ];
            $statusLabels = [
                'none' => 'Sin cargar ⚠️',
                'pending' => 'En revisión ⏳',
                'approved' => 'Aprobado ✓',
                'rejected' => 'Rechazado ❌'
            ];
            foreach ($docStatuses as $key => $info): 
                $label = $info[0];
                $status = $info[1];
                $badgeText = $statusLabels[$status] ?? $status;
            ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #ffffff; border-radius: 12px; font-size: 13px; font-weight: 700; border: 1px solid #f1f5f9;">
                    <span style="color:#475569; font-weight: 700;"><?= $label ?></span>
                    <span class="status-pill <?= $status ?>" style="font-size: 10px; padding: 4px 10px; border-radius: 20px; font-weight: 800; text-transform: uppercase;"><?= $badgeText ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <a href="profile.php?tab=documentos" style="
            display: block; width: 100%; padding: 16px; border-radius: 16px;
            background: var(--primary, #2563eb); color: #ffffff; text-decoration: none;
            font-size: 15px; font-weight: 800; text-align: center;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
            transition: all 0.2s;
        ">
            Subir o Editar Documentos
        </a>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const soundUrl = '<?= esc(delivery_app_url("assets/sounds/notification.mp3")) ?>';
    if (window.playNotificationSound) {
        window.playNotificationSound(soundUrl);
    } else {
        const audio = new Audio(soundUrl);
        audio.play().catch(e => console.log(e));
    }
});
</script>
<?php elseif ($subscriptionExpired): ?>
<div class="subscription-block-overlay" id="subscription-block-modal" style="
    position: fixed; inset: 0; z-index: 100000;
    background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(15px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
">
    <div id="subscription-modal-content" style="
        background: #ffffff; border-radius: 28px; padding: 40px 30px;
        width: 100%; max-width: 420px; text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        border: 1px solid rgba(255, 255, 255, 0.2);
    ">
        <?php if ($latestPayment && $latestPayment['status'] === 'pending'): ?>
            <div style="
                width: 80px; height: 80px; border-radius: 50%;
                background: rgba(245, 158, 11, 0.08); color: #f59e0b;
                display: flex; align-items: center; justify-content: center;
                font-size: 40px; margin: 0 auto 24px;
                box-shadow: 0 0 20px rgba(245, 158, 11, 0.12);
                animation: pulseGlow 2s infinite ease-in-out;
            ">
                ⏳
            </div>
            <h2 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 12px; letter-spacing: -0.5px;">Comprobante en verificación</h2>
            <p style="font-size: 15px; color: #64748b; margin: 0 0 24px; line-height: 1.6; font-weight: 500;">
                Tu comprobante de pago fue subido con éxito y está en revisión. El administrador te habilitará pronto.
            </p>
            <div style="
                background: #ffffff; border: 1px solid #e2e8f0; padding: 14px; border-radius: 16px;
                font-size: 13.5px; color: #475569; font-weight: 700;
                display: flex; align-items: center; justify-content: center; gap: 8px;
            ">
                <span>📅</span>
                <span>Enviado el: <?= date('d/m/Y H:i', strtotime($latestPayment['uploaded_at'])) ?> (UTC-3)</span>
            </div>
        <?php else: ?>
            <div style="
                width: 80px; height: 80px; border-radius: 50%;
                background: rgba(239, 68, 68, 0.1); color: #ef4444;
                display: flex; align-items: center; justify-content: center;
                font-size: 40px; margin: 0 auto 24px;
            ">
                💳
            </div>
            <h2 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 12px; letter-spacing: -0.5px;">Suscríbete para recibir más pedidos</h2>
            <p style="font-size: 15px; color: #64748b; margin: 0 0 24px; line-height: 1.6; font-weight: 500;">
                Tu acceso ha vencido o requiere renovación. Por favor, sube tu comprobante de pago semanal para continuar activo en la plataforma.
            </p>
            
            <?php if ($latestPayment && $latestPayment['status'] === 'rejected'): ?>
                <div style="
                    background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c;
                    padding: 12px; border-radius: 12px; font-size: 13px; font-weight: 600;
                    margin-bottom: 20px; text-align: left;
                ">
                    ❌ <b>Comprobante rechazado anterior:</b><br>
                    <?= esc($latestPayment['notes'] ?: 'No se especificaron motivos.') ?>
                </div>
            <?php endif; ?>

            <form id="payment-upload-form" style="display: flex; flex-direction: column; gap: 16px;">
                <label style="
                    display: flex; flex-direction: column; align-items: center; justify-content: center;
                    border: 2px dashed #cbd5e1; border-radius: 16px; padding: 24px; cursor: pointer;
                    background: #ffffff; transition: all 0.2s;
                " id="upload-label" ondragover="event.preventDefault()" ondrop="handleDrop(event)">
                    <span style="font-size: 32px; margin-bottom: 8px;">📷</span>
                    <span style="font-size: 14px; font-weight: 700; color: #475569;" id="file-label-text">Seleccionar foto de comprobante</span>
                    <span style="font-size: 11px; color: #94a3b8; margin-top: 4px;">Formatos: JPG, JPEG, PNG (Máx 8MB)</span>
                    <input type="file" name="payment_proof" id="payment_proof" accept="image/*" style="display: none;" onchange="handleFileSelect(this)">
                </label>
                
                <button type="submit" id="btn-submit-payment" class="btn-submit-payment">
    Subir Comprobante
</button>
            </form>
            
            <script>
                function handleFileSelect(input) {
                    const text = document.getElementById('file-label-text');
                    if (input.files && input.files[0]) {
                        text.innerText = "📄 " + input.files[0].name;
                        const lbl = document.getElementById('upload-label');
                        if (lbl) {
                            lbl.style.borderColor = '#10b981';
                            lbl.style.background = '#f0fdf4';
                        }
                    }
                }
                
                function handleDrop(e) {
                    e.preventDefault();
                    const input = document.getElementById('payment_proof');
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                        input.files = e.dataTransfer.files;
                        handleFileSelect(input);
                    }
                }
                
                const uploadForm = document.getElementById('payment-upload-form');
                if (uploadForm) {
                    uploadForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fileInput = document.getElementById('payment_proof');
    if (!fileInput.files || fileInput.files.length === 0) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'warning',
            title: 'Selecciona una foto del comprobante de pago.',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false
        });
        return;
    }

    const btn = document.getElementById('btn-submit-payment');
    btn.disabled = true;
    btn.innerText = 'Subiendo...';

    const formData = new FormData(this);
    try {
        const resp = await fetch('api_driver_upload_payment.php', {
            method: 'POST',
            body: formData
        });
        // Ensure HTTP success before parsing JSON
        if (!resp.ok) {
            throw new Error('Error HTTP ' + resp.status);
        }
        const res = await resp.json();
        if (res.success) {
            const audio = document.getElementById('upload-success-sound');
            if (audio) {
                audio.play().catch(err => {
                    console.warn('Audio playback blocked or failed:', err);
                });
            }
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: res.message || 'Comprobante subido correctamente.',
                timer: 3000,
                timerProgressBar: true,
                showConfirmButton: false
            });
            // Update UI to show pending verification without reload
            const modalContent = document.getElementById('subscription-modal-content');
            if (modalContent) {
                modalContent.innerHTML = `
                    <div style="
                        width: 80px; height: 80px; border-radius: 50%;
                        background: rgba(245, 158, 11, 0.08); color: #f59e0b;
                        display: flex; align-items: center; justify-content: center;
                        font-size: 40px; margin: 0 auto 24px;
                        box-shadow: 0 0 20px rgba(245, 158, 11, 0.12);
                        animation: pulseGlow 2s infinite ease-in-out;
                    ">
                        ⏳
                    </div>
                    <h2 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 12px; letter-spacing: -0.5px;">Comprobante en verificación</h2>
                    <p style="font-size: 15px; color: #64748b; margin: 0 0 24px; line-height: 1.6; font-weight: 500;">
                        Tu comprobante de pago fue subido con éxito y está en revisión. El administrador te habilitará pronto.
                    </p>
                    <div style="
                        background: #ffffff; border: 1px solid #e2e8f0; padding: 14px; border-radius: 16px;
                        font-size: 13.5px; color: #475569; font-weight: 700;
                        display: flex; align-items: center; justify-content: center; gap: 8px;
                    ">
                        <span>📅</span>
                        <span>Enviado el: Recién (UTC-3)</span>
                    </div>
                `;
            }
            // Recargar página después de breve pausa
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'error',
                title: res.message || 'Error al subir el comprobante.',
                timer: 4000,
                timerProgressBar: true,
                showConfirmButton: false
            });
        }
    } catch (err) {
        console.error('Upload error:', err);
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: err.message || 'Error de conexión con el servidor.',
            timer: 4000,
            timerProgressBar: true,
            showConfirmButton: false
        });
    } finally {
        // Ensure button state is restored unless we already reloaded
        const btn = document.getElementById('btn-submit-payment');
        if (btn) {
            btn.disabled = false;
            btn.innerText = 'Subir Comprobante';
        }
    }
});
}
            </script>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modal de Activación Exitosa -->
<div id="activation-success-modal" class="modal-overlay" style="display: none; z-index: 100001; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(10px); position: fixed; inset: 0; align-items: center; justify-content: center; padding: 20px;">
    <div class="premium-modal"> 
        <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(16, 185, 129, 0.1); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 40px; margin: 0 auto 24px;">
            🎉
        </div>
        <h2 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 12px;">¡Cuenta Activada!</h2>
        <p style="font-size: 15px; color: #64748b; margin: 0 0 30px; line-height: 1.6; font-weight: 500;">
            El administrador aprobó tu cuenta. Ya estás habilitado para recibir y tomar pedidos en vivo.
        </p>
        <button type="button" onclick="acceptActivation()" style="width: 100%; padding: 16px; border-radius: 16px; background: #10b981; color: #ffffff; font-size: 15px; font-weight: 800; border: none; cursor: pointer; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);">
            Comenzar a Trabajar
        </button>
    </div>
</div>

    <!-- SECCIÓN DE PERFIL PERSONALIZADA -->
    <div class="profile-header-tech">
        <div class="avatar-wrapper-tech">
            <div class="avatar-img-tech">
                <?php if (!empty($userData['logo_path'])): ?>
                    <img src="<?= esc(delivery_app_url($userData['logo_path'])) ?>?v=<?= time() ?>" alt="Avatar">
                <?php else: ?>
                    <div class="avatar-initials-tech"><?= esc($driverInitials) ?></div>
                <?php endif; ?>
            </div>
            <div class="verified-badge-tech" title="Cuenta Verificada">✓</div>
        </div>
        <h2 class="welcome-title-tech">¡Hola!, <?= explode(' ', esc($userData['name']))[0] ?></h2>
        <p class="subtitle-tech">Conéctate para recibir pedidos</p>
    </div>

    <!-- ÁREA DEL RADAR CON LÁSER 360° -->
    <div class="radar-wrapper paused" id="radar-ui">
        <div class="radar-pulse-wave"></div>
        <div class="radar-sweep-beam"></div>
        <div class="radar-ring-arc arc-1"></div>
        <div class="radar-ring-arc arc-2"></div>
        <div class="radar-ring-arc arc-3"></div>
        <div class="radar-center-circle"></div>
    </div>

    <!-- ÁREA DEL TOGGLE -->
    <div class="availability-toggle-box">
        <label class="ios-switch">
            <input type="checkbox" id="main-status-toggle" onchange="handleScannerToggle(this.checked)" <?= ($userData['is_online'] == 1 && $activeCount < 2) ? 'checked' : '' ?> >
            <span class="ios-slider"></span>
        </label>
        <div class="status-label-text" id="main-status-text">DESCONECTADO</div>
    </div>
</div>

<!-- MODAL DE BROADCAST -->
<div id="broadcast-modal" class="broadcast-overlay">
    <div class="broadcast-card">
        <div class="shop-header">
            <div class="shop-avatar" id="m-shop-logo-container">🏢</div>
            <div class="shop-info">
                <p style="display:inline-flex; align-items:center; gap:5px;">
                    <span style="width:6px; height:6px; background:var(--primary, #2563eb); border-radius:50%; box-shadow:0 0 8px var(--primary, #2563eb); animation:pulse-dot 1.5s infinite;"></span>
                    NUEVO PEDIDO DE
                </p>
                <h3 id="m-shop-name">-</h3>
            </div>
        </div>

        <div style="position: relative; margin-bottom: 20px;">
            <div id="mini-route-map"></div>
            <div class="map-laser-scanner"></div>
            <!-- Floating Live Status Pill -->
            <div id="map-route-badge" style="
                position: absolute; top: 12px; right: 12px;
                background: rgba(15, 23, 42, 0.75); color: #fff;
                backdrop-filter: blur(8px);
                padding: 6px 12px; border-radius: 20px;
                font-size: 11px; font-weight: 800;
                display: none; align-items: center; gap: 6px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.1);
                border: 1px solid rgba(255,255,255,0.15);
                z-index: 100;
            ">
                <span style="color: #10b981; animation: pulse-dot 1.5s infinite;">●</span>
                <span id="route-badge-text">Calculando...</span>
            </div>
        </div>

        <div class="money-row">
            <div class="money-box" id="m-product-box">
                <small id="m-product-label">PAGAS AL LOCAL</small>
                <b id="m-product-amount">0 Gs.</b>
            </div>
            <div class="money-box earnings">
                <small>TU GANANCIA</small>
                <b id="m-earnings">0 Gs.</b>
            </div>
        </div>

        <!-- CONTENEDOR DESLIZABLE PARA ACEPTAR -->
        <div id="swipe-container" class="swipe-track">
            <div id="swipe-bg-fill" class="swipe-bg-fill"></div>
            <div class="swipe-text">Deslizar para Aceptar</div>
            <div id="swipe-handle" class="swipe-handle">
                <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path></svg>
            </div>
        </div>
        <button class="btn-ignore" onclick="closeBroadcast()">IGNORAR</button>
    </div>
</div>

<!-- Modal de Éxito (Pedido Aceptado) -->
<div id="accept-success-modal" class="modal-overlay">
    <div class="modal-card-success">
        <button class="modal-close-top" onclick="closeAcceptSuccessModal()">✕</button>
        <div class="status-icon-container">
            <div class="status-icon-waves"></div>
            <span class="check-mark">✓</span>
        </div>
        <h2>Pedido Aceptado</h2>
        <p>¡Excelente! Ve al local a retirar el pedido.</p>
        <button class="btn-listo" onclick="closeAcceptSuccessModal()">Listo</button>
    </div>
</div>

<!-- Modal Pedido Tomado por Otro -->
<div id="order-taken-modal" style="
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(15,23,42,0.55); backdrop-filter: blur(10px);
    align-items: center; justify-content: center;
">
    <div style="
        background: #fff; border-radius: 28px; padding: 36px 28px 28px;
        width: 88vw; max-width: 360px; text-align: center;
        box-shadow: 0 24px 60px rgba(0,0,0,0.2);
        animation: scaleIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
    ">
        <div style="
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(37, 99, 235, 0.1); margin: 0 auto 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 36px;
        ">❌</div>
        <h3 style="margin: 0 0 8px; font-size: 22px; font-weight: 800; color: #1e293b;">Pedido tomado</h3>
        <p style="margin: 0 0 28px; font-size: 15px; color: #64748b; font-weight: 500; line-height: 1.5;">
            ¡Sigue activo para el próximo pedido!
        </p>
        <button onclick="closeOrderTakenModal()" style="
            width: 100%; padding: 16px; border: none; border-radius: 16px;
            background: var(--primary, #2563eb);
            color: #fff; font-size: 15px; font-weight: 800; cursor: pointer;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
        ">Entendido</button>
    </div>
</div>


<script>
    mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
    
    let checkInterval = null;
    let currentBroadcastId = null;
    let miniMap = null;
    let locationInterval = null;
    let currentLat = null;
    let currentLng = null;
    let mockLat = <?= (float)($userData['latitude'] ?: -25.2637) ?>;
    let mockLng = <?= (float)($userData['longitude'] ?: -57.5759) ?>;
// Toast helper
function showToast(message) {
    const toast = document.getElementById('approval-toast');
    if (toast) {
        toast.querySelector('#toast-message').textContent = message;
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 5000);
    }
}

    let watchId = null;

    function startLocationUpdates() {
        sendCurrentLocation();
        if (locationInterval) clearInterval(locationInterval);
        locationInterval = setInterval(sendCurrentLocation, 2000);

        if (navigator.geolocation && watchId === null) {
            try {
                watchId = navigator.geolocation.watchPosition(
                    (pos) => {
                        currentLat = pos.coords.latitude;
                        currentLng = pos.coords.longitude;
                        updateLocationOnServer(currentLat, currentLng);
                    },
                    (err) => {
                        console.warn("watchPosition warning:", err);
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );
            } catch (e) {
                console.warn("Error initiating watchPosition:", e);
            }
        }
    }

    function stopLocationUpdates() {
        if (locationInterval) {
            clearInterval(locationInterval);
            locationInterval = null;
        }
        if (watchId !== null && navigator.geolocation) {
            try {
                navigator.geolocation.clearWatch(watchId);
            } catch (e) {}
            watchId = null;
        }
    }

    function sendCurrentLocation() {
        if (!navigator.geolocation) {
            return;
        }

        navigator.geolocation.getCurrentPosition((pos) => {
            currentLat = pos.coords.latitude;
            currentLng = pos.coords.longitude;
            updateLocationOnServer(currentLat, currentLng);
        }, (err) => {
            console.warn("No se pudo obtener la geolocalización de GPS:", err);
            if (currentLat !== null && currentLng !== null) {
                updateLocationOnServer(currentLat, currentLng);
            }
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        });
    }

    async function updateLocationOnServer(lat, lng) {
        try {
            const formData = new FormData();
            formData.append('latitude', lat);
            formData.append('longitude', lng);
            await fetch('api_update_location.php', {
                method: 'POST',
                body: formData
            });
        } catch (e) {
            console.error("Error al actualizar ubicación:", e);
        }
    }

    function handleScannerToggle(isOnline, skipDbUpdate = false) {
        const radar = document.getElementById('radar-ui');
        const text = document.getElementById('main-status-text');

        if (!skipDbUpdate) {
            const formData = new FormData();
            formData.append('is_online', isOnline ? '1' : '0');
            fetch('api_toggle_status.php', {
                method: 'POST',
                body: formData
            }).catch(err => console.error("Error toggling status:", err));
        }

        if (isOnline) {
            radar.classList.remove('paused');
            text.innerText = 'BUSCANDO PEDIDOS';
            text.classList.add('active');
            startLocationUpdates();
            startPolling();
        } else {
            radar.classList.add('paused');
            text.innerText = 'DESCONECTADO';
            text.classList.remove('active');
            stopLocationUpdates();
            stopPolling();
            closeBroadcast();
        }
    }

    function startPolling() {
        if (checkInterval) return;
        // Polling ultrarrápido cada 1.5 segundos
        checkInterval = setInterval(async () => {
            try {
                const resp = await fetch('api_check_new_orders.php?_t=' + Date.now());
                const res = await resp.json();
                
                if (res.has_orders && res.order.id !== currentBroadcastId) {
                    // Cerrar modal de "tomado" si estaba visible
                    const takenModal = document.getElementById('order-taken-modal');
                    if (takenModal) takenModal.style.display = 'none';
                    showBroadcast(res.order);
                } else if (!res.has_orders && currentBroadcastId) {
                    // El pedido fue tomado por otro conductor — mostrar modal con sonido
                    showOrderTakenModal();
                    closeBroadcast();
                }
            } catch (e) {}
        }, 1500);
    }

    function stopPolling() {
        if (checkInterval) { clearInterval(checkInterval); checkInterval = null; }
    }

    // Inicializar polling y geolocalización al cargar la página si el repartidor está activo
    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.getElementById('main-status-toggle');
        if (toggle && toggle.checked) {
            handleScannerToggle(true, true);
        }
    });

    function showBroadcast(order) {
        currentBroadcastId = order.id;
        document.getElementById('m-shop-name').innerText = order.local_name;
        document.getElementById('m-earnings').innerText = order.earnings.toLocaleString('de-DE') + ' Gs.';
        
        const logoContainer = document.getElementById('m-shop-logo-container');
        if (order.local_logo) {
            const baseUrl = '<?= esc(delivery_app_url()) ?>/';
            logoContainer.innerHTML = `<img src="${baseUrl}${order.local_logo}" alt="Logo">`;
        } else {
            logoContainer.innerHTML = '🏢';
        }

        // Reset text of badge
        const badge = document.getElementById('map-route-badge');
        if (badge) badge.style.display = 'none';

        const productBox = document.getElementById('m-product-box');
        if (order.driver_pays) {
            productBox.classList.add('local-pay');
            document.getElementById('m-product-label').innerText = 'PAGAS AL LOCAL';
            document.getElementById('m-product-amount').innerText = order.amount_product.toLocaleString('de-DE') + ' Gs.';
        } else {
            productBox.classList.remove('local-pay');
            document.getElementById('m-product-label').innerText = 'PAGO AL LOCAL';
            document.getElementById('m-product-amount').innerText = 'SIN COBRO';
        }

        document.getElementById('broadcast-modal').style.display = 'flex';
        if (typeof resetSlider === 'function') resetSlider();
        playBroadcastSound();

        // Inicializar mini mapa de ruta
        setTimeout(() => initMiniMap(order), 100);
    }

    function initMiniMap(order) {
        if (miniMap) miniMap.remove();
        miniMap = new mapboxgl.Map({
            container: 'mini-route-map',
            style: 'mapbox://styles/mapbox/streets-v12',
            center: [(order.local_lng + order.dest_lng) / 2, (order.local_lat + order.dest_lat) / 2],
            zoom: 12,
            interactive: false
        });

        miniMap.on('load', async () => {
            // Marcador de Local (Comercio) con icono de tienda en azul marca
            const localPinEl = document.createElement('div');
            localPinEl.innerHTML = `
                <div style="
                    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
                    width: 36px; height: 36px;
                    border-radius: 50%;
                    display: flex; align-items: center; justify-content: center;
                    font-size: 17px;
                    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.45);
                    border: 2.5px solid #ffffff;
                ">🏪</div>
            `;
            new mapboxgl.Marker(localPinEl).setLngLat([order.local_lng, order.local_lat]).addTo(miniMap);

            // Marcador de Cliente (Destino) verde estándar
            new mapboxgl.Marker({ color: '#10b981' }).setLngLat([order.dest_lng, order.dest_lat]).addTo(miniMap);
            
            const query = await fetch(`https://api.mapbox.com/directions/v5/mapbox/driving/${order.local_lng},${order.local_lat};${order.dest_lng},${order.dest_lat}?geometries=geojson&access_token=${mapboxgl.accessToken}`);
            const json = await query.json();
            if (json.routes && json.routes[0]) {
                const route = json.routes[0];
                const km = (route.distance / 1000).toFixed(1);
                const mins = Math.round(route.duration / 60);
                
                const badge = document.getElementById('map-route-badge');
                const badgeText = document.getElementById('route-badge-text');
                if (badge && badgeText) {
                    badgeText.innerText = `${km} km · ${mins} min`;
                    badge.style.display = 'flex';
                }

                miniMap.addSource('route', { 'type': 'geojson', 'data': { 'type': 'Feature', 'geometry': route.geometry } });
                miniMap.addLayer({ 'id': 'route', 'type': 'line', 'source': 'route', 'layout': { 'line-join': 'round', 'line-cap': 'round' }, 'paint': { 'line-color': '#2563eb', 'line-width': 4, 'line-opacity': 0.8 } });
                const bounds = new mapboxgl.LngLatBounds([order.local_lng, order.local_lat], [order.dest_lng, order.dest_lat]);
                miniMap.fitBounds(bounds, { padding: 30 });
            }
        });
    }

    let audioCtx = null;
    let audioSourceNode = null;
    let audioGainNode = null;
    let audioBufferCached = null;
    let broadcastAudioHTML5 = null;

    async function playBroadcastSound() {
        const src = '<?= esc(delivery_app_url('assets/sounds/notification.mp3')) ?>';
        
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                if (!audioCtx) {
                    audioCtx = new AudioContextClass();
                }
                if (audioCtx.state === 'suspended') {
                    await audioCtx.resume();
                }

                // Detener cualquier sonido previo
                stopBroadcastSound();

                if (!audioBufferCached) {
                    const resp = await fetch(src);
                    const arrayBuffer = await resp.arrayBuffer();
                    audioBufferCached = await audioCtx.decodeAudioData(arrayBuffer);
                }

                audioSourceNode = audioCtx.createBufferSource();
                audioSourceNode.buffer = audioBufferCached;
                audioSourceNode.loop = true;

                audioGainNode = audioCtx.createGain();
                audioGainNode.gain.value = 3.0; // Ganancia a 300% volumen

                audioSourceNode.connect(audioGainNode);
                audioGainNode.connect(audioCtx.destination);
                audioSourceNode.start(0);
                return;
            }
        } catch (e) {
            console.log("Web Audio looping falló, usando HTML5 fallback:", e);
        }

        // Fallback HTML5 Audio tradicional (100% volumen, bucle)
        playBroadcastSoundHTML5();
    }

    function playBroadcastSoundHTML5() {
        if (!broadcastAudioHTML5) {
            broadcastAudioHTML5 = new Audio('<?= esc(delivery_app_url('assets/sounds/notification.mp3')) ?>');
            broadcastAudioHTML5.loop = true;
            broadcastAudioHTML5.volume = 1.0;
        }
        broadcastAudioHTML5.currentTime = 0;
        broadcastAudioHTML5.play().catch(err => console.log("HTML5 looping falló:", err));
    }

    function stopBroadcastSound() {
        // Detener Web Audio
        if (audioSourceNode) {
            try {
                audioSourceNode.stop();
            } catch(e) {}
            audioSourceNode = null;
        }
        // Detener HTML5 Audio fallback
        if (broadcastAudioHTML5) {
            broadcastAudioHTML5.pause();
            broadcastAudioHTML5.currentTime = 0;
        }
    }

    let acceptTimeout = null;

    async function showAcceptSuccessModal() {
        // Limpiar el broadcast ID INMEDIATAMENTE para que el polling
        // no confunda al conductor que aceptó con uno que perdió el pedido
        currentBroadcastId = null;

        stopBroadcastSound();
        document.getElementById('broadcast-modal').style.display = 'none';

        // Reproducir sonido de éxito
        const successAudio = new Audio('<?= esc(delivery_app_url("uploads/sounds/success.mp3")) ?>');
        successAudio.play().catch(err => console.log("Audio play blocked:", err));

        // Verificar cuántos pedidos activos tiene el conductor
        try {
            const activeResp = await fetch('api_driver_active_count.php?_t=' + Date.now());
            const activeData = await activeResp.json();
            const activeCount = activeData.count ?? 0;

            if (activeCount >= 2) {
                // Conductor lleno — mostrar modal y redirigir a hoja de ruta
                const modal = document.getElementById('accept-success-modal');
                if (modal) modal.style.display = 'flex';
                acceptTimeout = setTimeout(() => { window.location.href = 'my_deliveries.php'; }, 4000);
            } else {
                // Conductor puede tomar otro pedido — toast y seguir en radar
                showToast('✅ ¡Pedido aceptado! Puedes tomar otro más.', 'success');
                resetSlider();
            }
        } catch(e) {
            window.location.href = 'my_deliveries.php';
        }
    }

    function closeAcceptSuccessModal() {
        if (acceptTimeout) clearTimeout(acceptTimeout);
        window.location.href = 'my_deliveries.php';
    }

    function closeOrderTakenModal() {
        document.getElementById('order-taken-modal').style.display = 'none';
    }

    function showOrderTakenModal() {
        document.getElementById('order-taken-modal').style.display = 'flex';
        // Reproducir sonido de pedido tomado
        const rejectAudio = new Audio('<?= esc(delivery_app_url("assets/sounds/order_taken.mp3")) ?>');
        rejectAudio.volume = 0.8;
        rejectAudio.play().catch(err => console.log("Audio rejected:", err));
    }

    async function acceptBroadcastedOrder() {
        if (!currentBroadcastId) return;
        
        stopBroadcastSound();
        
        const track = document.getElementById('swipe-container');
        const text = track ? track.querySelector('.swipe-text') : null;
        if (track) track.classList.add('processing');
        if (text) text.innerText = 'PROCESANDO...';

        const formData = new FormData();
        formData.append('order_id', currentBroadcastId);

        try {
            const resp = await fetch('api_accept_order.php', { method: 'POST', body: formData });
            const res = await resp.json();
            if (res.success) {
                showAcceptSuccessModal();
            } else {
                // Pedido tomado por otro — mostrar modal con sonido
                closeBroadcast();
                showOrderTakenModal();
            }
        } catch (e) {
            showToast('❌ Error de conexión al aceptar el pedido', 'error');
            resetSlider();
        }
    }

    function resetSlider() {
        const track = document.getElementById('swipe-container');
        const handle = document.getElementById('swipe-handle');
        const bgFill = document.getElementById('swipe-bg-fill');
        const text = track ? track.querySelector('.swipe-text') : null;
        
        if (track) track.classList.remove('processing');
        if (text) text.innerText = 'DESLIZAR PARA ACEPTAR';
        if (handle) handle.style.transform = 'translateX(0px)';
        if (bgFill) bgFill.style.width = '0%';
    }

    function closeBroadcast() {
        document.getElementById('broadcast-modal').style.display = 'none';
        currentBroadcastId = null;
        resetSlider();
        stopBroadcastSound();
    }

    function showToast(message, type = 'info') {
        const colors = { warning: '#f59e0b', error: '#ef4444', success: '#10b981', info: '#3b82f6' };
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%);
            background: ${colors[type] || colors.info}; color: #fff;
            padding: 12px 20px; border-radius: 14px; font-size: 14px; font-weight: 700;
            box-shadow: 0 8px 24px rgba(0,0,0,0.25); z-index: 99999;
            animation: toastIn 0.3s ease; white-space: nowrap; max-width: 90vw;
        `;
        toast.innerText = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3500);
    }

    // Inicializar lógica de arrastre para el slider
    document.addEventListener("DOMContentLoaded", () => {
        // Inicializar estado del toggle basado en el checkbox PHP
        const statusToggle = document.getElementById('main-status-toggle');
        if (statusToggle && statusToggle.checked) {
            handleScannerToggle(true, true);
        } else {
            handleScannerToggle(false, true);
        }

        const handle = document.getElementById('swipe-handle');
        const track = document.getElementById('swipe-container');
        const bgFill = document.getElementById('swipe-bg-fill');
        const text = track ? track.querySelector('.swipe-text') : null;

        if (!handle || !track) return;

        let isDragging = false;
        let startX = 0;
        let maxTranslate = 0;
        let currentTranslate = 0;

        function updateSliderDimensions() {
            maxTranslate = track.clientWidth - handle.clientWidth - 8; // 8px de margen (4px izquierdo + 4px derecho)
        }

        // Eventos táctiles
        handle.addEventListener('touchstart', dragStart, { passive: true });
        window.addEventListener('touchmove', dragMove, { passive: false });
        window.addEventListener('touchend', dragEnd);

        // Eventos de ratón
        handle.addEventListener('mousedown', dragStart);
        window.addEventListener('mousemove', dragMove);
        window.addEventListener('mouseup', dragEnd);

        window.addEventListener('resize', updateSliderDimensions);

        function dragStart(e) {
            if (track.classList.contains('processing')) return;
            isDragging = true;
            updateSliderDimensions();
            startX = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
            handle.style.transition = 'none';
            bgFill.style.transition = 'none';
        }

        function dragMove(e) {
            if (!isDragging) return;
            
            const currentX = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
            let diff = currentX - startX;

            if (diff < 0) diff = 0;
            if (diff > maxTranslate) diff = maxTranslate;

            currentTranslate = diff;
            
            handle.style.transform = `translateX(${diff}px)`;
            
            const percentage = (diff / maxTranslate) * 100;
            bgFill.style.width = `calc(${percentage}% + ${handle.clientWidth}px)`;

            if (e.cancelable) e.preventDefault();
        }

        async function dragEnd() {
            if (!isDragging) return;
            isDragging = false;

            handle.style.transition = 'transform 0.25s cubic-bezier(0.4, 0, 0.2, 1)';
            bgFill.style.transition = 'width 0.25s cubic-bezier(0.4, 0, 0.2, 1)';

            if (currentTranslate >= maxTranslate * 0.88) {
                track.classList.add('processing');
                handle.style.transform = `translateX(${maxTranslate}px)`;
                bgFill.style.width = '100%';
                if (text) text.innerText = 'PROCESANDO...';
                await acceptBroadcastedOrder();
            } else {
                currentTranslate = 0;
                handle.style.transform = 'translateX(0px)';
                bgFill.style.width = '0%';
            }
        }
    });

    // --- LÓGICA DE MONITOREO DE APROBACIÓN EN VIVO (POLLING) ---
    function showActivationSuccessModal() {
        document.getElementById('activation-success-modal').style.display = 'flex';
        const soundUrl = '<?= esc(delivery_app_url("uploads/sounds/success.mp3")) ?>';
        if (window.playNotificationSound) {
            window.playNotificationSound(soundUrl);
        } else {
            const audio = new Audio(soundUrl);
            audio.play().catch(e => console.log(e));
        }
    }

    async function acceptActivation() {
        const docsModal = document.getElementById('docs-block-modal');
        if (docsModal) docsModal.style.display = 'none';
        
        const subModal = document.getElementById('subscription-block-modal');
        if (subModal) subModal.style.display = 'none';
        
        document.getElementById('activation-success-modal').style.display = 'none';
        
        try {
            const formData = new FormData();
            formData.append('is_online', '1');
            await fetch('api_toggle_status.php', {
                method: 'POST',
                body: formData
            });
            
            const checkbox = document.getElementById('main-status-toggle');
if (checkbox) {
    checkbox.disabled = false;
    checkbox.checked = true;
    handleScannerToggle(true, true);
} else {
                handleScannerToggle(true, true);
            }
        } catch (e) {
            console.error(e);
            window.location.reload();
        }
    }

    let approvalCheckInterval = setInterval(async () => {
        try {
            const resp = await fetch('api_check_approval.php?_t=' + Date.now());
            const res = await resp.json();
            if (res.success) {
                // Notificaciones push / internas del sistema (10:00 AM aviso o 12:00 PM expiración)
                if (res.notifications && res.notifications.length > 0) {
                    res.notifications.forEach(n => {
                        const soundUrl = '<?= delivery_app_url("assets/sounds/notification.mp3") ?>';
                        if (window.playNotificationSound) {
                            window.playNotificationSound(soundUrl);
                        } else {
                            const audio = new Audio(soundUrl);
                            audio.play().catch(e => console.log(e));
                        }

                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: n.type.includes('expired') ? 'error' : 'warning',
                                title: n.title,
                                text: n.message,
                                confirmButtonText: 'Entendido 💳',
                                confirmButtonColor: '#2563eb'
                            }).then(() => {
                                if (n.type.includes('expired')) {
                                    window.location.reload();
                                }
                            });
                        } else {
                            alert(n.title + "\n\n" + n.message);
                            if (n.type.includes('expired')) window.location.reload();
                        }
                    });
                }

                if (res.approved && (document.getElementById('docs-block-modal') || document.getElementById('subscription-block-modal'))) {
                    clearInterval(approvalCheckInterval);
                    const audio = new Audio('<?= delivery_app_url("assets/sounds/notification.mp3") ?>');
                    audio.play().catch(e => console.log("Autoplay de audio prevenido:", e));
                    
                    showActivationSuccessModal();
                    showToast('¡Cuenta activada! 🎉');
                } else if (res.subscription_expired && !document.getElementById('subscription-block-modal')) {
                    window.location.reload();
                }
            }
        } catch (e) {
            console.error("Error al consultar aprobación:", e);
        }
    }, 5000);
</script>

<?php require __DIR__ . '/_footer.php'; ?>
