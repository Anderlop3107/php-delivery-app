<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/matching.php';
require_login();

$user = current_user();
$isLocal = ($user['role'] === 'local');
$isDriver = ($user['role'] === 'repartidor');

// Consultas optimizadas con coordenadas del local para GPS
if ($isLocal) {
    // Incluir entregas que cambiaron de estado recientemente (último minuto) para poder notificar por audio en tiempo real
    $rows = app_all(
        "SELECT d.*, r.name AS repartidor_name, r.phone AS repartidor_phone, r.logo_path AS repartidor_avatar, r.latitude as repartidor_lat, r.longitude as repartidor_lng, u_local.latitude as local_lat, u_local.longitude as local_lng
         FROM deliveries d
         LEFT JOIN users r ON r.id = d.repartidor_user_id
         JOIN users u_local ON d.local_user_id = u_local.id
         WHERE d.local_user_id = ? AND (d.status NOT IN ('entregado', 'cancelado', 'rechazado') OR d.updated_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE))
         ORDER BY d.created_at DESC",
        'i',
        [(int) $user['id']]
    );
} else {
    $rows = app_all(
        "SELECT d.*, u.business_name as local_name, u.phone as local_phone, u.address as local_address, u.latitude as local_lat, u.longitude as local_lng, u.logo_path as local_logo
         FROM deliveries d
         JOIN users u ON d.local_user_id = u.id
         WHERE d.repartidor_user_id = ? AND d.status NOT IN ('entregado', 'cancelado', 'rechazado')
         ORDER BY d.created_at DESC",
        'i',
        [(int) $user['id']]
    );
}

// Filtrar las filas activas para renderizar en la interfaz
$activeRows = [];
foreach ($rows as $r) {
    if (!in_array($r['status'], ['entregado', 'cancelado', 'rechazado'])) {
        $activeRows[] = $r;
    }
}

$driverLat = 0.0;
$driverLng = 0.0;

// Lógica de ordenación óptima por paradas (Hoja de Ruta) si es repartidor
if ($isDriver && !empty($activeRows)) {
    $driverData = app_one("SELECT latitude, longitude FROM users WHERE id = ?", "i", [(int)$user['id']]);
    $driverLat = (float)($driverData['latitude'] ?? 0);
    $driverLng = (float)($driverData['longitude'] ?? 0);

    if (count($activeRows) > 1) {
        usort($activeRows, function($a, $b) use ($driverLat, $driverLng) {
            $statusA = $a['status'];
            $statusB = $b['status'];

            $isPickupA = in_array($statusA, ['aceptado', 'repartidor_en_local']);
            $isPickupB = in_array($statusB, ['aceptado', 'repartidor_en_local']);

            // Regla 1: Las paradas de retiro (pickup) tienen prioridad sobre las de entrega
            if ($isPickupA && !$isPickupB) return -1;
            if (!$isPickupA && $isPickupB) return 1;

            // Regla 2: Si ambos están en retiro, ir al local más cercano al conductor
            if ($isPickupA && $isPickupB) {
                $distA = calcular_distancia_haversine($driverLat, $driverLng, (float)$a['local_lat'], (float)$a['local_lng']);
                $distB = calcular_distancia_haversine($driverLat, $driverLng, (float)$b['local_lat'], (float)$b['local_lng']);
                // Desempate: si misma distancia (mismo local), el pedido más antiguo va primero
                if (abs($distA - $distB) < 0.001) return strtotime($a['created_at']) <=> strtotime($b['created_at']);
                return $distA <=> $distB;
            }

            // Regla 3: Si ambos están en entrega, ir al domicilio del cliente más cercano
            $distA = calcular_distancia_haversine($driverLat, $driverLng, (float)$a['delivery_latitude'], (float)$a['delivery_longitude']);
            $distB = calcular_distancia_haversine($driverLat, $driverLng, (float)$b['delivery_latitude'], (float)$b['delivery_longitude']);
            // Desempate: pedido más antiguo va primero
            if (abs($distA - $distB) < 0.001) return strtotime($a['created_at']) <=> strtotime($b['created_at']);
            return $distA <=> $distB;
        });
    }

    // Asignar el número de parada secuencial a cada pedido activo
    $seq = 1;
    foreach ($activeRows as &$rowRef) {
        $rowRef['sequence_number'] = $seq++;
    }
    unset($rowRef);
}

$title = 'Pedidos en curso';
require __DIR__ . '/_header.php';
?>

<?php if (!empty($activeRows)): ?>
<!-- Mapbox GL JS & CSS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.js"></script>
<?php endif; ?>

<style>
    .oculto { display: none !important; }
    
    .pending-header { margin-bottom: 28px; padding: 10px 0 5px; }
    .pending-header h1 { 
        font-size: 28px; 
        font-weight: 800; 
        background: linear-gradient(135deg, var(--text) 40%, #475569 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        letter-spacing: -0.03em; 
        margin: 0; 
    }
    
    .status-card { 
        background: #fff; 
        border-radius: 24px; 
        padding: 24px; 
        margin-bottom: 24px; 
        box-shadow: 0 12px 32px -6px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(0,0,0,0.03);
        border-left: 6px solid #cbd5e1;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        overflow: hidden; 
    }
    .status-card.state-pendiente { border-left-color: var(--primary, #2563eb); }
    .status-card.state-local { border-left-color: var(--primary, #2563eb); }
    .status-card.state-transit { border-left-color: var(--primary); }
    .status-card.state-entregado { border-left-color: #10b981; }
    .status-card.delivered-anim { transform: scale(0.9); opacity: 0; height: 0; margin-bottom: 0; padding: 0; border: none; }
    
    .status-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .order-id { font-weight: 700; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
    
    .status-pill-tech {
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 850;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .status-pill-tech.status-pendiente {
        background: rgba(37, 99, 235, 0.08);
        color: var(--primary, #2563eb);
        border: 1px solid rgba(37, 99, 235, 0.2);
    }
    .status-pill-tech.status-local {
        background: rgba(37, 99, 235, 0.08);
        color: var(--primary, #2563eb);
        border: 1px solid rgba(37, 99, 235, 0.2);
    }
    .status-pill-tech.status-transit {
        background: var(--primary-soft);
        color: var(--primary);
    }
    .status-pill-tech.status-entregado {
        background: rgba(16, 185, 129, 0.08);
        color: #10b981;
    }
    
    .customer-info h4 { margin: 0; font-size: 19px; font-weight: 800; color: var(--text); }
    .customer-info p { margin: 6px 0 0; font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; font-weight: 500; }
    .customer-info svg { color: var(--primary); opacity: 0.7; }
    
    .delivery-progress-bento { display: flex; gap: 6px; margin-top: 24px; height: 6px; }
    .progress-bar-segment { flex: 1; background: #f1f5f9; border-radius: 10px; transition: background 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    @keyframes pulse-active-bar {
        0% {
            opacity: 0.55;
            box-shadow: 0 0 4px rgba(37, 99, 235, 0.15);
        }
        50% {
            opacity: 1;
            box-shadow: 0 0 10px rgba(37, 99, 235, 0.45);
        }
        100% {
            opacity: 0.55;
            box-shadow: 0 0 4px rgba(37, 99, 235, 0.15);
        }
    }
    .progress-bar-segment.active { 
        background: var(--primary); 
        animation: pulse-active-bar 1.8s infinite ease-in-out;
        box-shadow: 0 0 12px rgba(37, 99, 235, 0.5);
    }
    .progress-bar-segment.completed { background: #10b981; }

    .step-text-display { margin-top: 12px; font-size: 13px; font-weight: 800; color: var(--primary); text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }

    .person-box { 
        display: flex; align-items: center; gap: 12px; margin-top: 20px; padding: 16px; 
        background: #f8fafc; border-radius: 18px; border: 1px solid rgba(0,0,0,0.02);
    }
    .person-box.assigned-box {
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.04) 0%, rgba(37, 99, 235, 0.08) 100%);
        border: 1px solid rgba(37, 99, 235, 0.12);
        border-radius: 18px;
    }
    .person-avatar { 
        width: 44px; height: 44px; border-radius: 14px; background: var(--primary); 
        display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 16px; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
        overflow: hidden; flex-shrink: 0;
    }
    .searching-avatar {
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(37, 99, 235, 0.18) 100%) !important;
        border: 1.5px solid rgba(37, 99, 235, 0.25) !important;
        box-shadow: 0 0 16px rgba(37, 99, 235, 0.15) !important;
        position: relative;
    }
    .searching-radar-ring {
        position: absolute;
        width: 100%; height: 100%;
        border-radius: 14px;
        border: 2px solid var(--primary, #2563eb);
        animation: radarPing 1.8s cubic-bezier(0, 0.2, 0.8, 1) infinite;
        opacity: 0;
    }
    @keyframes radarPing {
        0% { transform: scale(0.7); opacity: 0.85; }
        100% { transform: scale(1.45); opacity: 0; }
    }
    .person-details { flex: 1; }
    .person-details b { display: block; font-size: 15px; font-weight: 700; color: var(--text); }
    .person-details span { font-size: 11px; color: var(--muted); font-weight: 600; }
    
    .wa-link-btn { 
        background: #25d366; color: #fff; width: 40px; height: 40px; border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; text-decoration: none; 
        box-shadow: 0 4px 12px rgba(37, 211, 102, 0.25); transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .wa-link-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(37, 211, 102, 0.35); }
    .wa-link-btn:active { transform: scale(0.92); }
    
    .call-link-btn { 
        background: #3b82f6; color: #fff; width: 40px; height: 40px; border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; text-decoration: none; 
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25); transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .call-link-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35); }
    .call-link-btn:active { transform: scale(0.92); }
    
    .wa-link-btn svg, .call-link-btn svg {
        color: #fff !important;
        opacity: 1 !important;
    }
    
    .driver-actions { margin-top: 20px; display: grid; gap: 12px; }
    .btn-action-main { 
        background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%); 
        color: #fff; border: none; border-radius: 18px; padding: 16px 20px; 
        font-weight: 800; font-size: 15px; cursor: pointer; 
        box-shadow: 0 8px 22px rgba(37, 99, 235, 0.2); 
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
    }
    .btn-action-main:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 26px rgba(37, 99, 235, 0.3);
    }
    .btn-action-main:active {
        transform: scale(0.97);
    }
    .btn-action-gps { 
        background: #1e293b; color: #fff; border: none; border-radius: 18px; padding: 16px 20px; 
        font-weight: 750; font-size: 14px; cursor: pointer; 
        display: flex; align-items: center; justify-content: center; gap: 8px; 
        box-shadow: 0 8px 22px rgba(30, 41, 59, 0.15); 
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
    }
    .btn-action-gps:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 26px rgba(30, 41, 59, 0.25);
    }
    .btn-action-gps:active {
        transform: scale(0.97);
    }

    /* Success Modal (Pedido Entregado) */
    .modal-overlay { 
        position: fixed; 
        top: 0; left: 0; right: 0; bottom: 0; 
        background: rgba(15, 23, 42, 0.4); 
        backdrop-filter: blur(8px); 
        -webkit-backdrop-filter: blur(8px);
        z-index: 3000; 
        display: none; 
        align-items: center; 
        justify-content: center; 
        padding: 20px; 
    }

    /* MODAL BOTTOM SHEET DE SEGUIMIENTO EN VIVO */
    .tracking-modal-overlay {
        position: fixed; inset: 0; z-index: 9999;
        background: rgba(15, 23, 42, 0.35);
        backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
        display: block; overflow: hidden;
        animation: fadeIn 0.3s ease;
    }
    .tracking-header-overlay {
        position: absolute; top: 18px; left: 18px; right: 18px; z-index: 200;
        display: flex; align-items: center; justify-content: space-between;
        pointer-events: none;
    }
    .tracking-back-btn {
        pointer-events: auto !important;
        width: 44px !important; height: 44px !important;
        min-width: 44px !important; min-height: 44px !important;
        max-width: 44px !important; max-height: 44px !important;
        border-radius: 50% !important; aspect-ratio: 1 / 1 !important;
        padding: 0 !important; margin: 0 !important; box-sizing: border-box !important;
        background-color: rgba(255, 255, 255, 0.82) !important;
        backdrop-filter: blur(10px) !important; -webkit-backdrop-filter: blur(10px) !important;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%232563eb' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M19 12H5M12 19L5 12L12 5'/%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: center !important;
        background-size: 22px 22px !important;
        border: 1.5px solid rgba(255, 255, 255, 0.8) !important;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12) !important;
        cursor: pointer !important; transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative; z-index: 501; flex-shrink: 0;
    }
    .tracking-back-btn:hover {
        transform: translateY(-2px) scale(1.06);
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.25);
        background-color: rgba(255, 255, 255, 0.95) !important;
    }
    .tracking-back-btn:active { transform: scale(0.92); }

    .tracking-header-title {
        font-size: 18px; font-weight: 800; color: #111827;
        background: transparent; border: none; box-shadow: none; padding: 0;
    }

    .tracking-map-view {
        position: absolute; inset: 0; width: 100%; height: 100%;
        background: #e2e8f0; z-index: 10;
    }

    .tracking-bottom-sheet {
        position: fixed !important; bottom: 25px !important; top: auto !important; left: 25px !important; right: 25px !important;
        width: auto !important; height: auto !important; max-height: 70vh;
        background: #ffffff;
        border-radius: 24px !important;
        box-shadow: 0 16px 48px rgba(15, 23, 42, 0.22), 0 4px 12px rgba(0,0,0,0.08);
        border: 1.5px solid rgba(255, 255, 255, 0.9);
        padding: 16px 20px 25px 20px; display: flex; flex-direction: column; gap: 10px;
        overflow-y: auto; z-index: 100;
        transition: max-height 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        touch-action: pan-y;
    }
    .tracking-bottom-sheet.expanded {
        max-height: 75vh;
    }

    .sheet-drag-handle {
        width: 46px; height: 5px; background: #cbd5e1; border-radius: 10px;
        margin: -8px auto 8px; flex-shrink: 0; cursor: grab;
        transition: background 0.2s, transform 0.2s;
    }
    .sheet-drag-handle:active {
        cursor: grabbing; background: #94a3b8; transform: scaleX(1.1);
    }

    .driver-profile-header {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
    }
    .driver-profile-info {
        display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;
    }
    .driver-avatar-circle {
        width: 48px; height: 48px; border-radius: 50%;
        border: 2px solid #ffffff; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25);
        overflow: hidden; flex-shrink: 0; background: var(--primary);
        display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px;
    }
    .driver-avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

    .driver-name-title {
        font-size: 17px; font-weight: 700; color: #111827;
        display: flex; align-items: center; gap: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .driver-status-subtitle {
        font-size: 13px; font-weight: 400; color: #6B7280;
        display: flex; align-items: center; gap: 6px; margin-top: 2px;
    }
    .live-pulse-dot {
        width: 7px; height: 7px; background: #10b981; border-radius: 50%;
        display: inline-block; box-shadow: 0 0 8px #10b981; animation: pulse-dot 1.5s infinite;
    }

    .driver-contact-capsule {
        display: flex; align-items: center; gap: 8px; flex-shrink: 0;
    }

    .sheet-divider {
        border: none; border-top: 1px solid #f1f5f9; margin: 2px 0;
    }

    .shipment-details-block { display: flex; flex-direction: column; gap: 14px; }
    .shipment-meta-row {
        display: flex; justify-content: space-between; align-items: center;
    }
    .shipment-id-badge { font-size: 15px; font-weight: 700; color: #111827; }
    .shipment-date { font-size: 13px; font-weight: 500; color: #9CA3AF; }

    .timeline-route { display: flex; flex-direction: column; gap: 0; position: relative; padding-left: 4px; }
    .timeline-item { display: flex; align-items: center; gap: 14px; }
    .timeline-marker {
        width: 24px; height: 24px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid #ffffff; box-shadow: 0 3px 10px rgba(0,0,0,0.15); flex-shrink: 0;
    }
    .marker-primary { background: var(--primary, #2563eb); }
    .marker-green { background: #10b981; }

    .animated-pin-marker {
        animation: pinBounce 1.8s infinite ease-in-out;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    }
    @keyframes pinBounce {
        0%, 100% { transform: translateY(0) scale(1); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4); }
        50% { transform: translateY(-3px) scale(1.08); box-shadow: 0 8px 18px rgba(16, 185, 129, 0.55); }
    }

    .timeline-connector {
        width: 2px; height: 24px;
        border-left: 2px dashed var(--primary, #2563eb);
        margin-left: 11px; opacity: 0.8;
    }

    .timeline-content { display: flex; flex-direction: column; }
    .timeline-sub { font-size: 12px; color: #6B7280; font-weight: 500; }
    .timeline-title { font-size: 14px; font-weight: 600; color: #111827; margin-top: 1px; }
    .modal-card { 
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
    @keyframes modalPop { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
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

    .modal-card h2 { font-size: 22px; font-weight: 800; margin: 0 0 8px; color: #ffffff; letter-spacing: -0.5px; }
    .modal-card p { font-size: 14px; color: rgba(255, 255, 255, 0.85); margin: 0 0 30px; font-weight: 600; }
    
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
</style>

<!-- Modal de Éxito (Pedido Entregado) -->
<div id="delivered-success-modal" class="modal-overlay">
    <div class="modal-card">
        <button class="modal-close-top" onclick="closeSuccessModal()">✕</button>
        <div class="status-icon-container">
            <div class="status-icon-waves"></div>
            <span class="check-mark">✓</span>
        </div>
        <h2>¡Pedido Entregado!</h2>
        <p>¡Buen trabajo! Has completado esta entrega con éxito.</p>
        <button class="btn-listo" onclick="closeSuccessModal()">Listo</button>
    </div>
</div>

<div class="pending-header">
    <h1>Seguimiento de Entrega</h1>
    <div style="width: 36px; height: 4.5px; background: var(--primary); border-radius: 10px; margin-top: 10px; box-shadow: 0 3px 10px rgba(37, 99, 235, 0.25);"></div>
</div>



<div class="pending-list" id="orders-list">
    <?php if (empty($activeRows)): ?>
        <div style="text-align: center; padding: 80px 20px;">
            <div style="background: var(--primary-soft); width: 100px; height: 100px; border-radius: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px;">
                <svg style="width: 44px; height: 44px; color: var(--primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h3>Sin actividad</h3>
            <p class="muted">No tienes pedidos activos en curso.</p>
        </div>
    <?php else: ?>
        <?php foreach ($activeRows as $row): 
            $s = strtolower(trim((string)$row['status']));
            // Fallback inteligente para estados corruptos
            if (empty($s)) { $s = $row['repartidor_user_id'] ? 'aceptado' : 'pendiente'; }

            // MAPA DE FLUJO DE 5 ETAPAS (Simplificado: Confirmar Retiro pasa directo a Entregando Pedido)
            $flow = [
                'pendiente' => ['label' => 'Buscando Repartidor', 'prog' => 1],
                'aceptado' => ['label' => 'Camino al Local', 'prog' => 2],
                'repartidor_en_local' => ['label' => 'En el Local / Retirando', 'prog' => 3],
                'en_camino_al_cliente' => ['label' => 'Entregando Pedido', 'prog' => 4],
                'en_puerta' => ['label' => 'Entregando Pedido', 'prog' => 4],
                'entregado' => ['label' => '¡Pedido Entregado!', 'prog' => 5],
            ];
            
            $current = $flow[$s] ?? ['label' => 'Procesando...', 'prog' => 1];
            $prog = $current['prog'];

            // Lógica de visibilidad estricta
            $ocultarCliente = ($s === 'pendiente' || $s === 'aceptado' || $s === 'repartidor_en_local');
            $ocultarLocal = ($s === 'en_camino_al_cliente' || $s === 'en_puerta');
            // Determinar clases de estado para bordes y pills premium dinámicos
            $cardStateClass = '';
            $statusClass = '';
            if ($s === 'pendiente') {
                $cardStateClass = 'state-pendiente';
                $statusClass = 'status-pendiente';
            } elseif ($s === 'aceptado' || $s === 'repartidor_en_local') {
                $cardStateClass = 'state-local';
                $statusClass = 'status-local';
            } elseif ($s === 'en_camino_al_cliente' || $s === 'en_puerta') {
                $cardStateClass = 'state-transit';
                $statusClass = 'status-transit';
            } elseif ($s === 'entregado') {
                $cardStateClass = 'state-entregado';
                $statusClass = 'status-entregado';
            }
        ?>
            <div class="status-card <?= $cardStateClass ?>" id="card-<?= $row['id'] ?>">
                <div class="status-top">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <span class="order-id">ID #<?= $row['id'] ?></span>
                        <?php if ($isDriver && count($activeRows) > 1 && isset($row['sequence_number'])): ?>
                            <span style="font-size: 11px; font-weight: 800; color: var(--primary); background: var(--primary-soft); padding: 4px 8px; border-radius: 6px; display: inline-block; width: max-content; margin-top: 4px; letter-spacing: 0.5px;">
                                📍 PARADA <?= $row['sequence_number'] ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="status-pill-tech <?= $statusClass ?>">
                        <?php if ($s === 'pendiente' || $s === 'aceptado' || $s === 'repartidor_en_local'): ?>
                            <span style="width: 6px; height: 6px; background: var(--primary, #2563eb); border-radius: 50%; display: inline-block; box-shadow: 0 0 8px var(--primary, #2563eb); animation: pulse-dot 1.5s infinite;"></span>
                        <?php endif; ?>
                        <?= $current['label'] ?>
                    </span>
                </div>

                <!-- BARRA DE PROGRESO (4 Segmentos) -->
                <div class="delivery-progress-bento" style="margin-top: 16px;">
                    <?php for($i=1; $i<=4; $i++): 
                        $class = '';
                        if ($prog > $i) $class = 'completed';
                        elseif ($prog == $i) $class = 'active';
                    ?>
                        <div class="progress-bar-segment <?= $class ?>"></div>
                    <?php endfor; ?>
                </div>


                <!-- BLOQUE DEL CLIENTE -->
                <div class="customer-info <?= $ocultarCliente ? 'oculto' : '' ?>" id="info-cliente-<?= $row['id'] ?>" style="margin-top: 20px; display: flex; flex-direction: column; gap: 10px;">
                    
                    <!-- Tarjeta Cliente -->
                    <div style="background: rgba(37, 99, 235, 0.04); border: 1px solid rgba(37, 99, 235, 0.08); border-left: 4px solid var(--primary); border-radius: 16px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1;">
                            <div style="background: var(--primary); color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);">
                                <svg style="width:16px; height:16px; color: #fff; opacity: 1;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <div style="min-width: 0; flex: 1;">
                                <small style="display: block; font-size: 9px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Cliente</small>
                                <span style="font-size: 15px; font-weight: 800; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;"><?= esc($row['customer_name'] ?: 'Cliente') ?></span>
                            </div>
                        </div>
                        
                        <!-- Botones de Acción (Llamada / WhatsApp) -->
                        <div style="display: flex; gap: 8px; flex-shrink: 0;">
                            <?php 
                                $cleanCustPhone = preg_replace('/[^0-9]/', '', $row['customer_phone'] ?? '');
                                if (str_starts_with($cleanCustPhone, '0')) {
                                    $cleanCustPhone = '595' . substr($cleanCustPhone, 1);
                                } elseif ($cleanCustPhone !== '' && !str_starts_with($cleanCustPhone, '595')) {
                                    $cleanCustPhone = '595' . $cleanCustPhone;
                                }
                                
                                // Mensaje contextual para WhatsApp según el estado de la entrega
                                $businessName = $row['local_name'] ?? 'el local';
                                $custName = $row['customer_name'] ?: 'Cliente';
                                if (($row['status'] ?? '') === 'en_puerta') {
                                    $waMsg = "¡Hola {$custName}! Soy tu repartidor. Ya estoy afuera con tu pedido de {$businessName}. 🚪🛵";
                                } elseif (($row['status'] ?? '') === 'en_camino_al_cliente') {
                                    $waMsg = "¡Hola {$custName}! Soy tu repartidor. Ya retiré tu pedido de {$businessName} y voy en camino a tu ubicación. 🛵";
                                } else {
                                    $waMsg = "¡Hola {$custName}! Soy tu repartidor. Ya acepté tu pedido de {$businessName} y lo estoy preparando. 🛵";
                                }
                                $waUrl = "https://wa.me/{$cleanCustPhone}?text=" . urlencode($waMsg);
                            ?>
                            <a href="<?= $waUrl ?>" target="_blank" class="wa-link-btn" style="width: 36px; height: 36px; box-shadow: 0 3px 8px rgba(37, 211, 102, 0.15);" title="Enviar mensaje de WhatsApp predefinido">
                                <svg style="width:18px; height:18px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                            </a>
                            <a href="tel:<?= $row['customer_phone'] ?>" class="call-link-btn" style="width: 36px; height: 36px; box-shadow: 0 3px 8px rgba(59, 130, 246, 0.15);">
                                <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.387a12.035 12.035 0 01-7.108-7.108c-.155-.44.01-1.275.387-1.556l1.293-.97c.362-.27.528-.733.417-1.173L6.763 2.074a1.125 1.125 0 00-1.091-.852H4.372A2.25 2.25 0 002.122 3.472v1.028z" /></svg>
                            </a>
                        </div>
                    </div>

                    <!-- Tarjeta Dirección -->
                    <div style="background: rgba(37, 99, 235, 0.04); border: 1px solid rgba(37, 99, 235, 0.08); border-left: 4px solid var(--primary); border-radius: 16px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1;">
                            <div style="background: var(--primary); color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);">
                                <svg style="width:16px; height:16px; color: #fff; opacity: 1;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            </div>
                            <div style="min-width: 0; flex: 1;">
                                <small style="display: block; font-size: 9px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Dirección de Entrega</small>
                                <span style="font-size: 13.5px; font-weight: 600; color: var(--text); display: block; line-height: 1.4;"><?= esc($row['delivery_address']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjeta Referencia -->
                    <?php if (!empty($row['order_description'])): ?>
                        <div style="background: rgba(37, 99, 235, 0.04); border: 1px solid rgba(37, 99, 235, 0.08); border-left: 4px solid var(--primary); border-radius: 16px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                            <div style="display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1;">
                                <div style="background: var(--primary); color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);">
                                    <svg style="width:16px; height:16px; color: #fff; opacity: 1;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                </div>
                                <div style="min-width: 0; flex: 1;">
                                    <small style="display: block; font-size: 9px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Indicaciones / Referencia</small>
                                    <span style="font-size: 13px; font-weight: 550; color: var(--muted); display: block; line-height: 1.4;"><?= esc($row['order_description']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tarjeta de Cobro / Ganancia -->
                    <div style="background: rgba(16, 185, 129, 0.04); border: 1px solid rgba(16, 185, 129, 0.08); border-left: 4px solid #10b981; border-radius: 16px; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px;">
                        <?php if ((int)$row['driver_pays_local'] === 1): ?>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: #10b981; color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.25);">
                                    <svg style="width:16px; height:16px; color: #fff; opacity: 1;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <span style="font-size: 14px; font-weight: 800; color: #10b981; display: block; margin-top: 2px;">Resumen de la entrega</span>
                                </div>
                            </div>
                            
                            <div style="margin-top: 4px; display: flex; flex-direction: column; gap: 6px; font-size: 13px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 4px; border-bottom: 1px dashed rgba(0,0,0,0.06);">
                                    <span style="color: var(--muted); font-weight: 550;">Recibes por Producto:</span>
                                    <b style="color: var(--text);"><?= gs($row['amount']) ?></b>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 4px; border-bottom: 1px dashed rgba(0,0,0,0.06);">
                                    <span style="color: var(--muted); font-weight: 550;">Ganancia de Envío:</span>
                                    <b style="color: #10b981;"><?= gs($row['delivery_cost']) ?></b>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 2px;">
                                    <span style="color: var(--text); font-weight: 800;">Cobro Total al Cliente:</span>
                                    <b style="color: var(--primary); font-size: 15px; font-weight: 850;"><?= gs($row['amount'] + $row['delivery_cost']) ?></b>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: #10b981; color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.25);">
                                    <svg style="width:16px; height:16px; color: #fff; opacity: 1;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <small style="display: block; font-size: 9px; font-weight: 800; color: #10b981; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Ganancia</small>
                                    <span style="font-size: 18px; font-weight: 850; color: #10b981; display: block; margin-top: 2px;"><?= gs($row['delivery_cost']) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- BLOQUE DEL LOCAL / REPARTIDOR -->
                <?php if ($isLocal && !empty($row['repartidor_name'])): ?>
                    <!-- Tarjeta de Repartidor Asignado (Formato Amplio + Botones Inferior Derecha) -->
                    <div class="person-box assigned-box <?= $ocultarLocal ? 'oculto' : '' ?>" id="info-local-<?= $row['id'] ?>" style="display: flex; flex-direction: column; padding: 18px; gap: 12px;">
                        <!-- Fila Superior: Avatar + Nombre Completo en Ancho Total -->
                        <div style="display: flex; align-items: center; gap: 14px; width: 100%;">
                            <div class="person-avatar" style="width: 54px; height: 54px; border-radius: 16px; border: 2.5px solid #ffffff; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25); flex-shrink: 0; font-size: 22px;">
                                <?php if (!empty($row['repartidor_avatar'])): ?>
                                    <img src="<?= esc(delivery_app_url($row['repartidor_avatar'])) ?>" alt="Avatar Repartidor" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    🛵
                                <?php endif; ?>
                            </div>
                            <div class="person-details" style="flex: 1; min-width: 0;">
                                <small style="display: flex; align-items: center; gap: 6px; font-size: 10px; font-weight: 800; color: var(--primary, #2563eb); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px;">
                                    <span style="width: 6px; height: 6px; background: #10b981; border-radius: 50%; display: inline-block; flex-shrink: 0;"></span>
                                    Conductor asignado
                                </small>
                                <b style="display: flex; align-items: center; gap: 6px; font-size: 16px; font-weight: 850; color: var(--text);">
                                    <span><?= esc($row['repartidor_name']) ?></span>
                                    <span class="verified-badge-mini" title="Conductor Verificado" style="background: var(--primary, #2563eb); color: #fff; width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 800; flex-shrink: 0; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35); border: 1.5px solid #ffffff;">✓</span>
                                </b>
                            </div>
                        </div>

                        <!-- Fila Inferior: Botones de Acción Alineados a la Inferior Derecha -->
                        <?php if (!empty($row['repartidor_phone'])): ?>
                            <?php 
                                $cleanLPhone = preg_replace('/[^0-9]/', '', $row['repartidor_phone']);
                                if (str_starts_with($cleanLPhone, '0')) {
                                    $cleanLPhone = '595' . substr($cleanLPhone, 1);
                                } elseif ($cleanLPhone !== '' && !str_starts_with($cleanLPhone, '595')) {
                                    $cleanLPhone = '595' . $cleanLPhone;
                                }
                            ?>
                            <div style="display: flex; justify-content: flex-end; align-items: center; gap: 10px; width: 100%; padding-top: 10px; border-top: 1px dashed rgba(37, 99, 235, 0.15);">
                                <a href="https://wa.me/<?= $cleanLPhone ?>" target="_blank" class="wa-link-btn" title="Enviar WhatsApp">
                                    <svg style="width:20px; height:20px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                                </a>
                                <a href="tel:<?= $row['repartidor_phone'] ?>" class="call-link-btn" title="Llamar al repartidor">
                                    <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.387a12.035 12.035 0 01-7.108-7.108c-.155-.44.01-1.275.387-1.556l1.293-.97c.362-.27.528-.733.417-1.173L6.763 2.074a1.125 1.125 0 00-1.091-.852H4.372A2.25 2.25 0 002.122 3.472v1.028z" /></svg>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Tarjeta para Estado Buscando o Vista Repartidor -->
                    <div class="person-box <?= $ocultarLocal ? 'oculto' : '' ?>" id="info-local-<?= $row['id'] ?>">
                        <?php if ($isLocal && empty($row['repartidor_name'])): ?>
                            <div class="person-avatar searching-avatar">
                                <div class="searching-radar-ring"></div>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--primary, #2563eb)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="animation: pulse-dot 1.5s infinite; position: relative; z-index: 2;">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    <path d="M11 8a3 3 0 0 1 3 3"></path>
                                </svg>
                            </div>
                            <div class="person-details">
                                <b style="color: var(--primary, #2563eb); display: flex; align-items: center; gap: 6px;">
                                    Buscando repartidor...
                                </b>
                                <span>Búsqueda en tiempo real activa</span>
                            </div>
                        <?php else: ?>
                            <div class="person-avatar">
                                <?php if (!$isLocal && !empty($row['local_logo'])): ?>
                                    <img src="<?= esc(delivery_app_url($row['local_logo'])) ?>" alt="Logo Local" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    🏪
                                <?php endif; ?>
                            </div>
                            <div class="person-details">
                                <b><?= esc($row['local_name']) ?></b>
                                <span>Punto de retiro</span>
                            </div>
                        <?php endif; ?>
                        <?php 
                            $phone = $isLocal ? $row['repartidor_phone'] : $row['local_phone'];
                            if ($phone): 
                        ?>
                            <div style="display: flex; gap: 10px;">
                                <?php 
                                    $cleanLPhone = preg_replace('/[^0-9]/', '', $phone);
                                    if (str_starts_with($cleanLPhone, '0')) {
                                        $cleanLPhone = '595' . substr($cleanLPhone, 1);
                                    } elseif ($cleanLPhone !== '' && !str_starts_with($cleanLPhone, '595')) {
                                        $cleanLPhone = '595' . $cleanLPhone;
                                    }
                                ?>
                                <a href="https://wa.me/<?= $cleanLPhone ?>" target="_blank" class="wa-link-btn">
                                    <svg style="width:20px; height:20px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                                </a>
                                <a href="tel:<?= $phone ?>" class="call-link-btn">
                                    <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.387a12.035 12.035 0 01-7.108-7.108c-.155-.44.01-1.275.387-1.556l1.293-.97c.362-.27.528-.733.417-1.173L6.763 2.074a1.125 1.125 0 00-1.091-.852H4.372A2.25 2.25 0 002.122 3.472v1.028z" /></svg>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isLocal && !empty($row['repartidor_name'])): ?>
                    <div style="margin-top: 14px;">
                        <button onclick='openTrackingSheetModal(<?= json_encode($row) ?>)' class="btn-action-main" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; padding: 14px; background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%);">
                            📍 Ver Seguimiento de Envío en Vivo
                        </button>
                    </div>
                <?php endif; ?>
                </div>

                <!-- ACCIONES DEL REPARTIDOR -->
                <?php if ($isDriver): ?>
                    <div class="driver-actions">
                        <?php if ($s === 'aceptado'): ?>
                            <button onclick="window.open('https://www.google.com/maps/search/?api=1&query=<?= $row['local_lat'] ?>,<?= $row['local_lng'] ?>')" class="btn-action-gps">🗺️ Abrir GPS al Local</button>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'repartidor_en_local')" class="btn-action-main">📍 Llegué al Local</button>
                        <?php elseif ($s === 'repartidor_en_local'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_puerta')" class="btn-action-main">Confirmar Retiro</button>
                        <?php elseif ($s === 'en_puerta' || $s === 'en_camino_al_cliente'): ?>
                            <button onclick="window.open('https://www.google.com/maps/search/?api=1&query=<?= $row['delivery_latitude'] ?>,<?= $row['delivery_longitude'] ?>')" class="btn-action-gps">🗺️ Abrir GPS al Cliente</button>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'entregado')" class="btn-action-main" style="background:#10b981;">✅ Confirmar Pedido Entregado</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
    async function updateStatus(orderId, newStatus) {
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('status', newStatus);

        try {
            const resp = await fetch('api_update_status.php', {
                method: 'POST',
                body: formData
            });
            const res = await resp.json();

            if (res.success) {
                if (newStatus === 'entregado') {
                    if (window.playNotificationSound) {
                        window.playNotificationSound('<?= esc(delivery_app_url('assets/sounds/delivered.mp3')) ?>');
                    } else {
                        new Audio('<?= esc(delivery_app_url('assets/sounds/delivered.mp3')) ?>').play().catch(e => console.log(e));
                    }
                    showSuccessModal();
                } else {
                    window.location.reload();
                }
            } else { alert(res.message); }
        } catch (e) { console.error(e); }
    }

    let deliveredTimeout = null;

    function showSuccessModal() {
        document.getElementById('delivered-success-modal').style.display = 'flex';
        
        // Redirección automática en 5 segundos a la misma página (para refrescar el estado del dashboard de entregas)
        deliveredTimeout = setTimeout(() => {
            window.location.reload();
        }, 5000);
    }

    function closeSuccessModal() {
        if (deliveredTimeout) clearTimeout(deliveredTimeout);
        document.getElementById('delivered-success-modal').style.display = 'none';
        window.location.reload();
    }

    let trackingSheetMap = null;
    let trackingLiveInterval = null;
    let trackingDriverMarker = null;

    function openTrackingSheetModal(order) {
        if (trackingLiveInterval) {
            clearInterval(trackingLiveInterval);
            trackingLiveInterval = null;
        }

        document.getElementById('t-driver-name').innerText = order.repartidor_name || 'Conductor';
        
        const avatarEl = document.getElementById('t-driver-avatar-container');
        if (order.repartidor_avatar) {
            const baseUrl = '<?= esc(delivery_app_url()) ?>/';
            avatarEl.innerHTML = `<img src="${baseUrl}${order.repartidor_avatar}" alt="Avatar">`;
        } else {
            avatarEl.innerHTML = '🛵';
        }

        const phone = order.repartidor_phone || '';
        let cleanPhone = phone.replace(/[^0-9]/g, '');
        if (cleanPhone.startsWith('0')) cleanPhone = '595' + cleanPhone.substring(1);
        else if (cleanPhone !== '' && !cleanPhone.startsWith('595')) cleanPhone = '595' + cleanPhone;

        document.getElementById('t-driver-wa').href = `https://wa.me/${cleanPhone}`;
        document.getElementById('t-driver-call').href = `tel:${phone}`;
        document.getElementById('t-order-id').innerText = `ID #${order.id}`;

        // Formatear Fecha
        const dateObj = order.created_at ? new Date(order.created_at) : new Date();
        const dateStr = dateObj.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
        document.getElementById('t-order-date').innerText = dateStr;

        document.getElementById('t-step-driver').innerText = (order.status === 'aceptado') ? 'Camino al local' : 'En el local';
        document.getElementById('t-local-name').innerText = order.local_name || 'Local / Comercio';
        document.getElementById('t-step-local').innerText = (order.status === 'repartidor_en_local') ? 'Retirando pedido' : 'Esperando';

        document.getElementById('tracking-sheet-modal').style.display = 'flex';

        // Inicializar mapa de seguimiento
        setTimeout(() => {
            mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
            
            const driverLng = parseFloat(order.repartidor_lng || order.local_lng - 0.005);
            const driverLat = parseFloat(order.repartidor_lat || order.local_lat - 0.005);
            const localLng = parseFloat(order.local_lng);
            const localLat = parseFloat(order.local_lat);

            if (trackingSheetMap) {
                trackingSheetMap.remove();
                trackingSheetMap = null;
            }

            trackingSheetMap = new mapboxgl.Map({
                container: 'tracking-map-container',
                style: 'mapbox://styles/mapbox/streets-v12',
                center: [(driverLng + localLng) / 2, (driverLat + localLat) / 2],
                zoom: 13
            });

            trackingSheetMap.on('load', async () => {
                trackingSheetMap.resize();

                // Marcador Conductor (Avatar Foto de Perfil)
                const driverPin = document.createElement('div');
                let avatarContent = '';
                if (order.repartidor_avatar) {
                    const baseUrl = '<?= esc(delivery_app_url()) ?>/';
                    avatarContent = `<img src="${baseUrl}${order.repartidor_avatar}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
                } else {
                    avatarContent = `<span style="font-size: 20px;">🛵</span>`;
                }

                driverPin.innerHTML = `
                    <div style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 18px rgba(37, 99, 235, 0.45); border: 2.5px solid #ffffff; overflow: hidden;">
                        ${avatarContent}
                    </div>
                `;
                trackingDriverMarker = new mapboxgl.Marker(driverPin).setLngLat([driverLng, driverLat]).addTo(trackingSheetMap);

                // Marcador Local (Verde)
                new mapboxgl.Marker({ color: '#10b981' }).setLngLat([localLng, localLat]).addTo(trackingSheetMap);

                // Ajustar encuadre priorizando el 60% superior del mapa sobre el BottomSheet inferior
                const bounds = new mapboxgl.LngLatBounds()
                    .extend([driverLng, driverLat])
                    .extend([localLng, localLat]);
                trackingSheetMap.fitBounds(bounds, { padding: { top: 70, bottom: 280, left: 50, right: 50 }, maxZoom: 15 });

                // Polling en tiempo real cada 3 segundos para mover suavemente el marcador del conductor
                trackingLiveInterval = setInterval(async () => {
                    try {
                        const resp = await fetch(`api_get_order_live_location.php?order_id=${order.id}`);
                        const res = await resp.json();
                        if (res.success && res.driver_lat && res.driver_lng && trackingDriverMarker) {
                            const liveLng = parseFloat(res.driver_lng);
                            const liveLat = parseFloat(res.driver_lat);
                            trackingDriverMarker.setLngLat([liveLng, liveLat]);

                            if (res.status) {
                                document.getElementById('t-step-driver').innerText = (res.status === 'aceptado') ? 'Camino al local' : 'En el local';
                                document.getElementById('t-step-local').innerText = (res.status === 'repartidor_en_local') ? 'Retirando pedido' : 'Esperando';
                            }
                        }
                    } catch (e) {}
                }, 3000);
            });
        }, 150);
    }

    function closeTrackingSheetModal() {
        if (trackingLiveInterval) {
            clearInterval(trackingLiveInterval);
            trackingLiveInterval = null;
        }
        document.getElementById('tracking-sheet-modal').style.display = 'none';
        if (trackingSheetMap) {
            trackingSheetMap.remove();
            trackingSheetMap = null;
        }
        trackingDriverMarker = null;
    }

    // Controlador de Arrastre Deslizable (BottomSheet Drag Controller)
    document.addEventListener("DOMContentLoaded", () => {
        const sheet = document.querySelector('.tracking-bottom-sheet');
        const handle = document.querySelector('.sheet-drag-handle');
        if (!sheet || !handle) return;

        let startY = 0;
        let startHeight = 0;
        let isDragging = false;

        const onTouchStart = (e) => {
            isDragging = true;
            startY = e.touches ? e.touches[0].clientY : e.clientY;
            startHeight = sheet.getBoundingClientRect().height;
            sheet.style.transition = 'none';
        };

        const onTouchMove = (e) => {
            if (!isDragging) return;
            const currentY = e.touches ? e.touches[0].clientY : e.clientY;
            const deltaY = startY - currentY;
            const newHeight = startHeight + deltaY;

            if (newHeight >= 180 && newHeight <= window.innerHeight * 0.78) {
                sheet.style.height = `${newHeight}px`;
            }
        };

        const onTouchEnd = (e) => {
            if (!isDragging) return;
            isDragging = false;
            sheet.style.transition = 'height 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
            const currentHeight = sheet.getBoundingClientRect().height;
            
            if (currentHeight > window.innerHeight * 0.52) {
                sheet.classList.add('expanded');
                sheet.style.height = '68vh';
            } else if (currentHeight < 220) {
                closeTrackingSheetModal();
            } else {
                sheet.classList.remove('expanded');
                sheet.style.height = '42vh';
            }
        };

        handle.addEventListener('touchstart', onTouchStart, { passive: true });
        handle.addEventListener('touchmove', onTouchMove, { passive: true });
        handle.addEventListener('touchend', onTouchEnd);

        handle.addEventListener('mousedown', onTouchStart);
        window.addEventListener('mousemove', onTouchMove);
        window.addEventListener('mouseup', onTouchEnd);

        handle.addEventListener('click', () => {
            sheet.classList.toggle('expanded');
            sheet.style.height = sheet.classList.contains('expanded') ? '68vh' : '42vh';
        });
    });
</script>

<div id="tracking-sheet-modal" class="tracking-modal-overlay" style="display: none;">
    <!-- ENCABEZADO SUPERPUESTO (HEADER OVERLAY) -->
    <div class="tracking-header-overlay">
        <button type="button" onclick="closeTrackingSheetModal()" class="tracking-back-btn" title="Volver a entregas" aria-label="Volver a entregas"></button>
    </div>

    <!-- MAPA INTERACTIVO (MITAD SUPERIOR - 52vh) -->
    <div id="tracking-map-container" class="tracking-map-view"></div>

    <!-- TARJETA INFERIOR DE INFORMACIÓN (BOTTOM SHEET - 48vh) -->
    <div class="tracking-bottom-sheet">
        <div class="sheet-drag-handle"></div>

        <!-- SECCIÓN PERFIL DEL REPARTIDOR -->
        <div class="driver-profile-header">
            <div class="driver-profile-info">
                <div class="driver-avatar-circle" id="t-driver-avatar-container">
                    🛵
                </div>
                <div class="driver-name-block">
                    <b class="driver-name-title">
                        <span id="t-driver-name">Conductor</span>
                        <span class="verified-badge-mini" title="Conductor Verificado" style="background: var(--primary, #2563eb); color: #fff; width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 800; flex-shrink: 0; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35); border: 1.5px solid #ffffff;">✓</span>
                    </b>
                    <span class="driver-status-subtitle">
                        <span class="live-pulse-dot"></span> Asignado
                    </span>
                </div>
            </div>
        </div>

        <hr class="sheet-divider">

        <!-- SECCIÓN DETALLES DEL ENVÍO -->
        <div class="shipment-details-block">
            <!-- LÍNEA SUPERIOR (ID + FECHA) -->
            <div class="shipment-meta-row">
                <span class="shipment-id-badge" id="t-order-id">ID #--</span>
                <span class="shipment-date" id="t-order-date">16 Sep, 2025</span>
            </div>

            <!-- TIMELINE VERTICAL (RUTA) -->
            <div class="timeline-route">
                <!-- ORIGEN: CONDUCTOR -->
                <div class="timeline-item">
                    <div class="timeline-marker marker-primary">
                        <div style="width: 7px; height: 7px; background: #ffffff; border-radius: 50%;"></div>
                    </div>
                    <div class="timeline-content">
                        <small class="timeline-sub">Conductor</small>
                        <b class="timeline-title" id="t-step-driver">Camino al local</b>
                    </div>
                </div>

                <!-- CONECTOR VERTICAL PUNTEADO -->
                <div class="timeline-connector"></div>

                <!-- DESTINO: LOCAL -->
                <div class="timeline-item" style="justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 14px; min-width: 0;">
                        <div class="timeline-marker marker-green animated-pin-marker" title="Ubicación del Local">
                            <svg style="width:13px; height:13px; color: #ffffff;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                        </div>
                        <div class="timeline-content">
                            <small class="timeline-sub" id="t-local-name">Local / Comercio</small>
                            <b class="timeline-title" id="t-step-local">Esperando</b>
                        </div>
                    </div>

                    <!-- CÁPSULA DE CONTACTO (MISMA LÍNEA DE LOCAL / COMERCIO) -->
                    <div class="driver-contact-capsule">
                        <a id="t-driver-wa" href="#" target="_blank" class="wa-link-btn" title="Enviar WhatsApp">
                            <svg style="width:20px; height:20px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                        </a>
                        <a id="t-driver-call" href="#" class="call-link-btn" title="Llamar">
                            <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.387a12.035 12.035 0 01-7.108-7.108c-.155-.44.01-1.275.387-1.556l1.293-.97c.362-.27.528-.733.417-1.173L6.763 2.074a1.125 1.125 0 00-1.091-.852H4.372A2.25 2.25 0 002.122 3.472v1.028z" /></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isDriver && !empty($activeRows)): ?>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            
            const driverCoords = [<?= $driverLng ?>, <?= $driverLat ?>];
            
            // Construir la lista de paradas en secuencia óptima
            const waypoints = [];
            
            // Punto de partida: Repartidor
            waypoints.push({
                lng: <?= $driverLng ?>,
                lat: <?= $driverLat ?>,
                label: '🛵',
                title: 'Tu Ubicación'
            });

            <?php foreach ($activeRows as $idx => $row): 
                $status = $row['status'];
                $isPickup = in_array($status, ['aceptado', 'repartidor_en_local']);
                $lbl = ($idx + 1);
                if ($isPickup):
            ?>
                waypoints.push({
                    lng: <?= (float)$row['local_lng'] ?>,
                    lat: <?= (float)$row['local_lat'] ?>,
                    label: '🏢',
                    title: 'Parada <?= $lbl ?>: Retirar en <?= esc(addslashes($row['local_name'])) ?>'
                });
            <?php else: ?>
                waypoints.push({
                    lng: <?= (float)$row['delivery_longitude'] ?>,
                    lat:  <?= (float)$row['delivery_latitude'] ?>,
                    label: '📍',
                    title: 'Parada <?= $lbl ?>: Entregar a <?= esc(addslashes($row['customer_name'] ?: 'Cliente')) ?>'
                });
            <?php endif; endforeach; ?>

            // Inicializar mapa si el contenedor existe
            const mapContainer = document.getElementById('driver-route-map');
            if (mapContainer) {
                const map = new mapboxgl.Map({
                    container: 'driver-route-map',
                    style: 'mapbox://styles/mapbox/streets-v12',
                    center: driverCoords,
                    zoom: 13
                });

                map.on('load', async () => {
                    const bounds = new mapboxgl.LngLatBounds();
                    
                    waypoints.forEach((wp, index) => {
                        const el = document.createElement('div');
                        el.style.width = '28px';
                        el.style.height = '28px';
                        el.style.borderRadius = '50%';
                        el.style.display = 'flex';
                        el.style.alignItems = 'center';
                        el.style.justifyContent = 'center';
                        el.style.boxShadow = '0 4px 10px rgba(0,0,0,0.15)';
                        el.style.border = '2px solid #ffffff';
                        
                        if (index === 0) {
                            el.style.background = '#2563eb'; // Azul repartidor
                            el.innerHTML = '🛵';
                        } else {
                            el.style.background = wp.label === '🏢' ? '#f59e0b' : '#10b981'; // Naranja / Verde
                            el.innerHTML = `<b style="color:#ffffff; font-size:11px;">${index}</b>`;
                        }
                        
                        new mapboxgl.Marker({ element: el })
                            .setLngLat([wp.lng, wp.lat])
                            .setPopup(new mapboxgl.Popup({ offset: 10 }).setHTML(`<div style="padding: 4px; font-family: sans-serif; font-size:12px; font-weight:700;">${wp.title}</div>`))
                            .addTo(map);
                            
                        bounds.extend([wp.lng, wp.lat]);
                    });

                    map.fitBounds(bounds, { padding: 40 });

                    if (waypoints.length > 1) {
                        const coordsString = waypoints.map(wp => `${wp.lng},${wp.lat}`).join(';');
                        const url = `https://api.mapbox.com/directions/v5/mapbox/driving/${coordsString}?geometries=geojson&access_token=${mapboxgl.accessToken}`;
                        
                        try {
                            const resp = await fetch(url);
                            const json = await resp.json();
                            if (json.routes && json.routes[0]) {
                                const route = json.routes[0];
                                const km = (route.distance / 1000).toFixed(1);
                                const mins = Math.round(route.duration / 60);
                                
                                const distDurEl = document.getElementById('route-distance-duration');
                                if (distDurEl) distDurEl.innerText = `${km} km · ${mins} min`;

                                map.addSource('route', {
                                    'type': 'geojson',
                                    'data': {
                                        'type': 'Feature',
                                        'properties': {},
                                        'geometry': route.geometry
                                    }
                                });

                                map.addLayer({
                                    'id': 'route',
                                    'type': 'line',
                                    'source': 'route',
                                    'layout': {
                                        'line-join': 'round',
                                        'line-cap': 'round'
                                    },
                                    'paint': {
                                        'line-color': '#2563eb',
                                        'line-width': 4,
                                        'line-opacity': 0.8
                                    }
                                });
                            }
                        } catch (e) {
                            console.error(e);
                            const distDurEl = document.getElementById('route-distance-duration');
                            if (distDurEl) distDurEl.innerText = 'Ruta indisponible';
                        }
                    } else {
                        const distDurEl = document.getElementById('route-distance-duration');
                        if (distDurEl) distDurEl.innerText = '';
                    }
                });
            }
        });
    </script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
