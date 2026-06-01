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

<style>
    .profile-container { max-width: 500px; margin: 0 auto; }
    
    /* Segmented Control */
    .segmented-control {
        display: flex;
        background: #f1f5f9;
        padding: 4px;
        border-radius: 14px;
        margin-bottom: 30px;
    }
    .segment-btn {
        flex: 1;
        padding: 12px;
        border: 0;
        background: transparent;
        font-weight: 700;
        font-size: 14px;
        color: #64748b;
        cursor: pointer;
        border-radius: 10px;
        transition: all 0.2s;
    }
    .segment-btn.active {
        background: #fff;
        color: var(--primary);
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    }

    .tab-content { display: none; }
    .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    /* Forms */
    .profile-id-section { text-align: center; margin-bottom: 30px; position: relative; }
    .avatar-container {
        width: 100px;
        height: 100px;
        margin: 0 auto 15px;
        position: relative;
    }
    .profile-avatar {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--primary);
        background: #fff;
    }
    .edit-badge {
        position: absolute;
        bottom: 0;
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
    }

    .form-group { margin-bottom: 20px; }
    .input-wrapper { position: relative; }
    .input-wrapper input { padding-left: 45px; }
    .field-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        width: 20px;
        height: 20px;
        color: var(--primary);
    }
    .toggle-pass {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        cursor: pointer;
    }

    /* Toast Notification */
    .toast {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--primary);
        color: #fff;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 700;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
        z-index: 2000;
        display: none;
    }

    /* Mapbox Placeholder */
    #map { height: 200px; border-radius: 16px; margin-bottom: 15px; border: 1px solid var(--border); }
</style>

<div class="profile-container">
    <div class="profile-id-section">
        <div class="avatar-container">
            <?php if (!empty($userData['logo_path'])): ?>
                <img src="<?= esc(delivery_app_url($userData['logo_path'])) ?>?v=<?= time() ?>" class="profile-avatar" id="avatar-preview">
            <?php else: ?>
                <div class="profile-avatar" style="display:flex; align-items:center; justify-content:center; background:#f1f5f9;">
                    <svg style="width: 40px; height: 40px; color: #cbd5e1;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                </div>
            <?php endif; ?>
            <label for="logo-input" class="edit-badge">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
            </label>
        </div>
        <form id="logo-form" method="post" enctype="multipart/form-data" style="display:none;">
            <input type="file" id="logo-input" name="logo" onchange="document.getElementById('logo-form').submit()">
            <input type="hidden" name="action" value="update_logo">
        </form>
        <h3><?= esc($userData['name']) ?></h3>
        <p class="muted" style="margin-top: -10px;"><?= strtoupper($userData['role']) ?></p>
    </div>

    <div class="segmented-control">
        <button type="button" class="segment-btn active" onclick="switchTab('cuenta')">Cuenta</button>
        <button type="button" class="segment-btn" onclick="switchTab('local')">Local</button>
    </div>

    <form method="post">
        <!-- Tab 1: Cuenta -->
        <div id="tab-cuenta" class="tab-content active">
            <div class="form-group">
                <label>Nombre de Usuario</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <input name="name" value="<?= esc($userData['name']) ?>" placeholder="Tu nombre completo" required>
                </div>
            </div>

            <div class="form-group">
                <label>Email (No editable)</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    <input value="<?= esc($userData['email']) ?>" readonly style="background:#f8fafc; color:#94a3b8;">
                </div>
            </div>

            <div class="form-group">
                <label>Nueva Contraseña</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <input type="password" name="password" id="pass-field" placeholder="Dejar en blanco para no cambiar">
                    <div class="toggle-pass" onclick="togglePass()">
                        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-icon"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Móvil Personal</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    <input name="phone" value="<?= esc($userData['phone']) ?>" placeholder="Ej: +595981...">
                </div>
            </div>

            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); text-align: center;">
                <p class="muted" style="margin-bottom: 15px;">¿Deseas salir de tu cuenta?</p>
                <a href="<?= esc(delivery_app_url('logout.php')) ?>" class="btn btn-danger" style="width: 100%; padding: 15px; font-size: 16px; background: #be123c;">
                    🚪 Cerrar Sesión
                </a>
            </div>
        </div>

        <!-- Tab 2: Local -->
        <div id="tab-local" class="tab-content">
            <div class="form-group">
                <label>Nombre Comercial / Tienda</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    <input name="business_name" value="<?= esc($userData['business_name']) ?>" placeholder="Nombre de tu negocio">
                </div>
            </div>

            <div class="form-group">
                <label>WhatsApp del Negocio</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                    <input name="whatsapp" value="<?= esc($userData['whatsapp']) ?>" placeholder="Ej: +595981...">
                </div>
            </div>

            <div class="form-group">
                <label>Ubicación Base</label>
                <div id="map"></div>
                <input type="hidden" name="latitude" id="lat" value="<?= esc($userData['latitude']) ?>">
                <input type="hidden" name="longitude" id="lng" value="<?= esc($userData['longitude']) ?>">
                <p class="muted" style="font-size: 11px;">Mueve el mapa para ajustar tu ubicación base.</p>
            </div>

            <div class="form-group">
                <label>Dirección Escrita</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <input name="address" value="<?= esc($userData['address']) ?>" placeholder="Calle, número, barrio...">
                </div>
            </div>

            <div class="form-group">
                <label>Referencia</label>
                <div class="input-wrapper">
                    <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <input name="business_reference" value="<?= esc($userData['business_reference']) ?>" placeholder="Ej: Frente a la plaza, portón azul...">
                </div>
            </div>

            <button type="submit" style="width: 100%; margin-top: 10px;">💾 Guardar Cambios</button>
        </div>
    </form>
</div>

<div id="toast" class="toast">¡Perfil actualizado!</div>

<script>
    function switchTab(tab) {
        document.querySelectorAll('.segment-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');

        if (tab === 'local') {
            setTimeout(() => {
                // Forzar refresco de mapa si es necesario
            }, 100);
        }
    }

    function togglePass() {
        const field = document.getElementById('pass-field');
        field.type = field.type === 'password' ? 'text' : 'password';
    }

    // Show toast if needed
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('toast')) {
        const toast = document.getElementById('toast');
        if (urlParams.get('toast') === 'logo') toast.innerText = '¡Logotipo actualizado!';
        toast.style.display = 'block';
        setTimeout(() => toast.style.display = 'none', 3000);
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
