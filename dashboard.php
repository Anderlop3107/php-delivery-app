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

// Lógica de datos reales para los gráficos
$stats = app_one("
    SELECT 
        COUNT(*) as total_pedidos,
        COUNT(CASE WHEN status NOT IN ('cancelado', 'rechazado') THEN 1 END) as completados,
        COUNT(CASE WHEN status='cancelado' THEN 1 END) as cancelados
    FROM deliveries 
    WHERE local_user_id = ? AND DATE(created_at) = DATE(NOW())
", "i", [(int)$userData['id']]);

$total_pedidos = (int)($stats['total_pedidos'] ?? 0);
$completados = (int)($stats['completados'] ?? 0);
$cancelados = (int)($stats['cancelados'] ?? 0);

// Data para el gráfico semanal real (últimos 7 días)
$weekly_raw = app_all("
    SELECT 
        DAYOFWEEK(created_at) as dow,
        COUNT(*) as cnt
    FROM deliveries
    WHERE local_user_id = ? AND created_at >= DATE_SUB(DATE(NOW()), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY created_at ASC
", "i", [(int)$userData['id']]);

$map_dow = [1 => 'D', 2 => 'L', 3 => 'M', 4 => 'X', 5 => 'J', 6 => 'V', 7 => 'S'];
$weekly_data = ['L' => 0, 'M' => 0, 'X' => 0, 'J' => 0, 'V' => 0, 'S' => 0, 'D' => 0];

foreach ($weekly_raw as $row) {
    $key = $map_dow[(int)$row['dow']];
    $weekly_data[$key] = (int)$row['cnt'];
}

$max_week = max($weekly_data) ?: 1; 
$today_key = $map_dow[date('w') + 1]; 

$title = 'Inicio';
require __DIR__ . '/pages/_header.php';
?>

<style>
    /* Dashboard Specific Styles */
    .dash-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }
    
    .welcome-text {
        font-size: 24px;
        font-weight: 900;
        color: var(--text);
        letter-spacing: -0.5px;
    }
    
    .profile-trigger {
        width: 55px;
        height: 55px;
        border-radius: 50%;
        border: 2px solid var(--primary);
        padding: 2px;
        cursor: pointer;
        position: relative;
        transition: transform 0.2s;
    }
    .profile-trigger:active { transform: scale(0.95); }
    .profile-trigger img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
    
    /* Main Stats Card */
    .stats-card {
        margin-top: 130px; /* Empuja las tarjetas hacia abajo */
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        align-items: center;
    }
    
    .stats-left { display: flex; flex-direction: column; gap: 20px; }
    .stats-title { color: #888; font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
    
    .stat-item { display: flex; align-items: center; gap: 12px; }
    .stat-icon { 
        width: 32px; height: 32px; border-radius: 10px; 
        display: flex; align-items: center; justify-content: center;
    }
    .icon-flame { background: rgba(255, 140, 66, 0.1); color: #FF8C42; }
    .icon-cancel { background: rgba(255, 68, 68, 0.1); color: #ff4444; }
    
    .stat-info { display: flex; flex-direction: column; }
    .stat-label { color: #666; font-size: 12px; font-weight: 500; }
    .stat-value { color: var(--text); font-size: 18px; font-weight: 800; min-width: 20px; }
    
    /* Donut Chart */
    .chart-container {
        position: relative;
        width: 140px;
        height: 140px;
        margin-left: auto;
    }
    
    .donut-svg { transform: rotate(-90deg); }
    .donut-bg { fill: none; stroke: var(--border); stroke-width: 12; }
    .donut-ring { 
        fill: none; stroke: var(--primary); stroke-width: 12; 
        stroke-linecap: round; transition: stroke-dasharray 1s ease;
    }
    
    .chart-center {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        background: radial-gradient(circle, rgba(255,140,66,0.1) 0%, transparent 70%);
    }
    .chart-total { color: #FF8C42; font-size: 20px; font-weight: 900; }
    
    /* Weekly Card */
    .weekly-card { 
        margin-top: 25px; /* Subida un poco para acercarla a la de arriba */
    }
    .weekly-title { color: var(--text); font-size: 16px; font-weight: 800; margin-bottom: 25px; }
    
    .bar-chart {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        height: 120px;
        padding: 0 10px;
    }
    
    .bar-col {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        flex: 1;
    }
    
    .bar {
        display: block;
        width: 18px;
        background: #d1d5db; /* Gris más sólido */
        border-radius: 6px 6px 2px 2px;
        transition: height 0.5s ease;
        position: relative;
        min-height: 4px; /* Forzar visibilidad incluso con 0 pedidos */
    }
    .bar.active { 
        background: var(--primary); 
        box-shadow: 0 0 15px rgba(255, 140, 66, 0.4); 
        min-height: 8px;
    }
    
    .day-label { 
        color: #94a3b8; font-size: 11px; font-weight: 800; 
        text-transform: uppercase;
    }
    .bar.active + .day-label { color: var(--primary); }
</style>

<div class="dash-header">
    <div class="welcome-text">
        Hola, <?= esc($userData['business_name'] ?: $userData['name']) ?>
    </div>
    <div class="profile-trigger" onclick="location.href='pages/profile.php'">
        <?php if (!empty($userData['logo_path'])): ?>
            <img src="<?= esc(delivery_app_url($userData['logo_path'])) ?>?v=<?= time() ?>" alt="Logo">
        <?php else: ?>
            <div style="width:100%; height:100%; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center;">
                <svg style="width:24px; height:24px; color:#cbd5e1;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card stats-card">
    <div class="stats-left">
        <div class="stats-title">Pedidos del día</div>
        
        <div class="stat-item">
            <div class="stat-icon icon-flame">
                <span style="font-size: 18px;">🔥</span>
            </div>
            <div class="stat-info">
                <span class="stat-label">Completados</span>
                <span class="stat-value"><?= $completados ?></span>
            </div>
        </div>
        
        <div class="stat-item">
            <div class="stat-icon icon-cancel">
                <span style="font-size: 18px;">🚫</span>
            </div>
            <div class="stat-info">
                <span class="stat-label">Cancelados</span>
                <span class="stat-value"><?= $cancelados ?></span>
            </div>
        </div>
    </div>
    
    <div class="stats-right">
        <div class="chart-container">
            <svg viewBox="0 0 100 100" class="donut-svg">
                <circle cx="50" cy="50" r="40" class="donut-bg"></circle>
                <?php 
                    // El anillo muestra el ratio de éxito (No Cancelados / Total)
                    $exito = $total_pedidos - $cancelados;
                    $dash = $total_pedidos > 0 ? ($exito / $total_pedidos) * 251.2 : 0;
                ?>
                <circle cx="50" cy="50" r="40" class="donut-ring" 
                        style="stroke-dasharray: <?= $dash ?> 251.2;"></circle>
            </svg>
            <div class="chart-center">
                <span style="color:#666; font-size:10px; font-weight:700;">TOTAL</span>
                <span class="chart-total"><?= $total_pedidos ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card weekly-card">
    <div class="weekly-title">Pedidos semanal</div>
    
    <div class="bar-chart">
        <?php foreach ($weekly_data as $day => $val): 
            $h = ($val / $max_week) * 100;
            $isActive = ($day === $today_key);
        ?>
            <div class="bar-col">
                <div class="bar <?= $isActive ? 'active' : '' ?>" style="height: <?= $h ?>%;"></div>
                <span class="day-label"><?= $day ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/pages/_footer.php'; ?>
