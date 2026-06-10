<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
$userData = app_one('SELECT * FROM users WHERE id = ?', 'i', [(int) $user['id']]);

$errors = [];
$ok = '';

// Lógica de Subida de Logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_logo') {
    if (!empty($_FILES['logo']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/logos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $fileName = 'logo_' . $user['id'] . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $fileName)) {
            app_exec("UPDATE users SET logo_path = ? WHERE id = ?", 'si', ['uploads/logos/' . $fileName, (int)$user['id']]);
            header('Location: profile.php?toast=logo'); exit;
        }
    }
}

// Lógica de Actualización de Perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    
    $bName = trim((string) ($_POST['business_name'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $ref = trim((string) ($_POST['business_reference'] ?? ''));
    $lat = $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null;
    $lng = $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null;

    if ($name === '') $errors[] = 'El nombre es obligatorio.';

    if ($errors === []) {
        app_exec("UPDATE users SET name=?, phone=?, business_name=?, whatsapp=?, address=?, business_reference=?, latitude=?, longitude=? WHERE id=?",
            'ssssssddi', [$name, $phone, $bName, $whatsapp, $address, $ref, $lat, $lng, (int) $user['id']]);
        
        if ($pass !== '') {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            app_exec("UPDATE users SET password_hash = ? WHERE id = ?", 'si', [$hash, (int)$user['id']]);
        }
        
        header('Location: profile.php?toast=updated'); exit;
    }
}

$title = 'Perfil';
require __DIR__ . '/_header.php';
?>

<!-- Mapbox GL JS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.js"></script>

<style>
    /* Profile Hero Header */
    .profile-hero {
        position: relative;
        margin: -25px -20px 30px -20px;
        padding: 50px 20px 20px;
        text-align: center;
        overflow: hidden;
        background: #fff;
    }
    
    .hero-cover {
        position: absolute;
        top: 0; left: 0; right: 0; height: 160px;
        background: url('<?= !empty($userData['logo_path']) ? esc(delivery_app_url($userData['logo_path'])) : 'https://images.unsplash.com/photo-1513104890138-7c749659a591?q=80&w=1000&auto=format&fit=crop' ?>');
        background-size: cover;
        background-position: center;
        filter: blur(25px) brightness(0.85);
        transform: scale(1.2);
        z-index: 1;
    }
    
    .hero-cover::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, rgba(255, 255, 255, 0) 0%, var(--bg) 100%);
    }

    .hero-content { position: relative; z-index: 2; }

    .profile-avatar-center {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        border: 4px solid #ffffff;
        box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        margin: 0 auto 16px;
        background: #fff;
        overflow: hidden;
        position: relative;
    }
    .profile-avatar-center img { width: 100%; height: 100%; object-fit: cover; }
    .profile-avatar-center .placeholder { font-size: 40px; line-height: 92px; }

    .edit-badge-overlay {
        position: absolute;
        bottom: 5px;
        right: 0;
        background: var(--primary);
        color: #fff;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 3px solid #fff;
        cursor: pointer;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        z-index: 3;
    }

    .profile-name-box { display: flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 4px; }
    .profile-name-box h2 { font-size: 24px; font-weight: 800; color: var(--text); margin: 0; }
    
    .verified-badge {
        background: var(--primary);
        color: #fff;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: bold;
        flex-shrink: 0;
        box-shadow: 0 2px 10px rgba(37, 99, 235, 0.3);
    }

    /* Segmented Control Bento Style */
    .segmented-control-tech {
        display: flex;
        background: #f1f5f9;
        padding: 6px;
        border-radius: 18px;
        margin-bottom: 30px;
        border: 1px solid rgba(0,0,0,0.02);
    }
    .segment-btn {
        flex: 1;
        padding: 12px;
        border: 0;
        background: transparent;
        font-weight: 800;
        font-size: 13px;
        color: #94a3b8;
        cursor: pointer;
        border-radius: 14px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .segment-btn.active {
        background: #fff;
        color: var(--primary);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    .tab-content { display: none; }
    .tab-content.active { display: block; animation: slideIn 0.3s ease-out; }
    @keyframes slideIn { from { opacity: 0; transform: translateX(15px); } to { opacity: 1; transform: translateX(0); } }

    /* Minimal Tech Form */
    .form-group { margin-bottom: 18px; }
    .input-wrapper { position: relative; }
    .input-wrapper input { padding-left: 48px; border-radius: 16px; font-weight: 600; }
    .field-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        width: 18px;
        height: 18px;
        color: var(--primary);
        opacity: 0.8;
    }
    .toggle-pass {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        cursor: pointer;
    }

    /* Toast Notification Bento Style */
    .toast-tech {
        position: fixed;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%);
        background: #1e293b;
        color: #fff;
        padding: 14px 28px;
        border-radius: 20px;
        font-weight: 700;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        z-index: 3000;
        display: none;
        animation: toastPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    @keyframes toastPop { from { bottom: 70px; opacity: 0; } to { bottom: 100px; opacity: 1; } }

    #map { height: 220px; border-radius: 24px; margin-bottom: 15px; border: 1px solid var(--border); box-shadow: var(--shadow); }
    
    .btn-save-tech { width: 100%; margin-top: 15px; box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25); }
    .btn-logout-tech { 
        background: #ffffff; 
        color: var(--danger); 
        border: 1px solid rgba(239, 68, 68, 0.2); 
        box-shadow: none; 
        margin-top: 30px; 
        width: 100%; 
        padding: 14px;
        font-size: 15px;
        font-weight: 700;
    }
    .btn-logout-tech:active { background: #fef2f2; }
</style>

<div class="profile-hero">
    <div class="hero-cover"></div>
    <div class="hero-content">
        <div class="profile-avatar-center">
            <?php if (!empty($userData['logo_path'])): ?>
                <img src="<?= esc(delivery_app_url($userData['logo_path'])) ?>?v=<?= time() ?>" id="avatar-preview">
            <?php else: ?>
                <div class="placeholder">🏢</div>
            <?php endif; ?>
            <label for="logo-input" class="edit-badge-overlay">
                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
            </label>
        </div>
        <form id="logo-form" method="post" enctype="multipart/form-data" style="display:none;">
            <input type="file" id="logo-input" name="logo" onchange="document.getElementById('logo-form').submit()">
            <input type="hidden" name="action" value="update_logo">
        </form>
        <div class="profile-name-box">
            <h2><?= esc($userData['name']) ?></h2>
            <div class="verified-badge" title="Cuenta Verificada">✓</div>
        </div>
        <p class="muted" style="font-weight: 700; text-transform: uppercase; font-size: 10px; letter-spacing: 1px;"><?= strtoupper($userData['role']) ?></p>
    </div>
</div>

<div class="segmented-control-tech">
    <button type="button" class="segment-btn active" onclick="switchTab('cuenta')">Mi Cuenta</button>
    <button type="button" class="segment-btn" onclick="switchTab('local')">Mi Negocio</button>
</div>

<form method="post">
    <!-- Tab 1: Cuenta -->
    <div id="tab-cuenta" class="tab-content active">
        <div class="card" style="border:none; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <input name="name" value="<?= esc($userData['name']) ?>" placeholder="Nombre completo" required>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    <input value="<?= esc($userData['email']) ?>" readonly style="background:var(--bg); color:#94a3b8; border:none;" title="El email no puede ser modificado">
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <input type="password" name="password" id="pass-field" placeholder="Nueva contraseña (opcional)">
                    <div class="toggle-pass" onclick="togglePass()">
                        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    <input name="phone" value="<?= esc($userData['phone']) ?>" placeholder="Teléfono móvil">
                </div>
            </div>

            <button type="submit" class="btn btn-save-tech">💾 Guardar Perfil</button>

            <a href="<?= esc(delivery_app_url('logout.php')) ?>" class="btn btn-logout-tech">
                🚪 Cerrar Sesión
            </a>
        </div>
    </div>

    <!-- Tab 2: Local -->
    <div id="tab-local" class="tab-content">
        <div class="card" style="border:none; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    <input name="business_name" value="<?= esc($userData['business_name']) ?>" placeholder="Nombre del negocio">
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                    <input name="whatsapp" value="<?= esc($userData['whatsapp']) ?>" placeholder="WhatsApp de contacto">
                </div>
            </div>

            <div id="map"></div>
            <input type="hidden" name="latitude" id="lat" value="<?= esc($userData['latitude']) ?>">
            <input type="hidden" name="longitude" id="lng" value="<?= esc($userData['longitude']) ?>">
            <p class="muted" style="font-size: 11px; text-align: center; margin-bottom: 20px; font-weight: 700;">📍 Arrastra el pin para fijar tu ubicación</p>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <input name="address" value="<?= esc($userData['address']) ?>" placeholder="Dirección exacta">
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <input name="business_reference" value="<?= esc($userData['business_reference']) ?>" placeholder="Referencias del local">
                </div>
            </div>

            <button type="submit" class="btn btn-save-tech">💾 Guardar Cambios</button>
        </div>
    </div>
</form>

<div id="toast" class="toast-tech">¡Perfil actualizado!</div>

<script>
    mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
    
    let map, marker;
    const initialCoords = [<?= (float)($userData['longitude'] ?? -57.6359) ?>, <?= (float)($userData['latitude'] ?? -25.2637) ?>];

    function initMap() {
        if (map) return;
        map = new mapboxgl.Map({
            container: 'map',
            style: 'mapbox://styles/mapbox/streets-v12',
            center: initialCoords,
            zoom: 14
        });
        map.on('load', () => {
            const el = document.createElement('div');
            el.innerHTML = '📍'; el.style.fontSize = '32px'; el.style.cursor = 'pointer';
            marker = new mapboxgl.Marker({ draggable: true, element: el }).setLngLat(initialCoords).addTo(map);
            function updateCoords() {
                const lngLat = marker.getLngLat();
                document.getElementById('lat').value = lngLat.lat.toFixed(6);
                document.getElementById('lng').value = lngLat.lng.toFixed(6);
            }
            marker.on('dragend', updateCoords);
            map.on('click', (e) => { marker.setLngLat(e.lngLat); updateCoords(); });
        });
    }

    function switchTab(tab) {
        document.querySelectorAll('.segment-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');
        if (tab === 'local') {
            setTimeout(() => { initMap(); if (map) map.resize(); }, 100);
        }
    }

    function togglePass() {
        const field = document.getElementById('pass-field');
        field.type = field.type === 'password' ? 'text' : 'password';
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('toast')) {
        const toast = document.getElementById('toast');
        if (urlParams.get('toast') === 'logo') toast.innerText = '¡Logotipo actualizado!';
        toast.style.display = 'block';
        setTimeout(() => toast.style.display = 'none', 3000);
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
