<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['repartidor']);

$sessionUser = current_user();
$userData = app_one("SELECT * FROM users WHERE id = ?", "i", [(int)$sessionUser['id']]);

$title = 'Escáner de Pedidos';
require __DIR__ . '/_header.php';
?>

<!-- Mapbox GL JS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.js"></script>

<style>
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
    .avatar-img-tech span { font-size: 36px; }

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
    .radar-center-circle { width: 100px; height: 100px; background: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 10; box-shadow: 0 0 30px rgba(37, 99, 235, 0.4); color: #fff; font-size: 36px; overflow: hidden; }
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

    /* TOGGLE */
    .availability-toggle-box { text-align: center; }
    .status-label-text { margin-top: 15px; font-size: 14px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
    .status-label-text.active { color: #10b981; }
    .ios-switch { position: relative; display: inline-block; width: 64px; height: 34px; }
    .ios-switch input { opacity: 0; width: 0; height: 0; }
    .ios-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #e2e8f0; transition: .4s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 34px; }
    .ios-slider:before { position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 50%; box-shadow: 0 3px 8px rgba(0,0,0,0.15); }
    input:checked + .ios-slider { background-color: #10b981; }
    input:checked + .ios-slider:before { transform: translateX(30px); }

    /* BROADCAST MODAL */
    .broadcast-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); z-index: 4000; display: none; align-items: center; justify-content: center; padding: 20px; }
    .broadcast-card { 
        background: #fff; width: 100%; max-width: 400px; border-radius: 28px; 
        padding: 30px 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); 
        animation: modalPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
    }
    @keyframes modalPop { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    .shop-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
    .shop-avatar { width: 50px; height: 50px; background: var(--primary-soft); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; overflow: hidden; border: 1px solid rgba(0,0,0,0.05); }
    .shop-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .shop-info h3 { font-size: 18px; margin: 0; color: var(--text); }
    .shop-info p { font-size: 12px; color: var(--muted); margin: 2px 0 0; font-weight: 600; }

    #mini-route-map { height: 160px; border-radius: 20px; margin-bottom: 20px; border: 1px solid var(--border); }

    .money-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 25px; }
    .money-box { background: #f8fafc; padding: 15px; border-radius: 18px; border: 1px solid rgba(0,0,0,0.02); }
    .money-box.earnings { background: var(--primary-soft); border-color: rgba(37, 99, 235, 0.1); }
    .money-box small { display: block; font-size: 9px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
    .money-box.earnings small { color: var(--primary); }
    .money-box b { font-size: 17px; color: var(--text); }
    .money-box.earnings b { color: var(--primary); }

    .btn-accept-now { 
        width: 100%; background: var(--primary); color: #fff; border: none; border-radius: 18px; 
        padding: 18px; font-weight: 800; font-size: 16px; display: flex; align-items: center; 
        justify-content: center; gap: 10px; cursor: pointer; transition: transform 0.2s;
        box-shadow: 0 12px 30px rgba(37, 99, 235, 0.3);
    }
    .btn-accept-now:active { transform: scale(0.96); }
    .btn-ignore { 
        width: 100%; margin-top: 15px; background: transparent; color: var(--muted); border: none; 
        font-weight: 700; font-size: 13px; cursor: pointer; 
    }
</style>

<div class="driver-scanner-view">
    <!-- SECCIÓN DE PERFIL PERSONALIZADA -->
    <div class="profile-header-tech">
        <div class="avatar-wrapper-tech">
            <div class="avatar-img-tech">
                <?php if (!empty($userData['logo_path'])): ?>
                    <img src="<?= esc(delivery_app_url($userData['logo_path'])) ?>?v=<?= time() ?>" alt="Avatar">
                <?php else: ?>
                    <span>🛵</span>
                <?php endif; ?>
            </div>
            <div class="verified-badge-tech" title="Cuenta Verificada">✓</div>
        </div>
        <h2 class="welcome-title-tech">¡Hola!, <?= explode(' ', esc($userData['name']))[0] ?></h2>
        <p class="subtitle-tech">Conéctate para recibir pedidos</p>
    </div>

    <!-- ÁREA DEL RADAR -->
    <div class="radar-wrapper paused" id="radar-ui">
        <div class="radar-pulse-wave"></div>
        <div class="radar-ring-arc arc-1"></div>
        <div class="radar-ring-arc arc-2"></div>
        <div class="radar-ring-arc arc-3"></div>
        <div class="radar-center-circle"><span>🛵</span></div>
    </div>

    <!-- ÁREA DEL TOGGLE -->
    <div class="availability-toggle-box">
        <label class="ios-switch">
            <input type="checkbox" id="main-status-toggle" onchange="handleScannerToggle(this.checked)">
            <span class="ios-slider"></span>
        </label>
        <div class="status-label-text" id="main-status-text">Desconectado</div>
    </div>
</div>

<!-- MODAL DE BROADCAST -->
<div id="broadcast-modal" class="broadcast-overlay">
    <div class="broadcast-card">
        <div class="shop-header">
            <div class="shop-avatar" id="m-shop-logo-container">🏢</div>
            <div class="shop-info">
                <p>NUEVO PEDIDO DE</p>
                <h3 id="m-shop-name">-</h3>
            </div>
        </div>

        <div id="mini-route-map"></div>

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

        <button id="btn-accept" class="btn-accept-now" onclick="acceptBroadcastedOrder()">
            ACEPTAR PEDIDO
        </button>
        <button class="btn-ignore" onclick="closeBroadcast()">IGNORAR</button>
    </div>
</div>

<script>
    mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
    
    let checkInterval = null;
    let currentBroadcastId = null;
    let miniMap = null;

    function handleScannerToggle(isOnline) {
        const radar = document.getElementById('radar-ui');
        const text = document.getElementById('main-status-text');

        if (isOnline) {
            radar.classList.remove('paused');
            text.innerText = 'Buscando pedidos...';
            text.classList.add('active');
            startPolling();
        } else {
            radar.classList.add('paused');
            text.innerText = 'Desconectado';
            text.classList.remove('active');
            stopPolling();
            closeBroadcast();
        }
    }

    function startPolling() {
        if (checkInterval) return;
        checkInterval = setInterval(async () => {
            try {
                const resp = await fetch('api_check_new_orders.php');
                const res = await resp.json();
                
                if (res.has_orders && res.order.id !== currentBroadcastId) {
                    showBroadcast(res.order);
                }
            } catch (e) {}
        }, 3000);
    }

    function stopPolling() {
        if (checkInterval) { clearInterval(checkInterval); checkInterval = null; }
    }

    function showBroadcast(order) {
        currentBroadcastId = order.id;
        document.getElementById('m-shop-name').innerText = order.local_name;
        document.getElementById('m-earnings').innerText = order.earnings.toLocaleString('de-DE') + ' Gs.';
        
        const logoContainer = document.getElementById('m-shop-logo-container');
        if (order.local_logo) {
            const baseUrl = '/php-delivery-app/';
            logoContainer.innerHTML = `<img src="${baseUrl}${order.local_logo}" alt="Logo">`;
        } else {
            logoContainer.innerHTML = '🏢';
        }

        const productBox = document.getElementById('m-product-box');
        if (order.driver_pays) {
            productBox.style.opacity = '1';
            document.getElementById('m-product-label').innerText = 'PAGAS AL LOCAL';
            document.getElementById('m-product-amount').innerText = order.amount_product.toLocaleString('de-DE') + ' Gs.';
        } else {
            document.getElementById('m-product-label').innerText = 'PAGO AL LOCAL';
            document.getElementById('m-product-amount').innerText = 'SIN COBRO';
        }

        document.getElementById('broadcast-modal').style.display = 'flex';
        new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3').play();

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
            new mapboxgl.Marker({ color: '#ff4444' }).setLngLat([order.local_lng, order.local_lat]).addTo(miniMap);
            new mapboxgl.Marker({ color: '#10b981' }).setLngLat([order.dest_lng, order.dest_lat]).addTo(miniMap);
            
            const query = await fetch(`https://api.mapbox.com/directions/v5/mapbox/driving/${order.local_lng},${order.local_lat};${order.dest_lng},${order.dest_lat}?geometries=geojson&access_token=${mapboxgl.accessToken}`);
            const json = await query.json();
            if (json.routes && json.routes[0]) {
                miniMap.addSource('route', { 'type': 'geojson', 'data': { 'type': 'Feature', 'geometry': json.routes[0].geometry } });
                miniMap.addLayer({ 'id': 'route', 'type': 'line', 'source': 'route', 'layout': { 'line-join': 'round', 'line-cap': 'round' }, 'paint': { 'line-color': '#2563eb', 'line-width': 4, 'line-opacity': 0.8 } });
                const bounds = new mapboxgl.LngLatBounds([order.local_lng, order.local_lat], [order.dest_lng, order.dest_lat]);
                miniMap.fitBounds(bounds, { padding: 30 });
            }
        });
    }

    async function acceptBroadcastedOrder() {
        if (!currentBroadcastId) return;
        const btn = document.getElementById('btn-accept');
        btn.disabled = true;
        btn.innerText = 'PROCESANDO...';

        const formData = new FormData();
        formData.append('order_id', currentBroadcastId);

        try {
            const resp = await fetch('api_accept_order.php', { method: 'POST', body: formData });
            const res = await resp.json();
            if (res.success) {
                location.href = 'my_deliveries.php';
            } else {
                alert(res.message);
                closeBroadcast();
            }
        } catch (e) {
            alert('Error al aceptar el pedido');
            btn.disabled = false;
            btn.innerText = 'ACEPTAR PEDIDO';
        }
    }

    function closeBroadcast() {
        document.getElementById('broadcast-modal').style.display = 'none';
        currentBroadcastId = null;
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
