<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['local']);

$user = current_user();
$localData = app_one('SELECT business_name, whatsapp, address, business_reference, latitude, longitude FROM users WHERE id = ?', 'i', [(int) $user['id']]);

$errors = [];
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cName = trim((string)($_POST['customer_name'] ?? ''));
    $cPhone = trim((string)($_POST['customer_phone'] ?? ''));
    $address = trim((string)($_POST['delivery_address'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $lat = $_POST['delivery_latitude'] !== '' ? (float) $_POST['delivery_latitude'] : null;
    $lng = $_POST['delivery_longitude'] !== '' ? (float) $_POST['delivery_longitude'] : null;

    if ($cName === '' || $address === '' || $amount <= 0) {
        $errors[] = 'Todos los campos son obligatorios.';
    }

    if (empty($errors)) {
        app_exec(
            "INSERT INTO deliveries (local_user_id, customer_name, customer_phone, delivery_address, amount, delivery_latitude, delivery_longitude, status, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW())",
            'isssddd',
            [(int)$user['id'], $cName, $cPhone, $address, $amount, $lat, $lng]
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
    .stepper {
        display: flex;
        justify-content: space-between;
        margin-bottom: 30px;
        position: relative;
        padding: 0 10px;
    }
    .stepper::before {
        content: '';
        position: absolute;
        top: 15px;
        left: 40px;
        right: 40px;
        height: 2px;
        background: #e2e8f0;
        z-index: 1;
    }
    .step {
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        flex: 1;
    }
    .step-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #fff;
        border: 2px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 800;
        color: #94a3b8;
        transition: all 0.3s;
    }
    .step.active .step-circle {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
        box-shadow: 0 0 0 4px rgba(255, 140, 66, 0.2);
    }
    .step.completed .step-circle {
        background: #10b981;
        border-color: #10b981;
        color: #fff;
    }
    .step-label {
        font-size: 11px;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        text-align: center;
    }
    .step.active .step-label { color: var(--text); }

    .form-step { display: none; }
    .form-step.active { display: block; animation: slideIn 0.3s ease-out; }
    @keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }

    .input-group { margin-bottom: 20px; }
    .input-wrapper { position: relative; }
    .input-wrapper input, .input-wrapper textarea { padding-left: 45px; }
    .field-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        width: 20px;
        height: 20px;
        color: var(--primary);
    }
    .field-icon-area { top: 20px; transform: none; }

    #map { height: 180px; border-radius: 20px; margin: 15px 0; border: 1px solid var(--border); }
    
    .cost-note {
        font-size: 12px;
        color: #64748b;
        background: #f8fafc;
        padding: 12px;
        border-radius: 12px;
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .verification-card {
        background: #f8fafc;
        border-radius: 20px;
        padding: 20px;
    }
    .v-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; }
    .v-row span { color: #64748b; }
    .v-row b { color: var(--text); }

    .actions { display: flex; flex-direction: column; gap: 15px; margin-top: 20px; align-items: center; }
    .btn-continue { width: 100%; }
    .btn-back { font-size: 14px; font-weight: 700; color: #94a3b8; text-decoration: none; cursor: pointer; }
</style>

<div class="stepper">
    <div class="step active" id="step-1-indicator">
        <div class="step-circle">1</div>
        <div class="step-label">Información</div>
    </div>
    <div class="step" id="step-2-indicator">
        <div class="step-circle">2</div>
        <div class="step-label">Verificación</div>
    </div>
    <div class="step" id="step-3-indicator">
        <div class="step-circle">3</div>
        <div class="step-label">Confirmación</div>
    </div>
</div>

<form method="post" id="order-form">
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
                    <img src="https://upload.wikimedia.org/wikipedia/commons/3/39/Google_Maps_icon_%282015-2020%29.svg" style="width: 14px; height: 14px;">
                    GOOGLE MAPS
                </button>
            </div>
            <input type="hidden" name="delivery_latitude" id="lat">
            <input type="hidden" name="delivery_longitude" id="lng">

            <div class="input-group" style="margin-top:20px;">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <input type="number" name="amount" id="c_amount" placeholder="Monto del Pedido (Gs.)" required>
                </div>
            </div>

            <div class="input-group">
                <div class="input-wrapper">
                    <svg class="field-icon field-icon-area" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <textarea name="delivery_address" id="c_address" placeholder="Dirección particular" rows="2" required></textarea>
                </div>
            </div>

            <div class="input-group">
                <div class="input-wrapper">
                    <svg class="field-icon field-icon-area" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    <textarea name="order_description" id="c_notes" placeholder="Referencia" rows="2"></textarea>
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-continue" onclick="goToStep2()">CONTINUAR</button>
        </div>
    </div>

    <!-- PASO 2: VERIFICACIÓN -->
    <div class="form-step" id="step-2">
        <div class="card" style="border:none; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
            <h3>Verifica los datos</h3>
            <p class="muted" style="margin-bottom:20px;">Revisa que la información sea correcta antes de solicitar el envío.</p>
            
            <div class="verification-card">
                <div class="v-row"><span>Cliente</span><b id="v-name">-</b></div>
                <div class="v-row"><span>Teléfono</span><b id="v-phone">-</b></div>
                <div class="v-row"><span>Monto</span><b id="v-amount">-</b></div>
                <div class="v-row"><span>Dirección</span><b id="v-address">-</b></div>
                <div class="v-row"><span>Notas</span><b id="v-notes">-</b></div>
            </div>

            <div class="cost-note" style="background:#fff7ed; border:1px solid #ffedd5;">
                <svg style="width:16px; height:16px; color:var(--primary);" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                <span style="color:#9a3412; font-weight:600;">Se asignará el repartidor más cercano.</span>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn-continue">CONFIRMAR Y ENVIAR</button>
            <div class="btn-back" onclick="goToStep1()">VOLVER A EDITAR</div>
        </div>
    </div>
</form>

<script>
    mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
    
    let marker;
    const map = new mapboxgl.Map({
        container: 'map',
        style: 'mapbox://styles/mapbox/streets-v12',
        center: [<?= (float)($localData['longitude'] ?? -57.6359) ?>, <?= (float)($localData['latitude'] ?? -25.2637) ?>],
        zoom: 13
    });

    map.on('load', () => {
        const el = document.createElement('div');
        el.className = 'marker';
        el.style.width = '30px';
        el.style.height = '30px';
        el.style.backgroundColor = 'var(--primary)';
        el.style.borderRadius = '50%';
        el.style.border = '3px solid white';
        el.style.boxShadow = '0 0 10px rgba(0,0,0,0.3)';

        marker = new mapboxgl.Marker({ draggable: true, element: el })
            .setLngLat([<?= (float)($localData['longitude'] ?? -57.6359) ?>, <?= (float)($localData['latitude'] ?? -25.2637) ?>])
            .addTo(map);

        function updateCoords() {
            const lngLat = marker.getLngLat();
            document.getElementById('lat').value = lngLat.lat;
            document.getElementById('lng').value = lngLat.lng;
        }

        marker.on('dragend', updateCoords);
        updateCoords();
    });

    function openGoogleMaps() {
        window.open('https://www.google.com/maps', '_blank');
    }

    function goToStep2() {
        if (!document.getElementById('c_name').value || !document.getElementById('c_address').value || !document.getElementById('c_amount').value) {
            alert('Por favor, completa los campos obligatorios.');
            return;
        }

        // Llenar verificación
        document.getElementById('v-name').innerText = document.getElementById('c_name').value;
        document.getElementById('v-phone').innerText = document.getElementById('c_phone').value || 'No proveído';
        document.getElementById('v-amount').innerText = parseInt(document.getElementById('c_amount').value).toLocaleString() + ' Gs.';
        document.getElementById('v-address').innerText = document.getElementById('c_address').value;
        document.getElementById('v-notes').innerText = document.getElementById('c_notes').value || 'Sin notas';

        document.getElementById('step-1').classList.remove('active');
        document.getElementById('step-2').classList.add('active');
        document.getElementById('step-1-indicator').classList.add('completed');
        document.getElementById('step-1-indicator').classList.remove('active');
        document.getElementById('step-2-indicator').classList.add('active');
    }

    function goToStep1() {
        document.getElementById('step-2').classList.remove('active');
        document.getElementById('step-1').classList.add('active');
        document.getElementById('step-1-indicator').classList.remove('completed');
        document.getElementById('step-1-indicator').classList.add('active');
        document.getElementById('step-2-indicator').classList.remove('active');
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
