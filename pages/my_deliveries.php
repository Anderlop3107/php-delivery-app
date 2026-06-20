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
    
    .pending-header { margin-bottom: 24px; padding: 10px 0; }
    .pending-header h1 { font-size: 26px; font-weight: 800; color: var(--text); }
    .pending-header p { font-size: 14px; font-weight: 600; color: var(--muted); }
    
    .status-card { 
        background: #fff; 
        border-radius: var(--card-radius); 
        padding: 24px; 
        margin-bottom: 20px; 
        box-shadow: var(--shadow);
        border: 1px solid rgba(0,0,0,0.01);
        transition: transform 0.2s, box-shadow 0.2s; 
        overflow: hidden; 
    }
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
        background: var(--primary-soft);
        color: var(--primary);
    }
    
    .customer-info h4 { margin: 0; font-size: 19px; font-weight: 800; color: var(--text); }
    .customer-info p { margin: 6px 0 0; font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; font-weight: 500; }
    .customer-info svg { color: var(--primary); opacity: 0.7; }
    
    .delivery-progress-bento { display: flex; gap: 6px; margin-top: 24px; height: 6px; }
    .progress-bar-segment { flex: 1; background: #f1f5f9; border-radius: 10px; transition: background 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    .progress-bar-segment.active { background: var(--primary); box-shadow: 0 0 10px rgba(37, 99, 235, 0.2); }
    .progress-bar-segment.completed { background: #10b981; }

    .step-text-display { margin-top: 12px; font-size: 13px; font-weight: 800; color: var(--primary); text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }

    .person-box { 
        display: flex; align-items: center; gap: 12px; margin-top: 20px; padding: 16px; 
        background: #f8fafc; border-radius: 18px; border: 1px solid rgba(0,0,0,0.02);
    }
    .person-avatar { 
        width: 44px; height: 44px; border-radius: 14px; background: var(--primary); 
        display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 16px; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
    }
    .person-details { flex: 1; }
    .person-details b { display: block; font-size: 15px; font-weight: 700; color: var(--text); }
    .person-details span { font-size: 11px; color: var(--muted); font-weight: 600; }
    
    .wa-link-btn { 
        background: #25d366; color: #fff; width: 40px; height: 40px; border-radius: 12px; 
        display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.2);
    }
    
    .driver-actions { margin-top: 20px; display: grid; gap: 12px; }
    .btn-action-main { background: var(--primary); color: #fff; border: none; border-radius: 16px; padding: 16px; font-weight: 800; font-size: 14px; cursor: pointer; box-shadow: 0 8px 20px rgba(37, 99, 235, 0.15); }
    .btn-action-gps { background: #1e293b; color: #fff; border: none; border-radius: 16px; padding: 16px; font-weight: 700; font-size: 14px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
</style>

<div class="pending-header">
    <p class="muted" style="margin-bottom: 4px; text-transform: uppercase; letter-spacing: 1px;">En Tiempo Real</p>
    <h1>Seguimiento de Entrega</h1>
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
        ?>
            <div class="status-card" id="card-<?= $row['id'] ?>">
                <div class="status-top">
                    <span class="order-id">ID #<?= $row['id'] ?></span>
                    <span class="status-pill-tech">
                        <?= $current['label'] ?>
                    </span>
                </div>

                <!-- BLOQUE DEL CLIENTE -->
                <div class="customer-info <?= $ocultarCliente ? 'oculto' : '' ?>" id="info-cliente-<?= $row['id'] ?>">
                    <h4><?= esc($row['customer_name'] ?: 'Cliente') ?></h4>
                    <p>
                        <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <?= esc($row['delivery_address']) ?>
                    </p>
                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $row['customer_phone'] ?? '') ?>" class="wa-link-btn">💬</a>
                        <a href="tel:<?= $row['customer_phone'] ?>" class="wa-link-btn" style="background:#3b82f6;">📞</a>
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
                    <div class="person-avatar">🏠</div>
                    <div class="person-details">
                        <b><?= esc($isLocal ? ($row['repartidor_name'] ?: 'Buscando...') : $row['local_name']) ?></b>
                        <span><?= $isLocal ? 'Conductor asignado' : 'Punto de retiro' ?></span>
                    </div>
                    <?php 
                        $phone = $isLocal ? $row['repartidor_phone'] : $row['local_phone'];
                        if ($phone): 
                    ?>
                        <div style="display: flex; gap: 10px;">
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $phone) ?>" class="wa-link-btn">💬</a>
                            <a href="tel:<?= $phone ?>" class="wa-link-btn" style="background:#3b82f6;">📞</a>
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
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_camino_al_cliente')" class="btn-action-main" style="background:#1e293b;">📦 Pedido Recibido (Confirmar Retiro)</button>
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
                    new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3').play();
                    setTimeout(() => { window.location.reload(); }, 1500);
                } else {
                    window.location.reload();
                }
            } else { alert(res.message); }
        } catch (e) { console.error(e); }
    }

    // Refresh solo si no hay interacción y monitoreo de estados (Local)
    <?php if($isLocal): ?>
    (function() {
        const currentStatuses = {
            <?php foreach ($rows as $row): ?>
                "<?= $row['id'] ?>": "<?= esc($row['status']) ?>",
            <?php endforeach; ?>
        };

        const storageKey = 'local_delivery_statuses';
        const prevStatusesStr = sessionStorage.getItem(storageKey);
        let playArrivalSound = false;
        let playCompletedSound = false;
        let playAssignedSound = false;

        if (prevStatusesStr) {
            try {
                const prevStatuses = JSON.parse(prevStatusesStr);
                for (const orderId in currentStatuses) {
                    const currentStatus = currentStatuses[orderId];
                    const prevStatus = prevStatuses[orderId];
                    
                    // Si cambia a "repartidor_en_local" desde otro estado
                    if (currentStatus === 'repartidor_en_local' && prevStatus !== 'repartidor_en_local') {
                        playArrivalSound = true;
                    }

                    // Si cambia a "entregado" desde otro estado
                    if (currentStatus === 'entregado' && prevStatus !== 'entregado') {
                        playCompletedSound = true;
                    }

                    // Si cambia a "aceptado" (delivery asignado) desde pendiente o si es nuevo y ya está aceptado
                    if (currentStatus === 'aceptado' && (prevStatus === 'pendiente' || !prevStatus)) {
                        playAssignedSound = true;
                    }
                }
            } catch (e) {
                console.error("Error parsing stored statuses:", e);
            }
        }

        // Guardar estados actuales para el próximo ciclo
        sessionStorage.setItem(storageKey, JSON.stringify(currentStatuses));

        if (playArrivalSound) {
            const arrivalAudio = new Audio('<?= esc(delivery_app_url("uploads/sounds/delivery_arrived.mp3")) ?>');
            arrivalAudio.play().catch(err => {
                console.log("Audio playback prevented by browser autoplay policy:", err);
            });
        } else if (playCompletedSound) {
            const completedAudio = new Audio('<?= esc(delivery_app_url("uploads/sounds/delivery_completed.mp3")) ?>');
            completedAudio.play().catch(err => {
                console.log("Audio playback prevented by browser autoplay policy:", err);
            });
        } else if (playAssignedSound) {
            const assignedAudio = new Audio('<?= esc(delivery_app_url("uploads/sounds/delivery_assigned.mp3")) ?>');
            assignedAudio.play().catch(err => {
                console.log("Audio playback prevented by browser autoplay policy:", err);
            });
        }

        setInterval(() => { location.reload(); }, 10000);
    })();
    <?php endif; ?>
</script>

<?php require __DIR__ . '/_footer.php'; ?>
