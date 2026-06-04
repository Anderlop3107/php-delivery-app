<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['local']);

$user = current_user();
$localData = app_one('SELECT business_name, whatsapp, address, business_reference, latitude, longitude, logo_path FROM users WHERE id = ?', 'i', [(int) $user['id']]);

$errors = [];
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cName = trim((string)($_POST['customer_name'] ?? ''));
    $cPhone = trim((string)($_POST['customer_phone'] ?? ''));
    $address = trim((string)($_POST['delivery_address'] ?? ''));
    $deliveryCost = (float)($_POST['delivery_cost'] ?? 0);
    $productAmount = (float)($_POST['product_amount'] ?? 0);
    $driverPays = ($_POST['driver_pays'] ?? 'no') === 'yes' ? 1 : 0;
    $feePayer = $_POST['fee_payer'] ?? 'cliente';
    
    $destLat = $_POST['delivery_latitude'] !== '' ? (float) $_POST['delivery_latitude'] : null;
    $destLng = $_POST['delivery_longitude'] !== '' ? (float) $_POST['delivery_longitude'] : null;
    $originLat = $_POST['origin_latitude'] !== '' ? (float) $_POST['origin_latitude'] : (float)$localData['latitude'];
    $originLng = $_POST['origin_longitude'] !== '' ? (float) $_POST['origin_longitude'] : (float)$localData['longitude'];

    if ($cName === '' || $address === '' || $deliveryCost <= 0) {
        $errors[] = 'Todos los campos son obligatorios.';
    }

    if (empty($errors)) {
        app_exec(
            "INSERT INTO deliveries (
                local_user_id, customer_name, customer_phone, delivery_address, 
                order_description, amount, delivery_cost, driver_pays_local, delivery_fee_payer,
                pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude, 
                status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW())",
            'issssddisdddd',
            [
                (int)$user['id'], $cName, $cPhone, $address, 
                trim((string)$_POST['order_description']), $productAmount, $deliveryCost, $driverPays, $feePayer,
                $originLat, $originLng, $destLat, $destLng
            ]
        );
        header('Location: my_deliveries.php?toast=created'); exit;
    }
}

$title = 'Nuevo Pedido';
require __DIR__ . '/_header.php';
?>

<!-- Mapbox GL JS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.js"></script>

<style>
    /* Stepper */
    .stepper { display: flex; justify-content: space-between; margin-bottom: 30px; position: relative; padding: 0 10px; }
    .stepper::before { content: ''; position: absolute; top: 15px; left: 40px; right: 40px; height: 2px; background: #e2e8f0; z-index: 1; }
    .step { position: relative; z-index: 2; display: flex; flex-direction: column; align-items: center; gap: 8px; flex: 1; }
    .step-circle { width: 32px; height: 32px; border-radius: 50%; background: #fff; border: 2px solid #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #94a3b8; transition: all 0.3s; }
    .step.active .step-circle { background: var(--primary); border-color: var(--primary); color: #fff; box-shadow: 0 0 0 4px rgba(255, 140, 66, 0.2); }
    .step.completed .step-circle { background: #10b981; border-color: #10b981; color: #fff; }
    .step-label { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }

    .form-step { display: none; }
    .form-step.active { display: block; animation: slideIn 0.3s ease-out; }
    @keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }

    /* Inputs */
    .input-group { margin-bottom: 20px; }
    .input-wrapper { position: relative; }
    .input-wrapper input { padding-left: 45px; }
    .field-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; color: var(--primary); }

    #map { height: 180px; border-radius: 20px; margin: 15px 0; border: 1px solid var(--border); }
    
    /* Global Card Styles for Steps */
    .op-card { background: #f8fafc; border-radius: 16px; padding: 15px; margin-bottom: 15px; border: 1px solid var(--border); }
    .op-title { font-size: 11px; font-weight: 800; color: var(--text); margin-bottom: 10px; display: block; }
    .op-options { display: flex; gap: 8px; }
    .op-btn { flex: 1; padding: 10px; border-radius: 10px; border: 2px solid #e2e8f0; background: #fff; font-weight: 700; color: #64748b; cursor: pointer; transition: all 0.2s; text-align: center; font-size: 12px; }
    .op-btn.active { border-color: var(--primary); color: var(--primary); background: var(--primary-soft); }
    .op-card input { padding: 10px 14px; font-size: 13px; border-radius: 12px; background: #fff; }

    /* Step 2 Verification */
    .summary-card { background: #fff; border-radius: 16px; padding: 16px; border: 1px solid var(--border); margin-bottom: 15px; }
    .contact-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
    .wa-btn { background: #25d366; color: #fff; padding: 6px 12px; border-radius: 999px; text-decoration: none; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px; }

    .route-map { height: 150px; border-radius: 16px; margin-bottom: 15px; border: 1px solid var(--border); }
    
    .detail-card { background: #f8fafc; border-radius: 16px; padding: 12px 16px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    .detail-info b { font-size: 13px; color: var(--text); }
    .detail-info span { display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; }
    .detail-val { font-size: 14px; font-weight: 800; color: var(--text); }

    .cost-trip-card { background: var(--primary); color: #fff; border-radius: 16px; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; margin-top: 15px; }
    .cost-trip-card b { font-size: 16px; font-weight: 800; }

    .actions { display: flex; flex-direction: column; gap: 15px; margin-top: 20px; align-items: center; width: 100%; }
    .btn-continue { width: auto; min-width: 220px; padding: 14px 30px; font-size: 15px; }
    .btn-back { font-size: 14px; font-weight: 700; color: #94a3b8; cursor: pointer; }
</style>

<div class="stepper">
    <div class="step active" id="step-1-indicator"><div class="step-circle">1</div><div class="step-label">Información</div></div>
    <div class="step" id="step-2-indicator"><div class="step-circle">2</div><div class="step-label">Verificación</div></div>
    <div class="step" id="step-3-indicator"><div class="step-circle">3</div><div class="step-label">Confirmación</div></div>
</div>

<form method="post" id="order-form">
    <!-- PASO 1: INFORMACIÓN -->
    <div class="form-step active" id="step-1">
        <div class="card" style="border:none; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
            <div class="input-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <input type="text" name="customer_name" id="c_name" placeholder="Nombre del cliente" required>
                </div>
            </div>

            <div class="input-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                    <input type="text" name="customer_phone" id="c_phone" placeholder="Telefono Ej 0981123456">
                </div>
            </div>

            <div style="position: relative;">
                <div id="map"></div>
                <button type="button" onclick="openGoogleMaps()" style="position: absolute; bottom: 15px; right: 15px; z-index: 10; background: #ffffff; color: #475569; padding: 8px 12px; font-size: 11px; font-weight: 800; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.1); cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/3/39/Google_Maps_icon_%282015-2020%29.svg" style="width: 14px; height: 14px;"> GOOGLE MAPS
                </button>
            </div>
            
            <input type="hidden" name="origin_latitude" id="o_lat">
            <input type="hidden" name="origin_longitude" id="o_lng">
            <input type="hidden" name="delivery_latitude" id="lat">
            <input type="hidden" name="delivery_longitude" id="lng">

            <div class="input-group" style="margin-top:20px;">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <input type="text" id="c_delivery_cost_display" placeholder="Costo de Envío (Gs.)" readonly style="background:#f8fafc; font-weight:800; color:var(--primary);">
                    <input type="hidden" name="delivery_cost" id="c_delivery_cost">
                </div>
            </div>

            <div class="input-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <input type="text" name="delivery_address" id="c_address" placeholder="Dirección particular" required>
                </div>
            </div>

            <div class="input-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    <input type="text" name="order_description" id="c_ref" placeholder="Referencia">
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-continue" onclick="goToStep2()">CONTINUAR</button>
        </div>
    </div>

    <!-- PASO 2: VERIFICACIÓN -->
    <div class="form-step" id="step-2">
        <div class="summary-card">
            <!-- 1. Cliente -->
            <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 20px;">👤</span>
                <p id="v-customer-name" style="margin:0; font-size:18px; font-weight:800; color:var(--text);">-</p>
            </div>
            
            <!-- 2. Contacto -->
            <div class="contact-row" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <div class="wa-btn">
                    <span style="font-size: 14px;">💬</span>
                    WhatsApp
                </div>
                <div style="display: flex; align-items: center; gap: 5px; color: #64748b; font-size: 14px; font-weight: 700;">
                    <span>📞</span>
                    <span id="v-customer-phone">-</span>
                </div>
            </div>

            <!-- 3. Dirección -->
            <div style="margin-bottom: 15px;">
                <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                    <span style="font-size: 14px;">🏠</span>
                    <span style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase;">Dirección</span>
                </div>
                <p id="v-address" style="margin:0; font-size:14px; color:var(--text); font-weight:600;">-</p>
            </div>

            <!-- 4. Referencia -->
            <div>
                <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                    <span style="font-size: 14px;">📝</span>
                    <span style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase;">Referencia</span>
                </div>
                <p id="v-ref" style="margin:0; font-size:14px; color:var(--text); font-weight:600;">-</p>
            </div>
        </div>

        <div id="route-map" class="route-map"></div>

        <!-- Detalles Operativos -->
        <div class="op-card">
            <span class="op-title">¿PAGA POR EL PRODUCTO?</span>
            <div class="op-options">
                <div class="op-btn active" id="btn-pays-no" onclick="setOp('driver_pays', 'no', this)">NO</div>
                <div class="op-btn" id="btn-pays-yes" onclick="setOp('driver_pays', 'yes', this)">SÍ</div>
            </div>
            <input type="hidden" name="driver_pays" id="driver_pays_val" value="no">
            
            <div id="product_amount_box" style="display:none; margin-top:15px;">
                <div class="input-wrapper">
                    <input type="number" name="product_amount" id="c_product_amount" placeholder="Monto que paga el delivery (Gs.)" style="background:#fff;">
                </div>
            </div>
        </div>

        <div class="op-card">
            <span class="op-title">¿QUIÉN PAGA EL ENVÍO?</span>
            <div class="op-options">
                <div class="op-btn" id="btn-payer-local" onclick="setOp('fee_payer', 'local', this)">EL LOCAL</div>
                <div class="op-btn active" id="btn-payer-cliente" onclick="setOp('fee_payer', 'cliente', this)">EL CLIENTE</div>
            </div>
            <input type="hidden" name="fee_payer" id="fee_payer_val" value="cliente">
        </div>

        <div class="cost-trip-card">
            <b>Costo del viaje Gs. <span id="v-total-trip">0</span></b>
            <svg style="width:24px; height:24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
        </div>

        <div class="actions" style="flex-direction: row; justify-content: center; gap: 40px; margin-top: 30px;">
            <div class="btn-back" onclick="goToStep1()" style="margin: 0;">VOLVER</div>
            <button type="submit" class="btn btn-continue" style="min-width: 140px; margin: 0; padding: 12px 20px;">CONFIRMAR</button>
        </div>
    </div>
</form>

<script>
    mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
    
    let marker;
    let originCoords = [<?= (float)($localData['longitude'] ?? -57.6359) ?>, <?= (float)($localData['latitude'] ?? -25.2637) ?>];
    let routeMap;

    const map = new mapboxgl.Map({
        container: 'map',
        style: 'mapbox://styles/mapbox/streets-v12',
        center: originCoords,
        zoom: 13
    });

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            originCoords = [pos.coords.longitude, pos.coords.latitude];
            document.getElementById('o_lat').value = pos.coords.latitude;
            document.getElementById('o_lng').value = pos.coords.longitude;
            map.setCenter(originCoords);
            if (marker) marker.setLngLat(originCoords);
        });
    }

    map.on('load', () => {
        const el = document.createElement('div');
        el.innerHTML = '📍'; el.style.fontSize = '32px'; el.style.cursor = 'pointer';
        marker = new mapboxgl.Marker({ draggable: true, element: el }).setLngLat(originCoords).addTo(map);
        marker.on('dragend', calculateCost);
        map.on('click', e => { marker.setLngLat(e.lngLat); calculateCost(); });
    });

    async function calculateCost() {
        const dest = marker.getLngLat();
        document.getElementById('lat').value = dest.lat;
        document.getElementById('lng').value = dest.lng;
        const url = `https://api.mapbox.com/directions/v5/mapbox/driving/${originCoords[0]},${originCoords[1]};${dest.lng},${dest.lat}?access_token=${mapboxgl.accessToken}`;
        try {
            const resp = await fetch(url);
            const data = await resp.json();
            if (data.routes && data.routes.length > 0) updateUI(data.routes[0].distance / 1000);
        } catch (e) {}
    }

    function updateUI(km) {
        let cost = km <= 7 ? 20000 : (km <= 9 ? 25000 : (km <= 10 ? 30000 : Math.ceil(km) * 3000));
        const formatted = new Intl.NumberFormat('de-DE').format(cost);
        document.getElementById('c_delivery_cost_display').value = `Gs. ${formatted} (${km.toFixed(1)} km)`;
        document.getElementById('c_delivery_cost').value = cost;
    }

    function setOp(field, val, btn) {
        document.getElementById(field + '_val').value = val;
        btn.parentElement.querySelectorAll('.op-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        if (field === 'driver_pays') {
            document.getElementById('product_amount_box').style.display = (val === 'yes' ? 'block' : 'none');
        }
    }

    function goToStep2() {
        const cName = document.getElementById('c_name');
        const cPhone = document.getElementById('c_phone');
        const cAddress = document.getElementById('c_address');
        const cCost = document.getElementById('c_delivery_cost');
        const cRef = document.getElementById('c_ref');

        if (!cName.value || !cAddress.value || !cCost.value) {
            alert('Por favor, completa los datos y selecciona ubicación.'); return;
        }

        document.getElementById('v-customer-name').innerText = cName.value;
        document.getElementById('v-customer-phone').innerText = cPhone.value || 'No proveído';
        document.getElementById('v-address').innerText = cAddress.value;
        document.getElementById('v-ref').innerText = cRef.value || 'Sin referencia';
        
        document.getElementById('v-total-trip').innerText = parseInt(cCost.value).toLocaleString('de-DE');

        document.getElementById('step-1').classList.remove('active');
        document.getElementById('step-2').classList.add('active');
        document.getElementById('step-1-indicator').className = 'step completed';
        document.getElementById('step-2-indicator').className = 'step active';

        initRouteMap();
    }

    function initRouteMap() {
        if (routeMap) routeMap.remove();
        const dest = marker.getLngLat();
        routeMap = new mapboxgl.Map({
            container: 'route-map', style: 'mapbox://styles/mapbox/streets-v12',
            center: [(originCoords[0] + dest.lng)/2, (originCoords[1] + dest.lat)/2],
            zoom: 11
        });

        routeMap.on('load', async () => {
            new mapboxgl.Marker({ color: '#ff4444' }).setLngLat(originCoords).addTo(routeMap);
            new mapboxgl.Marker({ color: '#10b981' }).setLngLat(dest).addTo(routeMap);

            const query = await fetch(`https://api.mapbox.com/directions/v5/mapbox/driving/${originCoords[0]},${originCoords[1]};${dest.lng},${dest.lat}?geometries=geojson&access_token=${mapboxgl.accessToken}`);
            const json = await query.json();
            if (json.routes && json.routes[0]) {
                const data = json.routes[0];
                routeMap.addSource('route', { 'type': 'geojson', 'data': { 'type': 'Feature', 'geometry': data.geometry } });
                routeMap.addLayer({ 'id': 'route', 'type': 'line', 'source': 'route', 'layout': { 'line-join': 'round', 'line-cap': 'round' }, 'paint': { 'line-color': '#FF8C42', 'line-width': 5, 'line-opacity': 0.75 } });
                const bounds = new mapboxgl.LngLatBounds(originCoords, [dest.lng, dest.lat]);
                routeMap.fitBounds(bounds, { padding: 40 });
            }
        });
    }

    function goToStep1() {
        document.getElementById('step-2').classList.remove('active');
        document.getElementById('step-1').classList.add('active');
        document.getElementById('step-1-indicator').className = 'step active';
        document.getElementById('step-2-indicator').className = 'step';
    }

    function openGoogleMaps() {
        const dest = marker.getLngLat();
        window.open(`https://www.google.com/maps/search/?api=1&query=${dest.lat},${dest.lng}`, '_blank');
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
