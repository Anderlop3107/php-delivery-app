<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
$isLocal = ($user['role'] === 'local');
$isDriver = ($user['role'] === 'repartidor');

if ($isLocal) {
    $rows = app_all(
        "SELECT d.*, r.name AS repartidor_name, r.phone AS repartidor_phone
         FROM deliveries d
         LEFT JOIN users r ON r.id = d.repartidor_user_id
         WHERE d.local_user_id = ? AND d.status != 'entregado'
         ORDER BY d.created_at DESC",
        'i',
        [(int) $user['id']]
    );
} else {
    $rows = app_all(
        "SELECT d.*, u.business_name as local_name, u.phone as local_phone, u.address as local_address
         FROM deliveries d
         JOIN users u ON d.local_user_id = u.id
         WHERE d.repartidor_user_id = ? AND d.status != 'entregado'
         ORDER BY d.created_at DESC",
        'i',
        [(int) $user['id']]
    );
}

$title = 'Pedidos en curso';
require __DIR__ . '/_header.php';
?>

<style>
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
        position: relative;
    }
    .status-card:active { transform: scale(0.99); }
    .status-card.delivered-anim { transform: scale(0.9); opacity: 0; height: 0; margin-bottom: 0; padding: 0; border: none; }
    
    .status-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .order-id { font-weight: 700; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
    
    /* Tech Status Pill */
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
    .status-pill-tech.urgent { background: #fee2e2; color: var(--danger); }
    
    .customer-info h4 { margin: 0; font-size: 19px; font-weight: 800; color: var(--text); }
    .customer-info p { margin: 6px 0 0; font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; font-weight: 500; }
    .customer-info svg { color: var(--primary); opacity: 0.7; }
    
    /* Bento Horizontal Progress */
    .delivery-progress-bento { 
        display: flex; 
        gap: 6px; 
        margin-top: 24px; 
        height: 6px; 
    }
    .progress-bar-segment { 
        flex: 1; 
        background: #f1f5f9; 
        border-radius: 10px; 
        transition: background 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .progress-bar-segment.active { background: var(--primary); box-shadow: 0 0 10px rgba(37, 99, 235, 0.2); }
    .progress-bar-segment.completed { background: #10b981; }

    .step-text-display { margin-top: 12px; font-size: 12px; font-weight: 700; color: var(--text); text-align: center; }

    /* Person/Driver Box (Glassmorphism inspired) */
    .person-box { 
        display: flex; 
        align-items: center; 
        gap: 12px; 
        margin-top: 20px; 
        padding: 16px; 
        background: #f8fafc; 
        border-radius: 18px; 
        border: 1px solid rgba(0,0,0,0.02);
    }
    .person-avatar { 
        width: 44px; 
        height: 44px; 
        border-radius: 14px; 
        background: var(--primary); 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        color: #fff; 
        font-weight: 800; 
        font-size: 16px; 
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
    }
    .person-details { flex: 1; }
    .person-details b { display: block; font-size: 15px; font-weight: 700; color: var(--text); }
    .person-details span { font-size: 11px; color: var(--muted); font-weight: 600; }
    
    .wa-link-btn { 
        background: #25d366; 
        color: #fff; 
        width: 40px; 
        height: 40px; 
        border-radius: 12px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        text-decoration: none; 
        box-shadow: 0 4px 12px rgba(37, 211, 102, 0.2);
        transition: transform 0.2s;
    }
    .wa-link-btn:active { transform: scale(0.9); }
    
    .driver-actions { margin-top: 20px; display: grid; gap: 12px; }
    
    .btn-gps { 
        background: #ffffff; 
        color: var(--text); 
        border: 1px solid var(--border); 
        box-shadow: none; 
        font-size: 14px; 
        font-weight: 600; 
    }
    .btn-gps:active { background: #f8fafc; }
</style>

<div class="pending-header">
    <p class="muted" style="margin-bottom: 4px; text-transform: uppercase; letter-spacing: 1px;">Seguimiento</p>
    <h1>Pedidos en curso</h1>
</div>

<div class="pending-list" id="orders-list">
    <?php if (empty($rows)): ?>
        <div style="text-align: center; padding: 80px 20px;">
            <div style="background: var(--primary-soft); width: 100px; height: 100px; border-radius: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px;">
                <svg style="width: 44px; height: 44px; color: var(--primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h3 style="color: var(--text);">Todo al día</h3>
            <p class="muted">No tienes pedidos activos en curso.</p>
            <?php if($isLocal): ?><a href="create_delivery.php" class="btn" style="margin-top: 24px; padding: 14px 30px;">Nuevo Envío</a><?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($rows as $row): 
            $s = $row['status'];
            $steps = [
                ['key' => 'pendiente', 'label' => 'Recibido'],
                ['key' => 'aceptado', 'label' => 'Asignado'],
                ['key' => 'en_camino_al_local', 'label' => 'Camino Local'],
                ['key' => 'repartidor_en_local', 'label' => 'En Local'],
                ['key' => 'en_camino_al_cliente', 'label' => 'Camino Cliente'],
            ];
            
            $current_idx = 0;
            foreach($steps as $i => $step) {
                if ($s === $step['key']) $current_idx = $i;
            }
            $current_label = $steps[$current_idx]['label'] ?? 'Procesando';
        ?>
            <div class="status-card" id="card-<?= $row['id'] ?>">
                <div class="status-top">
                    <span class="order-id">ID #<?= $row['id'] ?></span>
                    <span class="status-pill-tech" id="badge-<?= $row['id'] ?>">
                        <?= str_replace('_', ' ', $s) ?>
                    </span>
                </div>

                <div class="customer-info">
                    <h4><?= esc($row['customer_name'] ?: 'Cliente') ?></h4>
                    <p>
                        <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <?= esc($row['delivery_address']) ?>
                    </p>
                </div>

                <div class="delivery-progress-bento">
                    <?php foreach($steps as $i => $step): 
                        $active = ($current_idx == $i);
                        $completed = ($current_idx > $i);
                    ?>
                        <div class="progress-bar-segment <?= ($active ? 'active' : '') ?> <?= ($completed ? 'completed' : '') ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="step-text-display">
                    <span style="color: var(--primary);"><?= $current_label ?></span>
                </div>

                <?php if ($isLocal && $row['repartidor_user_id']): ?>
                    <div class="person-box">
                        <div class="person-avatar"><?= substr($row['repartidor_name'], 0, 1) ?></div>
                        <div class="person-details">
                            <b><?= esc($row['repartidor_name']) ?></b>
                            <span>Conductor asignado</span>
                        </div>
                        <?php if ($row['repartidor_phone']): ?>
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $row['repartidor_phone']) ?>" class="wa-link-btn">
                                <svg style="width: 20px; height: 20px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isDriver): ?>
                    <div class="person-box">
                        <div class="person-avatar">🏠</div>
                        <div class="person-details">
                            <b><?= esc($row['local_name']) ?></b>
                            <span>Local de retiro</span>
                        </div>
                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $row['local_phone']) ?>" class="wa-link-btn">
                            <svg style="width: 20px; height: 20px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                        </a>
                    </div>

                    <div class="driver-actions">
                        <?php if ($s === 'aceptado'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_camino_al_local')" class="btn">🚀 Ir al Local</button>
                        <?php elseif ($s === 'en_camino_al_local'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'repartidor_en_local')" class="btn" style="background:#1e293b;">📍 Ya llegué al Local</button>
                        <?php elseif ($s === 'repartidor_en_local'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_camino_al_cliente')" class="btn">🛵 Ir al Cliente</button>
                        <?php elseif ($s === 'en_camino_al_cliente'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'entregado')" class="btn" style="background:#10b981; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.2);">✅ ¡Entregado!</button>
                        <?php endif; ?>
                        
                        <a href="https://www.google.com/maps/search/?api=1&query=<?= $row['delivery_latitude'] ?>,<?= $row['delivery_longitude'] ?>" target="_blank" class="btn btn-gps">🗺️ Abrir GPS</a>
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
                    const card = document.getElementById('card-' + orderId);
                    const badge = document.getElementById('badge-' + orderId);
                    badge.innerText = '¡ENTREGADO!';
                    badge.style.background = '#dcfce7';
                    badge.style.color = '#166534';
                    
                    new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3').play();

                    setTimeout(() => {
                        card.classList.add('delivered-anim');
                        setTimeout(() => {
                            card.remove();
                            if (document.querySelectorAll('.status-card').length === 0) {
                                window.location.reload();
                            }
                        }, 500);
                    }, 4000);
                } else {
                    window.location.reload();
                }
            } else {
                alert(res.message);
            }
        } catch (e) {
            console.error(e);
        }
    }

    <?php if ($isLocal): ?>
    setInterval(() => {
        // Podríamos refrescar datos vía AJAX para Bento UI real-time
    }, 15000);
    <?php endif; ?>
</script>

<?php require __DIR__ . '/_footer.php'; ?>
