<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] !== 'repartidor') {
    header('Location: profile.php');
    exit;
}

$userData = app_one('SELECT * FROM users WHERE id = ?', 'i', [(int) $user['id']]);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadDir = __DIR__ . '/../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Subir Parte Frontal
    if (!empty($_FILES['doc_ci_front']['name'])) {
        $ext = pathinfo($_FILES['doc_ci_front']['name'], PATHINFO_EXTENSION);
        $fileNameFront = 'doc_ci_front_' . $user['id'] . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['doc_ci_front']['tmp_name'], $uploadDir . $fileNameFront)) {
            app_exec("UPDATE users SET doc_ci_path = ? WHERE id = ?", 'si', ['uploads/documents/' . $fileNameFront, (int)$user['id']]);
        }
    }
    
    // Subir Parte Posterior
    if (!empty($_FILES['doc_ci_back']['name'])) {
        $ext = pathinfo($_FILES['doc_ci_back']['name'], PATHINFO_EXTENSION);
        $fileNameBack = 'doc_ci_back_' . $user['id'] . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['doc_ci_back']['tmp_name'], $uploadDir . $fileNameBack)) {
            app_exec("UPDATE users SET doc_ci_back_path = ? WHERE id = ?", 'si', ['uploads/documents/' . $fileNameBack, (int)$user['id']]);
        }
    }
    
    header('Location: profile.php?toast=updated&tab=documentos');
    exit;
}

$title = 'Cédula de Identidad';
require __DIR__ . '/_header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; padding: 10px 0;">
    <a href="profile.php?tab=documentos" style="text-decoration: none; display: flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 700; font-size: 14px;">
        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Volver
    </a>
    <span style="font-weight: 800; font-size: 16px; color: var(--text);">Documento CI</span>
    <div style="width: 58px;"></div>
</div>

<div class="card" style="border:none; padding: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); border-radius: 24px; background: #fff; margin-bottom: 100px;">
    <h2 style="font-size: 20px; font-weight: 800; color: var(--text); margin-top: 0; margin-bottom: 6px;">Cédula de Identidad</h2>
    <p style="font-size: 13.5px; color: var(--muted); font-weight: 600; margin: 0 0 24px 0; line-height: 1.4;">
        Sube capturas nítidas y legibles de tu Cédula de Identidad para la verificación del perfil.
    </p>

    <form method="post" enctype="multipart/form-data">
        <!-- Parte Frontal -->
        <div class="form-group" style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 800; font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.5px; margin-bottom: 10px;">Parte Frontal (Adelante)</label>
            <div class="upload-zone" onclick="document.getElementById('file-front').click()" style="cursor: pointer; position: relative;">
                <input type="file" name="doc_ci_front" id="file-front" accept="image/*" style="display: none;" onchange="previewImage(this, 'preview-front', 'placeholder-front')">
                
                <div class="preview-container" id="preview-front" style="display: <?= !empty($userData['doc_ci_path']) ? 'block' : 'none' ?>; text-align: center;">
                    <img src="<?= !empty($userData['doc_ci_path']) ? esc(delivery_app_url($userData['doc_ci_path'])) : '' ?>" style="width: 100%; max-height: 180px; object-fit: contain; border-radius: 16px; border: 1px solid var(--border); padding: 5px; background: #fafafa;">
                    <div style="font-size: 11px; color: var(--muted); font-weight: 700; margin-top: 8px;">Haz clic para cambiar la foto</div>
                </div>
                
                <div class="upload-placeholder" id="placeholder-front" style="display: <?= !empty($userData['doc_ci_path']) ? 'none' : 'flex' ?>; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; border: 2px dashed var(--border); border-radius: 20px; background: rgba(0,0,0,0.01); transition: all 0.2s;">
                    <span style="font-size: 36px; margin-bottom: 8px;">📸</span>
                    <span style="font-weight: 800; font-size: 14px; color: var(--primary);">Tomar o subir parte frontal</span>
                    <span style="font-size: 11px; color: var(--muted); margin-top: 4px; font-weight: 600;">Formatos permitidos: JPG, PNG</span>
                </div>
            </div>
        </div>

        <!-- Parte Posterior -->
        <div class="form-group" style="margin-bottom: 30px;">
            <label style="display: block; font-weight: 800; font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.5px; margin-bottom: 10px;">Parte Posterior (Atrás)</label>
            <div class="upload-zone" onclick="document.getElementById('file-back').click()" style="cursor: pointer; position: relative;">
                <input type="file" name="doc_ci_back" id="file-back" accept="image/*" style="display: none;" onchange="previewImage(this, 'preview-back', 'placeholder-back')">
                
                <div class="preview-container" id="preview-back" style="display: <?= !empty($userData['doc_ci_back_path']) ? 'block' : 'none' ?>; text-align: center;">
                    <img src="<?= !empty($userData['doc_ci_back_path']) ? esc(delivery_app_url($userData['doc_ci_back_path'])) : '' ?>" style="width: 100%; max-height: 180px; object-fit: contain; border-radius: 16px; border: 1px solid var(--border); padding: 5px; background: #fafafa;">
                    <div style="font-size: 11px; color: var(--muted); font-weight: 700; margin-top: 8px;">Haz clic para cambiar la foto</div>
                </div>
                
                <div class="upload-placeholder" id="placeholder-back" style="display: <?= !empty($userData['doc_ci_back_path']) ? 'none' : 'flex' ?>; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; border: 2px dashed var(--border); border-radius: 20px; background: rgba(0,0,0,0.01); transition: all 0.2s;">
                    <span style="font-size: 36px; margin-bottom: 8px;">📸</span>
                    <span style="font-weight: 800; font-size: 14px; color: var(--primary);">Tomar o subir parte posterior</span>
                    <span style="font-size: 11px; color: var(--muted); margin-top: 4px; font-weight: 600;">Formatos permitidos: JPG, PNG</span>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-save-tech" style="width: 100%; padding: 16px; border-radius: 16px; font-weight: 800; background: var(--primary); color: #fff; border: none; cursor: pointer; box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25); transition: all 0.2s;">💾 Guardar Cambios</button>
    </form>
</div>

<style>
    .upload-placeholder:hover {
        border-color: var(--primary) !important;
        background: rgba(37, 99, 235, 0.02) !important;
    }
</style>

<script>
    function previewImage(input, previewId, placeholderId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewEl = document.getElementById(previewId);
                const placeholderEl = document.getElementById(placeholderId);
                
                previewEl.style.display = 'block';
                placeholderEl.style.display = 'none';
                
                let img = previewEl.querySelector('img');
                if (!img) {
                    img = document.createElement('img');
                    img.style.width = '100%';
                    img.style.maxHeight = '180px';
                    img.style.objectFit = 'contain';
                    img.style.borderRadius = '16px';
                    img.style.border = '1px solid var(--border)';
                    img.style.padding = '5px';
                    img.style.backgroundColor = '#fafafa';
                    previewEl.insertBefore(img, previewEl.firstChild);
                }
                img.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
