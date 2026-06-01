<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['repartidor']);

$user = current_user();

// Obtener pedidos pendientes (sin asignar)
$pendientes = app_all(
    "SELECT d.*, u.business_name as local_name, u.address as local_address
     FROM deliveries d 
     LEFT JOIN users u ON d.local_user_id = u.id 
     WHERE d.status = 'pendiente' 
     ORDER BY d.created_at DESC"
);

$title = 'Tablero Repartidor';
require __DIR__ . '/_header.php';
?>

<style>
    .driver-header { margin-bottom: 25px; }
    .driver-header h1 { font-size: 24px; font-weight: 800; color: #1e293b; margin-bottom: 5px; }
    
    .order-card-driver { background: #fff; border-radius: 24px; padding: 20px; margin-bottom: 16px; border: 1px solid #f1f5f9; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
    .local-name { font-weight: 800; color: #0C3A5B; font-size: 18px; margin-bottom: 5px; display: block; }
    .local-addr { font-size: 13px; color: #64748b; margin-bottom: 15px; display: flex; align-items: center; gap: 5px; }
    
    .dest-box { background: #f8fafc; border-radius: 16px; padding: 15px; margin-bottom: 20px; }
    .dest-box label { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; display: block; }
    .dest-addr { font-size: 14px; font-weight: 600; color: #1e293b; }
    
    .order-footer { display: flex; justify-content: space-between; align-items: center; }
    .order-pay { font-size: 16px; font-weight: 800; color: #1e293b; }
    .order-pay span { font-size: 10px; color: #94a3b8; display: block; font-weight: 700; }
</style>

<div class="driver-header">
    <h1>Pedidos Disponibles</h1>
    <p class="muted">Toca un pedido para aceptarlo y empezar la entrega.</p>
</div>

<div class="available-list">
    <?php if (empty($pendientes)): ?>
        <div style="text-align: center; padding: 60px 20px;">
            <div style="background: #f1f5f9; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                <svg style="width: 40px; height: 40px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0a2 2 0 01-2 2H6a2 2 0 01-2-2m16 0l-3.586 3.586a2 2 0 01-2.828 0L12 14m0 0l-3.586 3.586a2 2 0 01-2.828 0L2 14"></path></svg>
            </div>
            <h3>Sin pedidos</h3>
            <p class="muted">No hay pedidos nuevos por el momento. ¡Vuelve pronto!</p>
        </div>
    <?php else: ?>
        <?php foreach ($pendientes as $p): ?>
            <div class="order-card-driver">
                <span class="local-name"><?= esc((string)$p['local_name']) ?></span>
                <div class="local-addr">
                    <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    <?= esc((string)$p['local_address'] ?: 'Local') ?>
                </div>

                <div class="dest-box">
                    <label>Destino</label>
                    <div class="dest-addr"><?= esc((string)$p['delivery_address']) ?></div>
                </div>

                <div class="order-footer">
                    <div class="order-pay">
                        <span>PAGO PRODUCTO</span>
                        <?= gs((float)$p['amount']) ?>
                    </div>
                    <form method="post" action="<?= delivery_app_url('pages/api_accept_order.php') ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="btn">Aceptar</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
