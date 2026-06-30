<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

// Fetch fresh data from DB
$sessionUser = current_user();
$userData = app_one("SELECT * FROM users WHERE id = ?", "i", [(int)$sessionUser['id']]);

if (!$userData) {
    die("Error: Usuario no encontrado.");
}

// Redirect if driver
if ($userData['role'] === 'repartidor') {
    header('Location: ' . delivery_app_url('pages/driver_dashboard.php'));
    exit;
}

// Redirect if admin
if ($userData['role'] === 'admin') {
    header('Location: ' . delivery_app_url('pages/admin_dashboard.php'));
    exit;
}

// Lógica de datos reales para los gráficos
$stats = app_one("
    SELECT 
        COUNT(*) as total_pedidos,
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as completados,
        COUNT(CASE WHEN status='cancelado' THEN 1 END) as cancelados
    FROM deliveries 
    WHERE local_user_id = ? AND DATE(created_at) = DATE(NOW())
", "i", [(int)$userData['id']]);

$total_pedidos = (int)($stats['total_pedidos'] ?? 0);
$completados = (int)($stats['completados'] ?? 0);
$cancelados = (int)($stats['cancelados'] ?? 0);

// Data para el gráfico semanal real (últimos 7 días) de entregas completadas
$weekly_raw = app_all("
    SELECT 
        DAYOFWEEK(created_at) as dow,
        COUNT(*) as cnt
    FROM deliveries
    WHERE local_user_id = ? AND status = 'entregado' AND created_at >= DATE_SUB(DATE(NOW()), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY created_at ASC
", "i", [(int)$userData['id']]);

$map_dow = [1 => 'D', 2 => 'L', 3 => 'M', 4 => 'X', 5 => 'J', 6 => 'V', 7 => 'S'];
$weekly_data = ['L' => 0, 'M' => 0, 'X' => 0, 'J' => 0, 'V' => 0, 'S' => 0, 'D' => 0];

foreach ($weekly_raw as $row) {
    $key = $map_dow[(int)$row['dow']];
    $weekly_data[$key] = (int)$row['cnt'];
}

$max_week = max(5, max($weekly_data)); 
$today_key = $map_dow[date('w') + 1]; 

$title = 'Inicio';
require __DIR__ . '/pages/_header.php';
?>

<style>
    /* Profile Hero Header */
    .profile-hero {
        position: relative;
        margin: -20px -20px 30px -20px; /* Overlap wrap padding */
        padding: 60px 20px 20px;
        text-align: center;
        overflow: hidden;
        background: #fff;
    }
    
    .hero-cover {
        position: absolute;
        top: 0; left: 0; right: 0; height: 180px;
        background: url('<?= !empty($userData['logo_path']) ? esc(delivery_app_url($userData['logo_path'])) : 'https://images.unsplash.com/photo-1513104890138-7c749659a591?q=80&w=1000&auto=format&fit=crop' ?>');
        background-size: cover;
        background-position: center;
        filter: blur(25px) brightness(0.8);
        transform: scale(1.2);
        z-index: 1;
    }
    
    .hero-cover::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, rgba(255, 255, 255, 0) 0%, #ffffff 100%);
    }

    .hero-content { position: relative; z-index: 2; }

    .profile-avatar-center {
        width: 105px;
        height: 105px;
        border-radius: 50%;
        border: 4px solid #ffffff;
        box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        margin: 0 auto 16px;
        background: #fff;
        overflow: hidden;
        cursor: pointer;
        transition: transform 0.3s ease;
    }
    .profile-avatar-center:active { transform: scale(0.95); }
    .profile-avatar-center img { width: 100%; height: 100%; object-fit: cover; }
    .profile-avatar-center .placeholder { font-size: 40px; line-height: 97px; }

    .profile-name-box { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 4px; }
    .profile-name-box h1 { font-size: 24px; font-weight: 800; color: var(--text); margin: 0; }
    
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
    
    .bento-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
    
    /* Hero Card (Full Width) */
    .card-hero {
        grid-column: span 2;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 28px 32px;
        background: #fff;
    }
    
    .hero-info h3 { font-size: 22px; color: var(--text); }
    
    /* Stats Cards - More Compact */
    .card-stat {
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .stat-icon {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
    }
    .stat-data { display: flex; flex-direction: column; }
    .stat-data b { font-size: 18px; color: var(--text); }
    .stat-data span { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }

    /* Modern Donut */
    .donut-container { position: relative; width: 80px; height: 80px; }
    .donut-svg { transform: rotate(-90deg); }
    .donut-bg { fill: none; stroke: #f1f5f9; stroke-width: 10; }
    .donut-ring { 
        fill: none; stroke: var(--primary); stroke-width: 10; 
        stroke-linecap: round; transition: stroke-dasharray 1.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .donut-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; color: var(--primary); }

    /* Weekly Card */
    .card-weekly { grid-column: span 2; padding: 24px; }
    .weekly-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    
    .bar-chart {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        height: 120px;
        padding: 0 4px;
        gap: 8px;
    }
    .bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .bar {
        width: 100%;
        max-width: 32px;
        background: var(--primary);
        border-radius: 8px;
        transition: all 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        min-height: 6px;
    }
    .bar.active { 
        background: var(--primary); 
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25); 
    }
    .day-label { font-size: 11px; font-weight: 700; color: #94a3b8; }
    .bar.active + .day-label { color: var(--primary); }
</style>

<div class="profile-hero">
    <div class="hero-cover"></div>
    <div class="hero-content">
        <div class="profile-avatar-center" onclick="location.href='pages/profile.php'">
            <?php if (!empty($userData['logo_path'])): ?>
                <img src="<?= esc(delivery_app_url($userData['logo_path'])) ?>?v=<?= time() ?>" alt="Logo">
            <?php else: ?>
                <div class="placeholder">🏢</div>
            <?php endif; ?>
        </div>
        <div class="profile-name-box">
            <h1><?= esc($userData['business_name'] ?: $userData['name']) ?></h1>
            <div class="verified-badge" title="Local Verificado">✓</div>
        </div>
        <p class="muted" style="font-weight: 600;">Panel de Gestión</p>
    </div>
</div>

<div class="bento-grid">
    <!-- Hero Activity Card: Pedidos del día -->
    <div class="card card-hero">
        <div class="hero-info" style="display: flex; flex-direction: column; gap: 20px;">
            <h3 style="font-size: 15px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px;">Pedidos del día</h3>
            
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <!-- Completados -->
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 20px;">🔥</span>
                    <div style="display: flex; flex-direction: column;">
                        <span class="muted" style="font-size: 11px; font-weight: 700;">COMPLETADOS</span>
                        <b style="font-size: 18px; color: var(--text);"><?= $completados ?></b>
                    </div>
                </div>
                
                <!-- Cancelados -->
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 20px;">🚫</span>
                    <div style="display: flex; flex-direction: column;">
                        <span class="muted" style="font-size: 11px; font-weight: 700;">CANCELADOS</span>
                        <b style="font-size: 18px; color: var(--text);"><?= $cancelados ?></b>
                    </div>
                </div>
            </div>
        </div>

        <div class="donut-container">
            <svg viewBox="0 0 100 100" class="donut-svg">
                <circle cx="50" cy="50" r="40" class="donut-bg"></circle>
                <?php 
                    // Cada pedido completado aumenta un 10% el gráfico (objetivo: 10 al día)
                    $dash = min(10, $completados) * 25.12;
                ?>
                <circle cx="50" cy="50" r="40" class="donut-ring" 
                        id="donut-progress-ring"
                        style="stroke-dasharray: 0 251.2;"></circle>
            </svg>
            <div class="donut-text"><?= $completados ?></div>
        </div>
    </div>

    <!-- Weekly Bento Card -->
    <div class="card card-weekly">
        <div class="weekly-header">
            <h3>Tendencia semanal</h3>
            <span class="muted" style="font-size: 12px; font-weight: 700;">Últimos 7 días</span>
        </div>
        
        <div class="bar-chart">
            <?php foreach ($weekly_data as $day => $val): 
                $h = ($val / $max_week) * 100;
                $isActive = ($day === $today_key);
            ?>
                <div class="bar-col">
                    <div style="height: 80px; width: 100%; display: flex; align-items: flex-end; justify-content: center;">
                        <div class="bar <?= $isActive ? 'active' : '' ?>" data-height="<?= $h ?>%" style="height: 0%;"></div>
                    </div>
                    <span class="day-label"><?= $day ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    window.addEventListener('load', () => {
        // Ejecutar animación con un pequeño retardo para asegurar el render inicial de los navegadores móviles
        setTimeout(() => {
            // Animar dónut
            const donutRing = document.getElementById('donut-progress-ring');
            if (donutRing) {
                donutRing.style.strokeDasharray = '<?= $dash ?> 251.2';
            }
            
            // Animar barras
            document.querySelectorAll('.bar-chart .bar').forEach(bar => {
                bar.style.height = bar.getAttribute('data-height');
            });
        }, 150);
    });
</script>

<?php require __DIR__ . '/pages/_footer.php'; ?>
