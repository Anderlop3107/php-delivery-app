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
    .pending-header { margin-bottom: 25px; }
    .pending-header h1 { font-size: 24px; font-weight: 800; color: #1e293b; margin-bottom: 5px; }
    
    .status-card { background: #fff; border-radius: 24px; padding: 20px; margin-bottom: 16px; border: 1px solid #f1f5f9; box-shadow: 0 4px 12px rgba(0,0,0,0.03); transition: all 0.5s ease; overflow: hidden; }
    .status-card.delivered-anim { transform: scale(0.9); opacity: 0; height: 0; margin-bottom: 0; padding: 0; border: none; }
    
    .status-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
    .order-id { font-weight: 800; color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    
    .customer-info h4 { margin: 0; font-size: 18px; color: #1e293b; }
    .customer-info p { margin: 4px 0 0; font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 5px; }
    
    .delivery-status-box { background: #f8fafc; border-radius: 16px; padding: 15px; margin-top: 15px; }
    .status-step { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
    .status-step:last-child { margin-bottom: 0; }
    .step-dot { width: 10px; height: 10px; border-radius: 50%; background: #e2e8f0; }
    .step-dot.active { background: #0C3A5B; box-shadow: 0 0 0 4px rgba(12, 58, 91, 0.1); }
    .step-dot.completed { background: #10b981; }
    .step-text { font-size: 13px; font-weight: 600; color: #94a3b8; }
    .step-text.active { color: #0C3A5B; }
    .step-text.completed { color: #10b981; }

    .person-box { display: flex; align-items: center; gap: 12px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #f1f5f9; }
    .person-avatar { width: 40px; height: 40px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1; font-weight: 800; }
    .person-details { flex: 1; }
    .person-details b { display: block; font-size: 14px; color: #1e293b; }
    .person-details span { font-size: 12px; color: #64748b; }
    
    .wa-link { background: #25d366; color: #fff; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; }
    
    .driver-actions { margin-top: 15px; display: grid; gap: 10px; }
    
    .btn-delivered { background: #10b981; }
    .btn-arrived { background: #0C3A5B; }
</style>

<div class="pending-header">
    <h1><?= ($isLocal ? 'Entregas en curso' : 'Mis Pedidos') ?></h1>
    <p class="muted">Sincronización en tiempo real con el repartidor.</p>
</div>

<div class="pending-list" id="orders-list">
    <?php if (empty($rows)): ?>
        <div style="text-align: center; padding: 60px 20px;">
            <div style="background: #f1f5f9; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                <svg style="width: 40px; height: 40px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h3>Sin actividad</h3>
            <p class="muted">No hay pedidos activos en este momento.</p>
            <?php if($isLocal): ?><a href="create_delivery.php" class="btn" style="margin-top: 15px;">Crear Pedido</a><?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($rows as $row): 
            $s = $row['status'];
            $steps = [
                ['key' => 'pendiente', 'label' => 'Pedido Recibido', 'text' => 'Esperando repartidor...'],
                ['key' => 'aceptado', 'label' => 'Repartidor Asignado', 'text' => 'El repartidor aceptó el pedido'],
                ['key' => 'en_camino_al_local', 'label' => 'En camino al local', 'text' => 'El repartidor va hacia tu local'],
                ['key' => 'repartidor_en_local', 'label' => 'Repartidor en el local', 'text' => '¡El repartidor ha llegado!'],
                ['key' => 'en_camino_al_cliente', 'label' => 'En camino al cliente', 'text' => 'El pedido va al destino final'],
            ];
            
            // Determinar progreso
            $current_idx = 0;
            foreach($steps as $i => $step) {
                if ($s === $step['key']) $current_idx = $i;
            }
            if ($s === 'entregado') $current_idx = 99;
        ?>
            <div class="status-card" id="card-<?= $row['id'] ?>">
                <div class="status-top">
                    <span class="order-id">Pedido #<?= $row['id'] ?></span>
                    <span class="status-badge status-<?= ($s == 'pendiente' ? 'pendiente' : 'proceso') ?>" id="badge-<?= $row['id'] ?>">
                        <?= delivery_status_text($s) ?>
                    </span>
                </div>

                <div class="customer-info">
                    <h4><?= esc($row['customer_name'] ?: 'Cliente') ?></h4>
                    <p>
                        <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg>
                        <?= esc($row['delivery_address']) ?>
                    </p>
                </div>

                <div class="delivery-status-box">
                    <?php foreach($steps as $i => $step): 
                        $active = ($current_idx == $i);
                        $completed = ($current_idx > $i);
                    ?>
                        <div class="status-step">
                            <div class="step-dot <?= ($active ? 'active' : '') ?> <?= ($completed ? 'completed' : '') ?>"></div>
                            <span class="step-text <?= ($active ? 'active' : '') ?> <?= ($completed ? 'completed' : '') ?>">
                                <?= $step['label'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($isLocal && $row['repartidor_user_id']): ?>
                    <div class="person-box">
                        <div class="person-avatar"><?= substr($row['repartidor_name'], 0, 1) ?></div>
                        <div class="person-details">
                            <b><?= esc($row['repartidor_name']) ?></b>
                            <span>Repartidor asignado</span>
                        </div>
                        <?php if ($row['repartidor_phone']): ?>
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $row['repartidor_phone']) ?>" class="wa-link">
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
                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $row['local_phone']) ?>" class="wa-link">
                            <svg style="width: 20px; height: 20px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                        </a>
                    </div>

                    <div class="driver-actions">
                        <?php if ($s === 'aceptado'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_camino_al_local')" class="btn">🚀 Ir al Local</button>
                        <?php elseif ($s === 'en_camino_al_local'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'repartidor_en_local')" class="btn btn-arrived">📍 Ya llegué al Local</button>
                        <?php elseif ($s === 'repartidor_en_local'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'en_camino_al_cliente')" class="btn">🛵 Ir al Cliente</button>
                        <?php elseif ($s === 'en_camino_al_cliente'): ?>
                            <button onclick="updateStatus(<?= $row['id'] ?>, 'entregado')" class="btn btn-delivered">✅ ¡Entregado!</button>
                        <?php endif; ?>
                        
                        <a href="https://www.google.com/maps/search/?api=1&query=<?= $row['delivery_latitude'] ?>,<?= $row['delivery_longitude'] ?>" target="_blank" class="btn btn-secondary">🗺️ Abrir GPS</a>
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
                    // Animación y espera de 4 segundos
                    const card = document.getElementById('card-' + orderId);
                    const badge = document.getElementById('badge-' + orderId);
                    badge.innerText = '¡PEDIDO ENTREGADO!';
                    badge.className = 'status-badge status-entregado';
                    
                    // Sonido
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

    // Pooling para el local (opcional, refresca cada 10s)
    <?php if ($isLocal): ?>
    setInterval(() => {
        // window.location.reload(); 
        // Idealmente usaríamos AJAX aquí para no interrumpir al usuario
    }, 10000);
    <?php endif; ?>
</script>

<?php require __DIR__ . '/_footer.php'; ?>
