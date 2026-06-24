<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
$isLocal = ($user['role'] === 'local');
$isDriver = ($user['role'] === 'repartidor');

// Consultas optimizadas con coordenadas del local para GPS
if ($isLocal) {
    // Incluir entregas que cambiaron de estado recientemente (último minuto) para poder notificar por audio en tiempo real
    $rows = app_all(
        "SELECT d.*, r.name AS repartidor_name, r.phone AS repartidor_phone, u_local.latitude as local_lat, u_local.longitude as local_lng
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

$title = 'Pedidos en curso';
require __DIR__ . '/_header.php';
?>

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
        border-radius: var(--card-radius); 
        padding: 24px; 
        margin-bottom: 20px; 
        box-shadow: var(--shadow);
        border: 1px solid rgba(0,0,0,0.01);
        border-left: 6px solid #cbd5e1;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        overflow: hidden; 
    }
    .status-card.state-pendiente { border-left-color: #94a3b8; }
    .status-card.state-local { border-left-color: #f59e0b; }
    .status-card.state-transit { border-left-color: var(--primary); }
    .status-card.state-entregado { border-left-color: #10b981; }
    .status-card.delivered-anim { transform: scale(0.9); opacity: 0; height: 0; margin-bottom: 0; padding: 0; border: none; }
    
    .status-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .order-id { font-weight: 700; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
    
    .status-pill-tech {
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-pill-tech.status-pendiente {
        background: rgba(148, 163, 184, 0.12);
        color: #64748b;
    }
    .status-pill-tech.status-local {
        background: rgba(245, 158, 11, 0.08);
        color: #d97706;
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
        0% { opacity: 0.7; }
        50% { opacity: 1; }
        100% { opacity: 0.7; }
    }
    .progress-bar-segment.active { 
        background: var(--primary); 
        box-shadow: 0 0 12px rgba(37, 99, 235, 0.45); 
        animation: pulse-active-bar 2s infinite ease-in-out;
    }
    .progress-bar-segment.completed { background: #10b981; }

    .step-text-display { margin-top: 12px; font-size: 13px; font-weight: 800; color: var(--primary); text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }

    .person-box { 
        display: flex; align-items: center; gap: 12px; margin-top: 20px; padding: 16px; 
        background: #f8fafc; border-radius: 18px; border: 1px solid rgba(0,0,0,0.02);
    }
    .person-avatar { 
        width: 44px; height: 44px; border-radius: 14px; background: var(--primary); 
        display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 16px; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
        overflow: hidden;
    }
    .person-details { flex: 1; }
    .person-details b { display: block; font-size: 15px; font-weight: 700; color: var(--text); }
    .person-details span { font-size: 11px; color: var(--muted); font-weight: 600; }
    
    .wa-link-btn { 
        background: #25d366; color: #fff; width: 40px; height: 40px; border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; text-decoration: none; 
        box-shadow: 0 4px 12px rgba(37, 211, 102, 0.2); transition: transform 0.2s;
    }
    .wa-link-btn:active { transform: scale(0.9); }
    
    .call-link-btn { 
        background: #3b82f6; color: #fff; width: 40px; height: 40px; border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; text-decoration: none; 
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); transition: transform 0.2s;
    }
    .call-link-btn:active { transform: scale(0.9); }
    
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
</style>

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

            // MAPA DE FLUJO DE 6 ETAPAS
            $flow = [
                'pendiente' => ['label' => 'Pedido Recibido', 'prog' => 1],
                'aceptado' => ['label' => 'Camino al Local', 'prog' => 2],
                'repartidor_en_local' => ['label' => 'En el Local / Retirando', 'prog' => 3],
                'en_camino_al_cliente' => ['label' => 'En camino al Cliente', 'prog' => 4],
                'en_puerta' => ['label' => 'Entregando Pedido', 'prog' => 5],
                'entregado' => ['label' => '¡Pedido Entregado!', 'prog' => 6],
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
                    <span class="order-id">ID #<?= $row['id'] ?></span>
                    <span class="status-pill-tech <?= $statusClass ?>">
                        <?= $current['label'] ?>
                    </span>
                </div>

                <!-- BLOQUE DEL CLIENTE -->
                <div class="customer-info <?= $ocultarCliente ? 'oculto' : '' ?>" id="info-cliente-<?= $row['id'] ?>">
                    <h4 style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <svg style="width:20px; height:20px; color: var(--primary); opacity: 0.85;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span><?= esc($row['customer_name'] ?: 'Cliente') ?></span>
                    </h4>
                    <p>
                        <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <?= esc($row['delivery_address']) ?>
                    </p>
                    <?php if (!empty($row['order_description'])): ?>
                        <p style="margin-top: 6px; font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; font-weight: 500;">
                            <svg style="width:16px; height:16px; color: var(--primary); opacity: 0.7;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            <?= esc($row['order_description']) ?>
                        </p>
                    <?php endif; ?>
                    <div style="display: flex; gap: 10px; margin-top: 15px; justify-content: flex-end;">
                        <?php 
                            $cleanCustPhone = preg_replace('/[^0-9]/', '', $row['customer_phone'] ?? '');
                            if (str_starts_with($cleanCustPhone, '0')) {
                                $cleanCustPhone = '595' . substr($cleanCustPhone, 1);
                            } elseif ($cleanCustPhone !== '' && !str_starts_with($cleanCustPhone, '595')) {
                                $cleanCustPhone = '595' . $cleanCustPhone;
                            }
                        ?>
                        <a href="https://wa.me/<?= $cleanCustPhone ?>" target="_blank" class="wa-link-btn">
                            <svg style="width:22px; height:22px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                        </a>
                        <a href="tel:<?= $row['customer_phone'] ?>" class="call-link-btn">
                            <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                        </a>
                    </div>
                </div>

                <!-- BARRA DE PROGRESO (5 Segmentos) -->
                <div class="delivery-progress-bento">
                    <?php for($i=1; $i<=5; $i++): 
                        $class = '';
                        if ($prog > $i) $class = 'completed';
                        elseif ($prog == $i) $class = 'active';
                    ?>
                        <div class="progress-bar-segment <?= $class ?>"></div>
                    <?php endfor; ?>
                </div>
                <div class="step-text-display">
                    <?= $current['label'] ?>
                </div>

                <!-- BLOQUE DEL LOCAL -->
                <div class="person-box <?= $ocultarLocal ? 'oculto' : '' ?>" id="info-local-<?= $row['id'] ?>">
                    <div class="person-avatar">
                        <?php if (!$isLocal && !empty($row['local_logo'])): ?>
                            <img src="<?= esc(delivery_app_url($row['local_logo'])) ?>" alt="Logo Local" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <?= $isLocal ? '🛵' : '🏠' ?>
                        <?php endif; ?>
                    </div>
                    <div class="person-details">
                        <b><?= esc($isLocal ? ($row['repartidor_name'] ?: 'Buscando...') : $row['local_name']) ?></b>
                        <span><?= $isLocal ? 'Conductor asignado' : 'Punto de retiro' ?></span>
                    </div>
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
                                <svg style="width:22px; height:22px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                            </a>
                            <a href="tel:<?= $phone ?>" class="call-link-btn">
                                <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                            </a>
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
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_camino_al_cliente')" class="btn-action-main">Confirmar Retiro</button>
                        <?php elseif ($s === 'en_camino_al_cliente'): ?>
                            <button onclick="window.open('https://www.google.com/maps/search/?api=1&query=<?= $row['delivery_latitude'] ?>,<?= $row['delivery_longitude'] ?>')" class="btn-action-gps">🗺️ Abrir GPS al Cliente</button>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_puerta')" class="btn-action-main">🏁 Llegué donde el Cliente</button>
                        <?php elseif ($s === 'en_puerta'): ?>
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
                        window.playNotificationSound('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');
                    } else {
                        new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3').play().catch(e => console.log(e));
                    }
                    setTimeout(() => { window.location.reload(); }, 1500);
                } else {
                    window.location.reload();
                }
            } else { alert(res.message); }
        } catch (e) { console.error(e); }
    }


</script>

<?php require __DIR__ . '/_footer.php'; ?>
