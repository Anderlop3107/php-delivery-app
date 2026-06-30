<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] !== 'admin') {
    header('Location: ' . delivery_app_url('dashboard.php'));
    exit;
}

// 1. Estadísticas Globales
$todayOrders = app_one("
    SELECT COUNT(*) as count 
    FROM deliveries 
    WHERE DATE(created_at) = DATE(NOW())
")['count'] ?? 0;

$activeDriversCount = app_one("
    SELECT COUNT(*) as count 
    FROM users 
    WHERE role = 'repartidor' 
      AND is_online = 1 
      AND ubicacion_actualizada_en >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
")['count'] ?? 0;

$activeLocalsCount = app_one("
    SELECT COUNT(*) as count 
    FROM users 
    WHERE role = 'local' 
      AND subscription_status = 'active'
")['count'] ?? 0;

// 2. Obtener lista de conductores (Repartidores)
$drivers = app_all("
    SELECT * 
    FROM users 
    WHERE role = 'repartidor' 
    ORDER BY name ASC
");

// 3. Obtener lista de comercios (Locales)
$locals = app_all("
    SELECT * 
    FROM users 
    WHERE role = 'local' 
    ORDER BY COALESCE(business_name, name) ASC
");

// 4. Obtener entregas activas
$activeDeliveries = app_all("
    SELECT d.*, l.business_name as local_name, r.name as driver_name
    FROM deliveries d
    LEFT JOIN users l ON l.id = d.local_user_id
    LEFT JOIN users r ON r.id = d.repartidor_user_id
    WHERE d.status NOT IN ('entregado', 'cancelado')
    ORDER BY d.created_at DESC
");

$title = 'Panel Administrador';
require __DIR__ . '/_header.php';
?>

<style>
    .admin-container {
        margin-bottom: 100px;
    }
    
    /* Stats Bento Header */
    .admin-stats-bento {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-bottom: 24px;
    }
    
    .stat-card-admin {
        background: #ffffff;
        border-radius: 20px;
        padding: 16px;
        box-shadow: var(--shadow);
        border: 1px solid rgba(0, 0, 0, 0.01);
        display: flex;
        flex-direction: column;
        gap: 4px;
        transition: transform 0.2s;
    }
    
    .stat-card-admin:active {
        transform: scale(0.98);
    }
    
    .stat-card-admin small {
        font-size: 9px;
        font-weight: 800;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-card-admin b {
        font-size: 20px;
        font-weight: 800;
        color: var(--text);
    }
    
    .stat-card-admin .icon-wrap {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 8px;
        font-size: 16px;
    }
    
    .stat-blue { background: rgba(37, 99, 235, 0.08); color: var(--primary); }
    .stat-green { background: rgba(16, 185, 129, 0.08); color: #10b981; }
    .stat-orange { background: rgba(245, 158, 11, 0.08); color: #f59e0b; }

    /* Segmented Control - Tabs */
    .admin-tabs {
        display: flex;
        background: #f1f5f9;
        padding: 4px;
        border-radius: 16px;
        margin-bottom: 20px;
    }
    
    .tab-btn {
        flex: 1;
        border: none;
        background: transparent;
        padding: 10px;
        font-size: 12.5px;
        font-weight: 700;
        color: #64748b;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        text-align: center;
    }
    
    .tab-btn.active {
        background: #ffffff;
        color: var(--text);
        box-shadow: 0 4px 10px rgba(0,0,0,0.04);
    }
    
    /* Content cards list */
    .admin-section {
        display: none;
    }
    .admin-section.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .bento-list-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 16px;
        margin-bottom: 12px;
        box-shadow: var(--shadow);
        border: 1px solid rgba(0, 0, 0, 0.01);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        transition: transform 0.2s;
    }
    
    .bento-list-card:active {
        transform: scale(0.99);
    }
    
    .card-info {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 1;
    }
    
    .avatar-wrap {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        border: 1px solid #f1f5f9;
        overflow: hidden;
    }
    .avatar-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .info-text h4 {
        margin: 0;
        font-size: 14.5px;
        font-weight: 800;
        color: var(--text);
    }
    .info-text p {
        margin: 2px 0 0;
        font-size: 11px;
        color: var(--muted);
        font-weight: 500;
    }
    
    /* Document Badge Indicators */
    .doc-badges {
        display: flex;
        gap: 4px;
        margin-top: 6px;
    }
    .doc-dot {
        font-size: 8px;
        font-weight: 800;
        padding: 2px 6px;
        border-radius: 6px;
        text-transform: uppercase;
    }
    .doc-none { background: #f1f5f9; color: #94a3b8; }
    .doc-pending { background: #fffbeb; color: #d97706; }
    .doc-approved { background: #ecfdf5; color: #10b981; }
    .doc-rejected { background: #fef2f2; color: #ef4444; }

    /* Subscription Toggles */
    .sub-badge {
        font-size: 10px;
        font-weight: 800;
        padding: 4px 10px;
        border-radius: 8px;
        text-transform: uppercase;
        cursor: pointer;
    }
    .sub-active { background: #ecfdf5; color: #10b981; }
    .sub-expired { background: #fef2f2; color: #ef4444; }
    .sub-pending { background: #fffbeb; color: #d97706; }
    
    /* Glass Modal for Document Previews */
    .admin-modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 3000;
        display: none;
        align-items: flex-end;
    }
    
    .admin-modal-overlay.active {
        display: flex;
    }
    
    .admin-modal-card {
        background: #ffffff;
        width: 100%;
        border-radius: 32px 32px 0 0;
        padding: 28px 24px 40px;
        box-shadow: 0 -20px 50px rgba(0,0,0,0.15);
        max-height: 90vh;
        overflow-y: auto;
        transform: translateY(100%);
        transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    
    .admin-modal-overlay.active .admin-modal-card {
        transform: translateY(0);
    }
    
    .modal-header-admin {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .modal-header-admin h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 800;
        color: var(--text);
    }
    
    .modal-close-btn {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #f1f5f9;
        border: none;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .driver-docs-grid {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    
    .doc-verify-row {
        background: #f8fafc;
        border-radius: 20px;
        padding: 16px;
        border: 1px solid #e2e8f0;
    }
    
    .doc-verify-row h5 {
        margin: 0 0 12px;
        font-size: 13px;
        font-weight: 800;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .doc-images-flex {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-bottom: 12px;
    }
    
    .doc-img-wrap {
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        aspect-ratio: 4/3;
        cursor: zoom-in;
    }
    .doc-img-wrap img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }
    
    .doc-actions-admin {
        display: flex;
        gap: 8px;
    }
    
    .btn-action-admin {
        flex: 1;
        padding: 10px;
        font-size: 12px;
        font-weight: 700;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .btn-approve-admin {
        background: #10b981;
        color: #ffffff;
        box-shadow: 0 4px 10px rgba(16, 185, 129, 0.15);
    }
    .btn-approve-admin:active {
        transform: scale(0.96);
    }
    .btn-reject-admin {
        background: #ef4444;
        color: #ffffff;
        box-shadow: 0 4px 10px rgba(239, 68, 68, 0.15);
    }
    .btn-reject-admin:active {
        transform: scale(0.96);
    }
    
    /* Lightbox modal for enlarged images */
    .lightbox-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.9);
        z-index: 4000;
        display: none;
        align-items: center;
        justify-content: center;
    }
    .lightbox-overlay.active {
        display: flex;
    }
    .lightbox-img {
        max-width: 95vw;
        max-height: 85vh;
        object-fit: contain;
        border-radius: 8px;
    }
    
    /* Dropdown style for subscriptions */
    .select-sub-status {
        padding: 6px 8px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 700;
        border: 1px solid #cbd5e1;
        outline: none;
        cursor: pointer;
        background: #fff;
    }
</style>

<div class="admin-container">
    
    <!-- Bento Stats -->
    <div class="admin-stats-bento">
        <div class="stat-card-admin">
            <div class="icon-wrap stat-blue">📦</div>
            <small>Pedidos Hoy</small>
            <b><?= $todayOrders ?></b>
        </div>
        
        <div class="stat-card-admin">
            <div class="icon-wrap stat-green">🛵</div>
            <small>Drivers Activos</small>
            <b><?= $activeDriversCount ?></b>
        </div>
        
        <div class="stat-card-admin">
            <div class="icon-wrap stat-orange">🏢</div>
            <small>Locales Activos</small>
            <b><?= $activeLocalsCount ?></b>
        </div>
    </div>
    
    <!-- Tabs Segmented Control -->
    <div class="admin-tabs">
        <button class="tab-btn active" onclick="switchTab('repartidores')">Repartidores</button>
        <button class="tab-btn" onclick="switchTab('comercios')">Comercios</button>
        <button class="tab-btn" onclick="switchTab('pedidos')">Pedidos Activos</button>
    </div>
    
    <!-- Tab 1: Repartidores & Documentos -->
    <div id="tab-repartidores" class="admin-section active">
        <?php if (empty($drivers)): ?>
            <div style="text-align: center; color: var(--muted); padding: 40px;">No hay repartidores registrados.</div>
        <?php else: ?>
            <?php foreach ($drivers as $d): ?>
                <div class="bento-list-card" onclick='openDriverModal(<?= json_encode($d) ?>)'>
                    <div class="card-info">
                        <div class="avatar-wrap">
                            <?php if ($d['avatar_path']): ?>
                                <img src="<?= esc(delivery_app_url($d['avatar_path'])) ?>" alt="Avatar">
                            <?php else: ?>
                                👤
                            <?php endif; ?>
                        </div>
                        <div class="info-text">
                            <h4><?= esc($d['name']) ?></h4>
                            <p><?= esc($d['phone']) ?> · <?= $d['is_online'] ? '<span style="color:#10b981; font-weight:700;">🟢 En Línea</span>' : '<span style="color:#64748b;">⚪ Desconectado</span>' ?></p>
                            
                            <!-- Indicadores de documentos -->
                            <div class="doc-badges">
                                <span class="doc-dot doc-<?= $d['status_doc_ci'] ?>">CI: <?= $d['status_doc_ci'] ?></span>
                                <span class="doc-dot doc-<?= $d['status_doc_licencia'] ?>">Lic: <?= $d['status_doc_licencia'] ?></span>
                                <span class="doc-dot doc-<?= $d['status_doc_habilitacion'] ?>">Hab: <?= $d['status_doc_habilitacion'] ?></span>
                                <span class="doc-dot doc-<?= $d['status_doc_cedula_verde'] ?>">Verde: <?= $d['status_doc_cedula_verde'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div style="font-size: 18px; color: #cbd5e1;">&rsaquo;</div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Tab 2: Comercios & Suscripciones -->
    <div id="tab-comercios" class="admin-section">
        <?php if (empty($locals)): ?>
            <div style="text-align: center; color: var(--muted); padding: 40px;">No hay comercios registrados.</div>
        <?php else: ?>
            <?php foreach ($locals as $l): 
                $subStatus = $l['subscription_status'] ?? 'pending';
            ?>
                <div class="bento-list-card">
                    <div class="card-info">
                        <div class="avatar-wrap">
                            <?php if ($l['logo_path']): ?>
                                <img src="<?= esc(delivery_app_url($l['logo_path'])) ?>" alt="Logo">
                            <?php else: ?>
                                🏢
                            <?php endif; ?>
                        </div>
                        <div class="info-text">
                            <h4><?= esc($l['business_name'] ?: $l['name']) ?></h4>
                            <p>Vence: <?= $l['subscription_expires_at'] ? date('d/m/Y', strtotime($l['subscription_expires_at'])) : 'Nunca' ?></p>
                        </div>
                    </div>
                    <div>
                        <select class="select-sub-status" onchange="updateSubscription(<?= $l['id'] ?>, this.value)">
                            <option value="active" <?= $subStatus === 'active' ? 'selected' : '' ?>>Activo (+30d)</option>
                            <option value="expired" <?= $subStatus === 'expired' ? 'selected' : '' ?>>Expirado</option>
                            <option value="pending" <?= $subStatus === 'pending' ? 'selected' : '' ?>>Pendiente</option>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Tab 3: Pedidos Activos -->
    <div id="tab-pedidos" class="admin-section">
        <?php if (empty($activeDeliveries)): ?>
            <div style="text-align: center; color: var(--muted); padding: 40px;">No hay entregas activas en este momento.</div>
        <?php else: ?>
            <?php foreach ($activeDeliveries as $ad): 
                $status = strtolower($ad['status']);
                $pill_class = 'doc-pending';
                if ($status === 'pendiente') $pill_class = 'doc-none';
                if ($status === 'retirado' || $status === 'aceptado') $pill_class = 'doc-pending';
                if ($status === 'entregado') $pill_class = 'doc-approved';
                if ($status === 'cancelado') $pill_class = 'doc-rejected';
            ?>
                <div class="bento-list-card" style="align-items: flex-start; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; width: 100%; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; margin-bottom: 8px;">
                        <span style="font-weight: 800; font-size: 13px; color: var(--text);">Pedido #<?= $ad['id'] ?></span>
                        <span class="doc-dot <?= $pill_class ?>"><?= htmlspecialchars($ad['status']) ?></span>
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 4px; width: 100%; font-size: 12px; color: #475569;">
                        <div><b>Local:</b> <?= esc($ad['local_name'] ?: 'N/A') ?></div>
                        <div><b>Repartidor:</b> <?= esc($ad['driver_name'] ?: 'No asignado') ?></div>
                        <div><b>Cliente:</b> <?= esc($ad['customer_name']) ?></div>
                        <div><b>Dirección:</b> <?= esc($ad['delivery_address']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Admin Modal: Document verification -->
<div id="admin-doc-modal" class="admin-modal-overlay" onclick="closeDriverModal(event)">
    <div class="admin-modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-admin">
            <h3 id="modal-driver-name">Verificación de Conductor</h3>
            <button class="modal-close-btn" onclick="closeDriverModal(null)">✕</button>
        </div>
        
        <div class="driver-docs-grid" id="modal-docs-container">
            <!-- Dynamically populated via Javascript -->
        </div>
    </div>
</div>

<!-- Image Lightbox -->
<div id="lightbox-modal" class="lightbox-overlay" onclick="closeLightbox()">
    <img id="lightbox-img" class="lightbox-img" src="" alt="Ampliado">
</div>

<script>
    let activeDriverData = null;

    function switchTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.admin-section').forEach(sec => sec.classList.remove('active'));
        
        const targetBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.textContent.toLowerCase().includes(tabId.substring(0, 3)));
        if (targetBtn) targetBtn.classList.add('active');
        
        const targetSec = document.getElementById('tab-' + tabId);
        if (targetSec) targetSec.classList.add('active');
    }
    
    function openDriverModal(driver) {
        activeDriverData = driver;
        document.getElementById('modal-driver-name').textContent = 'Documentos: ' + driver.name;
        
        const container = document.getElementById('modal-docs-container');
        container.innerHTML = '';
        
        const docTypes = [
            { key: 'ci', label: 'Cédula de Identidad' },
            { key: 'licencia', label: 'Registro de Conducir' },
            { key: 'habilitacion', label: 'Habilitación' },
            { key: 'cedula_verde', label: 'Cédula Verde' }
        ];
        
        docTypes.forEach(doc => {
            const frontPath = driver['doc_' + doc.key + '_path'];
            const backPath = driver['doc_' + doc.key + '_back_path'];
            const status = driver['status_doc_' + doc.key];
            
            if (frontPath || backPath) {
                let statusLabel = '';
                if (status === 'approved') statusLabel = '<span style="color:#10b981; font-weight:800;">✓ Aprobado</span>';
                else if (status === 'rejected') statusLabel = '<span style="color:#ef4444; font-weight:800;">❌ Rechazado</span>';
                else statusLabel = '<span style="color:#f59e0b; font-weight:800;">⏳ Pendiente</span>';
                
                const row = document.createElement('div');
                row.className = 'doc-verify-row';
                row.innerHTML = `
                    <div style="display:flex; justify-content:space-between; margin-bottom:12px; align-items:center;">
                        <h5>${doc.label}</h5>
                        <div style="font-size:11px;">${statusLabel}</div>
                    </div>
                    <div class="doc-images-flex">
                        ${frontPath ? `<div class="doc-img-wrap" onclick="openLightbox('${frontPath}')"><img src="../${frontPath}"></div>` : '<div style="color:#94a3b8; font-size:11px; display:flex; align-items:center; justify-content:center; border:1px dashed #cbd5e1; border-radius:12px;">Sin frontal</div>'}
                        ${backPath ? `<div class="doc-img-wrap" onclick="openLightbox('${backPath}')"><img src="../${backPath}"></div>` : '<div style="color:#94a3b8; font-size:11px; display:flex; align-items:center; justify-content:center; border:1px dashed #cbd5e1; border-radius:12px;">Sin posterior</div>'}
                    </div>
                    <div class="doc-actions-admin">
                        <button class="btn-action-admin btn-approve-admin" onclick="verifyDocument(${driver.id}, '${doc.key}', 'approve')">Aprobar</button>
                        <button class="btn-action-admin btn-reject-admin" onclick="verifyDocument(${driver.id}, '${doc.key}', 'reject')">Rechazar</button>
                    </div>
                `;
                container.appendChild(row);
            }
        });
        
        if (container.children.length === 0) {
            container.innerHTML = '<div style="text-align:center; color:#94a3b8; font-weight:600; padding:20px;">Este conductor no ha subido ningún documento aún.</div>';
        }
        
        document.getElementById('admin-doc-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeDriverModal(e) {
        document.getElementById('admin-doc-modal').classList.remove('active');
        document.body.style.overflow = '';
    }
    
    function openLightbox(src) {
        document.getElementById('lightbox-img').src = '../' + src;
        document.getElementById('lightbox-modal').classList.add('active');
    }
    
    function closeLightbox() {
        document.getElementById('lightbox-modal').classList.remove('active');
    }
    
    function verifyDocument(driverId, docType, decision) {
        const action = decision === 'approve' ? 'approve_document' : 'reject_document';
        
        const formData = new FormData();
        formData.append('action', action);
        formData.append('driver_id', driverId);
        formData.append('doc_type', docType);
        
        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Update local memory and view
                if (activeDriverData) {
                    activeDriverData['status_doc_' + docType] = data.new_status;
                    openDriverModal(activeDriverData);
                }
                
                // Show floating check or reload page silently to update indicators
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error de conexión.');
        });
    }
    
    function updateSubscription(localId, status) {
        const formData = new FormData();
        formData.append('action', 'update_subscription');
        formData.append('local_id', localId);
        formData.append('status', status);
        formData.append('days', 30); // 30 días de renovación por defecto
        
        fetch('api_admin_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error de conexión.');
        });
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
