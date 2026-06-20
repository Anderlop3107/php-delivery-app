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
        header('Location: create_delivery.php?success=1'); exit;
    }
}

$title = 'Nuevo Pedido';
require __DIR__ . '/_header.php';
?>

<!-- Mapbox GL JS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.js"></script>

<!-- jQuery and Toastr -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<style>
    /* Chevron Stepper */
    .chevron-stepper {
        display: flex;
        width: 100%;
        height: 42px;
        background: #f1f5f9;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 15px;
    }
    .chevron-step {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: relative;
    }
    
    /* Step 1: In Step 1 (Active) / In Step 2 (Completed) */
    #step-1-chevron.active { background: var(--primary); color: #fff; }
    #step-1-chevron.completed { background: rgba(37, 99, 235, 0.4); color: #fff; }
    
    /* Step 2: In Step 1 (Pending) / In Step 2 (Active) */
    #step-2-chevron.pending { background: #f1f5f9; color: #94a3b8; }
    #step-2-chevron.active { background: #2563eb; color: #fff; font-weight: 800; }

    /* The Chevron Cut */
    .chevron-step:first-child {
        clip-path: polygon(0% 0%, calc(100% - 15px) 0%, 100% 50%, calc(100% - 15px) 100%, 0% 100%);
        padding-right: 15px;
    }
    .chevron-step:last-child {
        clip-path: polygon(0% 0%, 100% 0%, 100% 100%, 0% 100%, 15px 50%);
        padding-left: 15px;
        margin-left: -15px;
    }

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
    .summary-card { background: #fff; border-radius: 16px; padding: 16px 16px 60px; border: 1px solid var(--border); margin-bottom: 15px; position: relative; min-height: 140px; }
    .summary-actions { position: absolute; bottom: 12px; right: 15px; display: flex; gap: 10px; }
    .action-circle { 
        width: 38px; 
        height: 38px; 
        border-radius: 50%; 
        text-decoration: none; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        color: #fff; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
        transition: transform 0.2s;
        font-size: 18px;
    }
    .action-circle:active { transform: scale(0.9); }
    .wa-btn { background: #25d366; }
    .call-btn { background: #3b82f6; } 

    .route-map { height: 150px; border-radius: 16px; margin-bottom: 15px; border: 1px solid var(--border); }
    
    .detail-card { background: #f8fafc; border-radius: 16px; padding: 12px 16px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    .detail-info b { font-size: 13px; color: var(--text); }
    .detail-info span { display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; }
    .detail-val { font-size: 14px; font-weight: 800; color: var(--text); }

    /* Action Buttons with Integrated Back/Forward Circles */
    .main-action-btn { 
        background: var(--primary); 
        color: #fff; 
        border-radius: 20px; 
        padding: 10px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        margin-top: 25px; 
        position: relative;
        min-height: 64px;
        cursor: pointer;
        box-shadow: 0 10px 25px rgba(37, 99, 235, 0.2);
        transition: transform 0.2s;
        border: none;
        width: 100%;
    }
    .main-action-btn:active { transform: scale(0.98); }
    .main-action-btn.btn-slim { min-height: 50px; }
    .main-action-btn b { font-size: 15px; font-weight: 700; text-align: center; flex: 1; }
    
    .action-circle-inline {
        width: 44px;
        height: 44px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        flex-shrink: 0;
        transition: background 0.2s;
    }
    .action-circle-inline:active { background: rgba(255,255,255,0.4); }

    /* Modern Switch Style */
    .switch-container { display: flex; justify-content: space-between; align-items: center; width: 100%; }
    .switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background-color: #e2e8f0;
        transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 24px;
    }
    .slider:before {
        position: absolute;
        content: "";
        height: 18px; width: 18px;
        left: 3px; bottom: 3px;
        background-color: white;
        transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    input:checked + .slider { background-color: var(--primary); }
    input:checked + .slider:before { transform: translateX(20px); }

    /* Success Modal */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); z-index: 3000; display: none; align-items: center; justify-content: center; padding: 20px; }
    .modal-card { background: #fff; width: 100%; max-width: 320px; border-radius: 30px; padding: 40px 20px 30px; text-align: center; position: relative; animation: modalPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    @keyframes modalPop { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
    .modal-close-top { position: absolute; top: -15px; left: 50%; transform: translateX(-50%); width: 32px; height: 32px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); cursor: pointer; border: none; font-weight: 800; color: #94a3b8; }
    
    .status-icon-container { width: 80px; height: 80px; border-radius: 50%; background: var(--primary-soft); margin: 0 auto 25px; display: flex; align-items: center; justify-content: center; position: relative; }
    .status-icon-waves { position: absolute; width: 100%; height: 100%; border-radius: 50%; border: 2px solid var(--primary-soft); animation: waveRipple 2s infinite; }
    @keyframes waveRipple { from { transform: scale(1); opacity: 1; } to { transform: scale(1.5); opacity: 0; } }
    .check-mark { font-size: 32px; color: #000; z-index: 2; }

    .modal-card h2 { font-size: 22px; font-weight: 800; margin: 0 0 8px; color: var(--text); }
    .modal-card p { font-size: 14px; color: #64748b; margin: 0 0 30px; font-weight: 500; }
    
    .btn-listo { background: #1e293b; color: #fff; width: 100%; padding: 16px; border-radius: 16px; font-weight: 700; border: none; cursor: pointer; transition: background 0.2s; }
</style>

<div id="success-modal" class="modal-overlay">
    <div class="modal-card">
        <button class="modal-close-top" onclick="closeSuccessModal()">✕</button>
        <div class="status-icon-container">
            <div class="status-icon-waves"></div>
            <span class="check-mark">✓</span>
        </div>
        <h2>Pedido enviado</h2>
        <p>Buscando delivery disponible</p>
        <button class="btn-listo" onclick="closeSuccessModal()">Listo</button>
    </div>
</div>

<div class="chevron-stepper">
    <div class="chevron-step active" id="step-1-chevron">1. Información</div>
    <div class="chevron-step pending" id="step-2-chevron">2. Verificación</div>
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

        <div class="main-action-btn" onclick="goToStep2()">
            <b>CONTINUAR</b>
        </div>
    </div>

    <!-- PASO 2: VERIFICACIÓN -->
    <div class="form-step" id="step-2">
        <div class="summary-card">
            <!-- 1. Nombre -->
            <p id="v-customer-name" style="margin:0; font-size:18px; font-weight:800; color:var(--text);">-</p>
            
            <!-- 2. Dirección -->
            <div style="margin-top: 12px; display: flex; align-items: flex-start; gap: 8px;">
                <span style="font-size: 16px;">🏠</span>
                <div>
                    <span style="font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; display:block;">Dirección</span>
                    <p id="v-address" style="margin:2px 0 0; font-size:14px; color:var(--text); font-weight:600;">-</p>
                </div>
            </div>

            <!-- 3. Referencia -->
            <div style="margin-top: 10px; display: flex; align-items: flex-start; gap: 8px;">
                <span style="font-size: 16px;">📝</span>
                <div>
                    <span style="font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; display:block;">Referencia</span>
                    <p id="v-ref" style="margin:2px 0 0; font-size:13px; color:#64748b; font-weight:500;">-</p>
                </div>
            </div>

            <!-- 4. Botones Comunicación (Inferior Derecha) -->
            <div class="summary-actions">
                <a href="#" id="v-wa-link" target="_blank" class="action-circle wa-btn">
                    <svg style="width:22px; height:22px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.353-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.191-1.622a11.84 11.84 0 005.854 1.535h.004c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                </a>
                <a href="#" id="v-call-link" class="action-circle call-btn">
                    <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                </a>
            </div>

            <!-- Invisible helper para no romper JS -->
            <span id="v-customer-phone" style="display:none;"></span>
        </div>

        <div id="route-map" class="route-map"></div>

        <!-- Detalles Operativos -->
        <div class="op-card">
            <div class="switch-container">
                <span class="op-title" style="margin-bottom:0;">¿PAGA POR EL PRODUCTO?</span>
                <label class="switch">
                    <input type="checkbox" id="driver_pays_toggle" onchange="toggleProductPay(this.checked)">
                    <span class="slider"></span>
                </label>
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

        <div class="main-action-btn" onclick="document.getElementById('order-form').submit()">
            <div class="action-circle-inline" onclick="event.stopPropagation(); goToStep1()">
                <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"></path></svg>
            </div>
            <b>Costo del viaje Gs. <span id="v-total-trip">0</span></b>
            <div class="action-circle-inline">
                <svg style="width:24px; height:24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path></svg>
            </div>
        </div>
    </div>
</form>

<script>
    mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
    
    toastr.options = {
      "closeButton": false,
      "debug": false,
      "newestOnTop": false,
      "progressBar": false,
      "positionClass": "toast-top-center",
      "preventDuplicates": false,
      "onclick": null,
      "showDuration": "300",
      "hideDuration": "1000",
      "timeOut": "5000",
      "extendedTimeOut": "1000",
      "showEasing": "swing",
      "hideEasing": "linear",
      "showMethod": "fadeIn",
      "hideMethod": "fadeOut"
    };
    
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

    function toggleProductPay(checked) {
        const val = checked ? 'yes' : 'no';
        document.getElementById('driver_pays_val').value = val;
        document.getElementById('product_amount_box').style.display = checked ? 'block' : 'none';
    }

    function goToStep2() {
        const cName = document.getElementById('c_name');
        const cPhone = document.getElementById('c_phone');
        const cAddress = document.getElementById('c_address');
        const cCost = document.getElementById('c_delivery_cost');
        const cRef = document.getElementById('c_ref');

        if (!cName.value || !cAddress.value || !cCost.value) {
            toastr["error"]("Selecciona ubicación.", "¡Atención!");
            return;
        }

        document.getElementById('v-customer-name').innerText = cName.value;
        document.getElementById('v-address').innerText = cAddress.value;
        document.getElementById('v-ref').innerText = cRef.value || 'Sin referencia';
        
        // Configurar enlaces de comunicación
        const cleanPhone = cPhone.value.replace(/\D/g, '');
        const waLink = document.getElementById('v-wa-link');
        const callLink = document.getElementById('v-call-link');
        if (cleanPhone) {
            waLink.href = `https://wa.me/${cleanPhone}`;
            callLink.href = `tel:${cleanPhone}`;
            waLink.style.display = 'flex';
            callLink.style.display = 'flex';
        } else {
            waLink.style.display = 'none';
            callLink.style.display = 'none';
        }
        
        document.getElementById('v-total-trip').innerText = parseInt(cCost.value).toLocaleString('de-DE');

        document.getElementById('step-1').classList.remove('active');
        document.getElementById('step-2').classList.add('active');
        
        // Actualizar Chevron Stepper
        document.getElementById('step-1-chevron').className = 'chevron-step completed';
        document.getElementById('step-2-chevron').className = 'chevron-step active';

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
                routeMap.addLayer({ 'id': 'route', 'type': 'line', 'source': 'route', 'layout': { 'line-join': 'round', 'line-cap': 'round' }, 'paint': { 'line-color': 'var(--primary)', 'line-width': 5, 'line-opacity': 0.75 } });
                const bounds = new mapboxgl.LngLatBounds(originCoords, [dest.lng, dest.lat]);
                routeMap.fitBounds(bounds, { padding: 40 });
            }
        });
    }

    function goToStep1() {
        document.getElementById('step-2').classList.remove('active');
        document.getElementById('step-1').classList.add('active');
        
        // Actualizar Chevron Stepper
        document.getElementById('step-1-chevron').className = 'chevron-step active';
        document.getElementById('step-2-chevron').className = 'chevron-step pending';
    }

    function openGoogleMaps() {
        const dest = marker.getLngLat();
        window.open(`https://www.google.com/maps/search/?api=1&query=${dest.lat},${dest.lng}`, '_blank');
    }

    function handleBack() {
        const step2 = document.getElementById('step-2');
        if (step2.classList.contains('active')) {
            goToStep1();
        } else {
            window.location.href = '../dashboard.php';
        }
    }

    // Lógica del Modal de Éxito
    function showSuccessModal() {
        const modal = document.getElementById('success-modal');
        modal.style.display = 'flex';
        
        // Redirección automática en 3 segundos
        setTimeout(() => {
            window.location.href = '../dashboard.php';
        }, 3000);
    }

    function closeSuccessModal() {
        window.location.href = '../dashboard.php';
    }

    // Detectar éxito en la URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === '1') {
        showSuccessModal();
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
