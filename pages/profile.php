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
    $activeTab = $_POST['active_tab'] ?? 'cuenta';

    if ($name === '') $errors[] = 'El nombre es obligatorio.';

    if ($errors === []) {
        // Guardar datos comunes de cuenta
        app_exec("UPDATE users SET name=?, phone=? WHERE id=?", 'ssi', [$name, $phone, (int) $user['id']]);
        
        if ($pass !== '') {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            app_exec("UPDATE users SET password_hash = ? WHERE id = ?", 'si', [$hash, (int)$user['id']]);
        }

        // Si el usuario es local, actualizar datos comerciales y de geolocalización
        if ($userData['role'] === 'local') {
            $business_name = trim((string) ($_POST['business_name'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $business_reference = trim((string) ($_POST['business_reference'] ?? ''));
            $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
            $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

            app_exec(
                "UPDATE users SET business_name = ?, address = ?, business_reference = ?, latitude = ?, longitude = ? WHERE id = ?",
                'sssddi',
                [$business_name, $address, $business_reference, $latitude, $longitude, (int)$user['id']]
            );
        }

        // Si el usuario es repartidor, procesar subida de documentos
        if ($userData['role'] === 'repartidor') {
            $docs = [
                'doc_ci' => 'doc_ci_path',
                'doc_licencia' => 'doc_licencia_path',
                'doc_habilitacion' => 'doc_habilitacion_path',
                'doc_cedula_verde' => 'doc_cedula_verde_path'
            ];
            
            $uploadDir = __DIR__ . '/../uploads/documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            foreach ($docs as $fileKey => $colName) {
                if (!empty($_FILES[$fileKey]['name'])) {
                    $ext = pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION);
                    $fileName = 'doc_' . $fileKey . '_' . $user['id'] . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $fileName)) {
                        app_exec("UPDATE users SET {$colName} = ? WHERE id = ?", 'si', ['uploads/documents/' . $fileName, (int)$user['id']]);
                    }
                }
            }
        }
        
        header("Location: profile.php?toast=updated&tab=" . urlencode($activeTab)); exit;
    }
}

$title = 'Perfil';
require __DIR__ . '/_header.php';
?>
<?php if ($userData['role'] === 'local'): ?>
<!-- Mapbox GL JS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.2.0/mapbox-gl.js"></script>
<?php endif; ?>

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

    .profile-avatar-wrapper {
        position: relative;
        width: 100px;
        height: 100px;
        margin: 0 auto 16px;
    }
    .profile-avatar-center {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        border: 4px solid #ffffff;
        box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        background: #fff;
        overflow: hidden;
    }
    .profile-avatar-center img { width: 100%; height: 100%; object-fit: cover; }
    .profile-avatar-center .placeholder { font-size: 40px; line-height: 92px; }

    .edit-badge-overlay {
        position: absolute;
        bottom: -6px;
        right: -6px;
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
        z-index: 10;
        transition: transform 0.2s ease;
    }
    .edit-badge-overlay:hover {
        transform: scale(1.1);
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

    /* Success Modal Style */
    .modal-overlay { 
        position: fixed; 
        top: 0; left: 0; right: 0; bottom: 0; 
        background: rgba(15, 23, 42, 0.4); 
        backdrop-filter: blur(8px); 
        -webkit-backdrop-filter: blur(8px);
        z-index: 3000; 
        display: none; 
        align-items: center; 
        justify-content: center; 
        padding: 20px; 
    }
    .modal-card { 
        background: #ffffff; 
        width: 100%; 
        max-width: 320px; 
        border-radius: 28px; 
        padding: 40px 24px 30px; 
        text-align: center; 
        position: relative; 
        border-top: 6px solid var(--primary);
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.15);
        animation: modalPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
    }
    @keyframes modalPop { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
    .modal-close-top { 
        position: absolute; 
        top: -16px; left: 50%; 
        transform: translateX(-50%); 
        width: 32px; height: 32px; 
        background: #ffffff; 
        border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; 
        box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
        cursor: pointer; 
        border: 1px solid rgba(0,0,0,0.03); 
        font-weight: 800; 
        color: #94a3b8; 
        transition: transform 0.2s;
    }
    .modal-close-top:active { transform: translateX(-50%) scale(0.9); }
    
    .status-icon-container { 
        width: 80px; height: 80px; 
        border-radius: 50%; 
        background: var(--primary-soft); 
        margin: 0 auto 25px; 
        display: flex; align-items: center; justify-content: center; 
        position: relative; 
    }
    .status-icon-waves { 
        position: absolute; 
        width: 100%; height: 100%; 
        border-radius: 50%; 
        border: 2px solid var(--primary-soft); 
        animation: waveRipple 2s infinite; 
    }
    @keyframes waveRipple { from { transform: scale(1); opacity: 1; } to { transform: scale(1.6); opacity: 0; } }
    .check-mark { font-size: 36px; color: var(--primary); font-weight: 800; z-index: 2; }

    .modal-card h2 { font-size: 22px; font-weight: 800; margin: 0 0 8px; color: var(--text); letter-spacing: -0.5px; }
    .modal-card p { font-size: 14px; color: var(--muted); margin: 0 0 30px; font-weight: 600; }
    
    .btn-listo { 
        background: var(--primary); 
        color: #ffffff; 
        width: 100%; 
        padding: 16px; 
        border-radius: 16px; 
        font-weight: 700; 
        border: none; 
        cursor: pointer; 
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.2); 
        transition: all 0.2s; 
    }
    .btn-listo:active { transform: scale(0.97); opacity: 0.95; }

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
    
    /* Premium Upload Cards - Now as distinct Cards */
    .upload-card-interactive {
        display: flex; align-items: center; justify-content: space-between;
        background: #fff; border-radius: 20px;
        padding: 20px; margin-bottom: 12px;
        color: var(--text); cursor: pointer;
        border: 1px solid rgba(0,0,0,0.05);
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        transition: transform 0.2s ease;
        white-space: nowrap;
    }
    .upload-card-interactive:active { transform: scale(0.98); }
    .user-icon { font-size: 24px; margin-right: 15px; flex-shrink: 0; }
    .arrow-icon { font-size: 18px; font-weight: bold; flex-shrink: 0; margin-left: 10px; color: var(--muted); }
    
    /* Translucent blue style with subtle sweep shimmer */
    .upload-card-interactive.blue-shimmer {
        position: relative;
        overflow: hidden;
        background: rgba(37, 99, 235, 0.08);
        border: 1px solid rgba(37, 99, 235, 0.15);
        color: var(--primary);
    }
    .upload-card-interactive.blue-shimmer .arrow-icon {
        color: var(--primary);
    }
    .upload-card-interactive.blue-shimmer::after {
        content: '';
        position: absolute;
        top: 0;
        left: -150%;
        width: 60%;
        height: 100%;
        background: linear-gradient(
            to right,
            rgba(255, 255, 255, 0) 0%,
            rgba(255, 255, 255, 0.45) 50%,
            rgba(255, 255, 255, 0) 100%
        );
        transform: skewX(-25deg);
        animation: shineSweep 3.5s infinite ease-in-out;
    }
    @keyframes shineSweep {
        0% { left: -150%; }
        40% { left: 150%; }
        100% { left: 150%; }
    }
    
    /* Uploaded document state styling */
    .upload-card-interactive.uploaded {
        background: rgba(16, 185, 129, 0.05);
        border: 1px solid rgba(16, 185, 129, 0.15);
        color: #10b981;
    }
    .upload-card-interactive.uploaded .arrow-icon {
        color: #10b981;
        opacity: 0.7;
    }
    
    /* Incomplete document state styling */
    .upload-card-interactive.incomplete {
        background: rgba(245, 158, 11, 0.05);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: #d97706;
    }
    .upload-card-interactive.incomplete .arrow-icon {
        color: #d97706;
        opacity: 0.7;
    }
</style>

<div class="profile-hero">
    <div class="hero-cover"></div>
    <div class="hero-content">
        <div class="profile-avatar-wrapper">
            <div class="profile-avatar-center">
                <?php if (!empty($userData['logo_path'])): ?>
                    <img src="<?= esc(delivery_app_url($userData['logo_path'])) ?>?v=<?= time() ?>" id="avatar-preview">
                <?php else: ?>
                    <div class="placeholder">🏢</div>
                <?php endif; ?>
            </div>
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
    <?php if ($userData['role'] === 'local'): ?>
        <button type="button" class="segment-btn" onclick="switchTab('local')">Mi Negocio</button>
    <?php else: ?>
        <button type="button" class="segment-btn" onclick="switchTab('documentos')">Documentos</button>
    <?php endif; ?>
</div>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="active_tab" id="active-tab-input" value="cuenta">
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

    <!-- Tab 2: Local o Documentos dependiendo del Rol -->
    <?php if ($userData['role'] === 'local'): ?>
        <div id="tab-local" class="tab-content">
            <div class="card" style="border:none; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                <!-- Nombre del Local -->
                <div class="form-group">
                    <label class="muted" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Nombre del Local</label>
                    <div class="input-wrapper" style="margin-top: 5px;">
                        <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.12 12.42V6.368M3 21V9.349m0 0L8.852 3.7a1.5 1.5 0 0 1 2.037.22l5.852 5.43m-13.74 0h13.74m0 0v11.261"></path></svg>
                        <input name="business_name" value="<?= esc($userData['business_name']) ?>" placeholder="Nombre del local" required>
                    </div>
                </div>

                <!-- Teléfono del Local -->
                <div class="form-group">
                    <label class="muted" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Teléfono del Local</label>
                    <div class="input-wrapper" style="margin-top: 5px;">
                        <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                        <input type="tel" name="phone" value="<?= esc($userData['phone']) ?>" placeholder="Teléfono del local">
                    </div>
                </div>

                <!-- Dirección física del Local -->
                <div class="form-group">
                    <label class="muted" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Dirección del Local</label>
                    <div class="input-wrapper" style="margin-top: 5px;">
                        <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"></path></svg>
                        <input name="address" id="local_address" value="<?= esc($userData['address']) ?>" placeholder="Dirección física del local">
                    </div>
                </div>

                <!-- Referencia o Indicaciones -->
                <div class="form-group">
                    <label class="muted" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Referencia o Indicaciones del Local</label>
                    <div class="input-wrapper" style="margin-top: 5px;">
                        <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"></path></svg>
                        <input name="business_reference" value="<?= esc($userData['business_reference']) ?>" placeholder="Ej: Portón azul al lado del súper">
                    </div>
                </div>

                <!-- Ubicación en el Mapa -->
                <div class="form-group">
                    <label class="muted" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Ubicación en el Mapa</label>
                    <div id="local-map" style="height: 200px; border-radius: 16px; margin-top: 8px; border: 1px solid var(--border);"></div>
                    <input type="hidden" name="latitude" id="local_lat" value="<?= esc($userData['latitude']) ?>">
                    <input type="hidden" name="longitude" id="local_lng" value="<?= esc($userData['longitude']) ?>">
                </div>

                <button type="submit" class="btn btn-save-tech">💾 Guardar Cambios</button>
            </div>
        </div>
    <?php else: ?>
        <div id="tab-documentos" class="tab-content">
            <div class="card" style="border:none; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                
                <!-- Cédula de Identidad -->
                <?php
                $has_ci_front = !empty($userData['doc_ci_path']);
                $has_ci_back = !empty($userData['doc_ci_back_path']);
                
                $ci_status_class = 'blue-shimmer';
                if ($has_ci_front && $has_ci_back) {
                    $ci_status_class = 'uploaded';
                } elseif ($has_ci_front || $has_ci_back) {
                    $ci_status_class = 'incomplete';
                }
                ?>
                <div class="upload-card-interactive <?= $ci_status_class ?>" id="card-doc_ci" onclick="window.location.href='upload_id.php'">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                        <div style="display: flex; align-items: center;">
                            <span class="user-icon">👤</span>
                            <div style="display: flex; flex-direction: column;">
                                <span style="font-weight: 800; font-size: 15px; color: var(--text);">Cédula de Identidad</span>
                                <span id="status-doc_ci" style="font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                                    <?php if ($has_ci_front && $has_ci_back): ?>
                                        <span style="color: #10b981; display: flex; align-items: center; gap: 4px;">
                                            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            ✓ Documento guardado
                                        </span>
                                    <?php elseif ($has_ci_front || $has_ci_back): ?>
                                        <span style="color: #d97706; display: flex; align-items: center; gap: 4px;">
                                            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                            ⚠️ Incompleto (Falta un lado)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #64748b;">No seleccionado</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="arrow-icon">></span>
                        </div>
                    </div>
                </div>

                <!-- Registro de Conducir -->
                <?php
                $licencia_saved = !empty($userData['doc_licencia_path']);
                $licencia_url = $licencia_saved ? esc(delivery_app_url($userData['doc_licencia_path'])) : '';
                ?>
                <div class="upload-card-interactive <?= $licencia_saved ? 'uploaded' : 'blue-shimmer' ?>" id="card-doc_licencia" onclick="document.getElementById('input-doc_licencia').click()">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                        <div style="display: flex; align-items: center;">
                            <span class="user-icon">🚗</span>
                            <div style="display: flex; flex-direction: column;">
                                <span style="font-weight: 800; font-size: 15px; color: var(--text);">Registro de conducir</span>
                                <span id="status-doc_licencia" style="font-size: 12px; color: <?= $licencia_saved ? '#10b981' : '#64748b' ?>; font-weight: 700; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                                    <?php if ($licencia_saved): ?>
                                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        ✓ Documento guardado
                                    <?php else: ?>
                                        No seleccionado
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <?php if ($licencia_saved): ?>
                                <a href="<?= $licencia_url ?>" target="_blank" onclick="event.stopPropagation();" class="btn-ver-doc" style="background: rgba(37, 99, 235, 0.1); color: var(--primary); padding: 6px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;">🔍 VER</a>
                            <?php endif; ?>
                            <span class="arrow-icon">></span>
                        </div>
                    </div>
                </div>
                <input type="file" name="doc_licencia" id="input-doc_licencia" style="display:none;" onchange="handleFileSelected(this, 'status-doc_licencia', 'card-doc_licencia')">

                <!-- Habilitación -->
                <?php
                $habilitacion_saved = !empty($userData['doc_habilitacion_path']);
                $habilitacion_url = $habilitacion_saved ? esc(delivery_app_url($userData['doc_habilitacion_path'])) : '';
                ?>
                <div class="upload-card-interactive <?= $habilitacion_saved ? 'uploaded' : 'blue-shimmer' ?>" id="card-doc_habilitacion" onclick="document.getElementById('input-doc_habilitacion').click()">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                        <div style="display: flex; align-items: center;">
                            <span class="user-icon">📄</span>
                            <div style="display: flex; flex-direction: column;">
                                <span style="font-weight: 800; font-size: 15px; color: var(--text);">Habilitación</span>
                                <span id="status-doc_habilitacion" style="font-size: 12px; color: <?= $habilitacion_saved ? '#10b981' : '#64748b' ?>; font-weight: 700; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                                    <?php if ($habilitacion_saved): ?>
                                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        ✓ Documento guardado
                                    <?php else: ?>
                                        No seleccionado
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <?php if ($habilitacion_saved): ?>
                                <a href="<?= $habilitacion_url ?>" target="_blank" onclick="event.stopPropagation();" class="btn-ver-doc" style="background: rgba(37, 99, 235, 0.1); color: var(--primary); padding: 6px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;">🔍 VER</a>
                            <?php endif; ?>
                            <span class="arrow-icon">></span>
                        </div>
                    </div>
                </div>
                <input type="file" name="doc_habilitacion" id="input-doc_habilitacion" style="display:none;" onchange="handleFileSelected(this, 'status-doc_habilitacion', 'card-doc_habilitacion')">

                <!-- Cédula Verde -->
                <?php
                $cedula_verde_saved = !empty($userData['doc_cedula_verde_path']);
                $cedula_verde_url = $cedula_verde_saved ? esc(delivery_app_url($userData['doc_cedula_verde_path'])) : '';
                ?>
                <div class="upload-card-interactive <?= $cedula_verde_saved ? 'uploaded' : 'blue-shimmer' ?>" id="card-doc_cedula_verde" onclick="document.getElementById('input-doc_cedula_verde').click()">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                        <div style="display: flex; align-items: center;">
                            <span class="user-icon">🚙</span>
                            <div style="display: flex; flex-direction: column;">
                                <span style="font-weight: 800; font-size: 15px; color: var(--text);">Cédula verde</span>
                                <span id="status-doc_cedula_verde" style="font-size: 12px; color: <?= $cedula_verde_saved ? '#10b981' : '#64748b' ?>; font-weight: 700; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                                    <?php if ($cedula_verde_saved): ?>
                                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        ✓ Documento guardado
                                    <?php else: ?>
                                        No seleccionado
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <?php if ($cedula_verde_saved): ?>
                                <a href="<?= $cedula_verde_url ?>" target="_blank" onclick="event.stopPropagation();" class="btn-ver-doc" style="background: rgba(37, 99, 235, 0.1); color: var(--primary); padding: 6px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;">🔍 VER</a>
                            <?php endif; ?>
                            <span class="arrow-icon">></span>
                        </div>
                    </div>
                </div>
                <input type="file" name="doc_cedula_verde" id="input-doc_cedula_verde" style="display:none;" onchange="handleFileSelected(this, 'status-doc_cedula_verde', 'card-doc_cedula_verde')">

                <button type="submit" class="btn btn-save-tech">💾 Guardar Cambios</button>
            </div>
        </div>
    <?php endif; ?>
</form>

<div id="success-modal" class="modal-overlay">
    <div class="modal-card">
        <button type="button" class="modal-close-top" onclick="closeSuccessModal()">✕</button>
        <div class="status-icon-container">
            <div class="status-icon-waves"></div>
            <span class="check-mark">✓</span>
        </div>
        <h2 id="success-modal-title">¡Perfil actualizado!</h2>
        <p id="success-modal-message">Tus cambios se han guardado con éxito.</p>
        <button type="button" class="btn-listo" onclick="closeSuccessModal()">Listo</button>
    </div>
</div>

<script>
    function switchTab(tab) {
        document.querySelectorAll('.segment-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        // Actualizar el campo oculto con la pestaña activa
        const activeTabInput = document.getElementById('active-tab-input');
        if (activeTabInput) {
            activeTabInput.value = tab;
        }

        // Buscar y activar el botón de segmento programáticamente
        let targetBtn = null;
        document.querySelectorAll('.segment-btn').forEach(b => {
            if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + tab + "'")) {
                targetBtn = b;
            }
        });

        if (targetBtn) {
            targetBtn.classList.add('active');
        } else if (typeof event !== 'undefined' && event && event.currentTarget) {
            event.currentTarget.classList.add('active');
        }
        
        // El tab id de documentos es tab-documentos, el de local es tab-local
        if (tab === 'local') {
            document.getElementById('tab-local').classList.add('active');
        } else if (tab === 'documentos') {
            document.getElementById('tab-documentos').classList.add('active');
        } else {
            document.getElementById('tab-' + tab).classList.add('active');
        }
        
        // Redimensionar el mapa si se activa la pestaña del local
        if (tab === 'local' && typeof localMap !== 'undefined') {
            setTimeout(() => { localMap.resize(); }, 100);
        }
    }

    function togglePass() {
        const field = document.getElementById('pass-field');
        field.type = field.type === 'password' ? 'text' : 'password';
    }

    function handleFileSelected(input, statusId, cardId) {
        if (input.files && input.files.length > 0) {
            const file = input.files[0];
            const statusEl = document.getElementById(statusId);
            const cardEl = document.getElementById(cardId);
            
            statusEl.innerHTML = `<span style="color: #2563eb; display: flex; align-items: center; gap: 4px;">
                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                📂 Listo: ${file.name}
            </span>`;
            
            cardEl.classList.remove('blue-shimmer');
            cardEl.classList.remove('uploaded');
            cardEl.style.border = '1px dashed #2563eb';
            cardEl.style.background = 'rgba(37, 99, 235, 0.03)';
        }
    }

    const urlParams = new URLSearchParams(window.location.search);
    
    // Restaurar pestaña activa si se especificó en la URL
    const tabParam = urlParams.get('tab');
    if (tabParam) {
        switchTab(tabParam);
    }

    if (urlParams.has('toast')) {
        const modal = document.getElementById('success-modal');
        const titleEl = document.getElementById('success-modal-title');
        const msgEl = document.getElementById('success-modal-message');
        
        if (urlParams.get('toast') === 'logo') {
            titleEl.innerText = '¡Logotipo actualizado!';
            msgEl.innerText = 'El logotipo de tu negocio ha sido actualizado con éxito.';
        } else {
            titleEl.innerText = '¡Perfil actualizado!';
            msgEl.innerText = 'Tus cambios se han guardado con éxito.';
        }
        
        modal.style.display = 'flex';
        
        // Reproducir sonido de éxito
        const successAudio = new Audio('<?= esc(delivery_app_url("uploads/sounds/success.mp3")) ?>');
        successAudio.play().catch(err => {
            console.log("Audio playback prevented:", err);
        });
        
        // El modal dura exactamente 5 segundos
        setTimeout(() => {
            closeSuccessModal();
        }, 5000);
    }

    function closeSuccessModal() {
        // Remover el parámetro 'toast' del URL para evitar que aparezca de nuevo al refrescar
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
        document.getElementById('success-modal').style.display = 'none';
    }

    // Inicializar mapa de Mapbox para el comercio (Local)
    let localMap;
    let localMarker;
    
    <?php if ($userData['role'] === 'local'): ?>
    mapboxgl.accessToken = 'pk.eyJ1IjoiYW5kZXJsb3AiLCJhIjoiY21uMGJ1ZXhzMGkxMDJycHRuYzEwcmp4NCJ9.Jn4uXN5yX4DFIImQjw_R4w';
    
    const initialLat = <?= (float)($userData['latitude'] ?? -25.2637) ?>;
    const initialLng = <?= (float)($userData['longitude'] ?? -57.6359) ?>;
    
    localMap = new mapboxgl.Map({
        container: 'local-map',
        style: 'mapbox://styles/mapbox/streets-v12',
        center: [initialLng, initialLat],
        zoom: 14
    });
    
    localMap.on('load', () => {
        const el = document.createElement('div');
        el.innerHTML = '📍'; el.style.fontSize = '32px'; el.style.cursor = 'pointer';
        
        localMarker = new mapboxgl.Marker({ draggable: true, element: el })
            .setLngLat([initialLng, initialLat])
            .addTo(localMap);
            
        localMarker.on('dragend', () => {
            const coords = localMarker.getLngLat();
            document.getElementById('local_lat').value = coords.lat;
            document.getElementById('local_lng').value = coords.lng;
        });
        
        localMap.on('click', (e) => {
            localMarker.setLngLat(e.lngLat);
            document.getElementById('local_lat').value = e.lngLat.lat;
            document.getElementById('local_lng').value = e.lngLat.lng;
        });
    });
    <?php endif; ?>
</script>

<?php require __DIR__ . '/_footer.php'; ?>
