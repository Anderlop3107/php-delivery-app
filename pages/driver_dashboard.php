<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['repartidor']);

$user = current_user();

// Obtener pedidos pendientes (sin asignar)
// Incluimos delivery_cost que es lo que el repartidor gana
$pendientes = app_all(
    "SELECT d.*, u.business_name as local_name, u.address as local_address, u.logo_path as local_logo
     FROM deliveries d 
     LEFT JOIN users u ON d.local_user_id = u.id 
     WHERE d.status = 'pendiente' 
     ORDER BY d.created_at DESC"
);

$title = 'Pedidos Disponibles';
require __DIR__ . '/_header.php';
?>

<style>
    /* Driver Profile Hero */
    .driver-hero {
        position: relative;
        margin: -25px -20px 30px -20px;
        padding: 50px 20px 20px;
        text-align: center;
        overflow: hidden;
        background: #fff;
    }
    .driver-hero-cover {
        position: absolute;
        top: 0; left: 0; right: 0; height: 160px;
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        z-index: 1;
    }
    .driver-hero-cover::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, rgba(248, 250, 252, 0.1) 0%, var(--bg) 100%);
    }
    .driver-hero-content { position: relative; z-index: 2; }
    
    .driver-avatar-box {
        width: 90px; height: 90px;
        border-radius: 50%;
        border: 4px solid #fff;
        background: #fff;
        margin: 0 auto 15px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        display: flex; align-items: center; justify-content: center;
        overflow: hidden;
    }
    .driver-avatar-box span { font-size: 36px; }

    .driver-name-row { display: flex; align-items: center; justify-content: center; gap: 6px; }
    .driver-name-row h2 { font-size: 22px; margin: 0; color: var(--text); }
    .badge-verified { background: var(--primary); color: #fff; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 10px; }

    /* Available Orders Bento List */
    .section-title { font-size: 13px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin: 0 0 15px 5px; display: flex; align-items: center; gap: 8px; }
    .pulse-dot { width: 8px; height: 8px; background: var(--primary); border-radius: 50%; animation: pulse 1.5s infinite; }
    @keyframes pulse { 0% { transform: scale(0.9); opacity: 1; } 100% { transform: scale(2); opacity: 0; } }

    .order-bento-card {
        background: #fff;
        border-radius: var(--card-radius);
        padding: 24px;
        margin-bottom: 16px;
        box-shadow: var(--shadow);
        border: 1px solid rgba(0,0,0,0.01);
        transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .order-bento-card:active { transform: scale(0.97); }

    .local-info-row { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
    .local-mini-logo { width: 44px; height: 44px; border-radius: 12px; background: var(--bg); display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid #f1f5f9; }
    .local-mini-logo img { width: 100%; height: 100%; object-fit: cover; }
    .local-name-box b { display: block; font-size: 16px; color: var(--text); font-weight: 800; }
    .local-name-box span { font-size: 12px; color: var(--muted); font-weight: 600; display: flex; align-items: center; gap: 4px; }

    .route-bento { 
        background: var(--bg); 
        border-radius: 18px; 
        padding: 16px; 
        margin-bottom: 24px; 
        display: flex; 
        flex-direction: column; 
        gap: 12px; 
        position: relative;
    }
    .route-item { display: flex; align-items: flex-start; gap: 10px; position: relative; z-index: 2; }
    .route-icon { color: var(--primary); margin-top: 2px; flex-shrink: 0; }
    .route-text { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.3; }
    .route-text small { display: block; color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
    
    .route-line { position: absolute; left: 24px; top: 35px; bottom: 35px; width: 2px; background: repeating-linear-gradient(to bottom, #e2e8f0, #e2e8f0 4px, transparent 4px, transparent 8px); z-index: 1; }

    .order-earnings { 
        display: grid; 
        grid-template-columns: 1fr 1fr; 
        gap: 12px; 
        margin-bottom: 24px; 
    }
    .earning-box { background: var(--primary-soft); padding: 12px; border-radius: 16px; border: 1px solid rgba(37, 99, 235, 0.05); }
    .earning-box.product { background: #f8fafc; border-color: #e2e8f0; }
    .earning-label { font-size: 9px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: block; }
    .product .earning-label { color: var(--muted); }
    .earning-val { font-size: 16px; font-weight: 800; color: var(--text); }

    .btn-accept-tech {
        width: 100%;
        background: var(--primary);
        color: #fff;
        border: none;
        border-radius: 18px;
        padding: 18px;
        font-weight: 800;
        font-size: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2);
        cursor: pointer;
    }
    .btn-accept-tech:disabled { opacity: 0.6; cursor: not-allowed; }
</style>

<div class="driver-hero">
    <div class="driver-hero-cover"></div>
    <div class="driver-hero-content">
        <div class="driver-avatar-box">
            <span>🛵</span>
        </div>
        <div class="driver-name-row">
            <h2><?= esc($user['name']) ?></h2>
            <div class="badge-verified">✓</div>
        </div>
        <p class="muted" style="font-weight: 700; text-transform: uppercase; font-size: 10px; letter-spacing: 1px; margin-top: 4px;">Repartidor Pro</p>
    </div>
</div>

<div class="orders-section">
    <div class="section-title">
        <div class="pulse-dot"></div>
        Pedidos Disponibles
    </div>

    <div class="available-list" id="driver-list">
        <?php if (empty($pendientes)): ?>
            <div class="card" style="text-align: center; padding: 60px 20px; border: none;">
                <div style="background: var(--bg); width: 80px; height: 80px; border-radius: 24px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                    <svg style="width: 36px; height: 36px; color: var(--muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0a2 2 0 01-2 2H6a2 2 0 01-2-2m16 0l-3.586 3.586a2 2 0 01-2.828 0L12 14m0 0l-3.586 3.586a2 2 0 01-2.828 0L2 14"></path></svg>
                </div>
                <h3 style="font-size: 18px;">Sin pedidos</h3>
                <p class="muted" style="font-weight: 500;">Buscando nuevas entregas...</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendientes as $p): ?>
                <div class="order-bento-card" id="order-<?= $p['id'] ?>">
                    <div class="local-info-row">
                        <div class="local-mini-logo">
                            <?php if (!empty($p['local_logo'])): ?>
                                <img src="<?= esc(delivery_app_url($p['local_logo'])) ?>" alt="Logo">
                            <?php else: ?>
                                <span style="font-size: 20px;">🏢</span>
                            <?php endif; ?>
                        </div>
                        <div class="local-name-box">
                            <b><?= esc((string)$p['local_name']) ?></b>
                            <span>
                                <svg style="width:12px; height:12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg>
                                <?= esc((string)$p['local_address'] ?: 'Ubicación local') ?>
                            </span>
                        </div>
                    </div>

                    <div class="route-bento">
                        <div class="route-line"></div>
                        <div class="route-item">
                            <div class="route-icon">🏠</div>
                            <div class="route-text">
                                <small>Punto de Retiro</small>
                                <?= esc((string)$p['local_name']) ?>
                            </div>
                        </div>
                        <div class="route-item">
                            <div class="route-icon">📍</div>
                            <div class="route-text">
                                <small>Destino Cliente</small>
                                <?= esc((string)$p['delivery_address']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="order-earnings">
                        <div class="earning-box">
                            <span class="earning-label">Tu Ganancia</span>
                            <div class="earning-val"><?= gs((float)$p['delivery_cost']) ?></div>
                        </div>
                        <div class="earning-box product">
                            <span class="earning-label">Pago Local</span>
                            <div class="earning-val"><?= gs((float)$p['amount']) ?></div>
                        </div>
                    </div>

                    <button onclick="acceptOrder(<?= (int)$p['id'] ?>, this)" class="btn-accept-tech">
                        <span>TOMAR PEDIDO</span>
                        <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    async function acceptOrder(id, btn) {
        if (!confirm('¿Estás seguro de tomar este pedido?')) return;
        
        btn.disabled = true;
        btn.innerHTML = '<span>PROCESANDO...</span>';

        const formData = new FormData();
        formData.append('order_id', id);

        try {
            const resp = await fetch('api_accept_order.php', {
                method: 'POST',
                body: formData
            });
            const res = await resp.json();

            if (res.success) {
                // Sonido de éxito
                new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3').play();
                
                btn.style.background = '#10b981';
                btn.innerHTML = '<span>¡PEDIDO TOMADO!</span>';
                
                setTimeout(() => {
                    location.href = 'my_deliveries.php';
                }, 1000);
            } else {
                alert(res.message);
                btn.disabled = false;
                btn.innerHTML = '<span>TOMAR PEDIDO</span>';
            }
        } catch (e) {
            console.error(e);
            alert('Error de conexión');
            btn.disabled = false;
        }
    }

    // Polling sutil: refresca la lista cada 30s para ver nuevos pedidos
    setInterval(() => {
        // En una app real usaríamos fetch para actualizar el DOM sin recargar
        // Por ahora refrescamos para mantener simplicidad y datos frescos
        // location.reload(); 
    }, 30000);
</script>

<?php require __DIR__ . '/_footer.php'; ?>
