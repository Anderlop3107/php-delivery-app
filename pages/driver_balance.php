<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['repartidor']);

$user = current_user();

// 1. Calcular Saldo del día (Solo entregados hoy)
$earnings = app_one("
    SELECT SUM(delivery_cost) as total 
    FROM deliveries 
    WHERE repartidor_user_id = ? AND status = 'entregado' AND DATE(created_at) = DATE(NOW())
", "i", [(int)$user['id']]);
$total_earnings = (float)($earnings['total'] ?? 0);

// 2. Datos para el gráfico (Historial total del repartidor)
$stats = app_one("
    SELECT 
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as entregados,
        COUNT(CASE WHEN status IN ('cancelado', 'rechazado') THEN 1 END) as cancelados
    FROM deliveries 
    WHERE repartidor_user_id = ?
", "i", [(int)$user['id']]);

$total_entregados = (int)($stats['entregados'] ?? 0);
$total_cancelados = (int)($stats['cancelados'] ?? 0);
$total_vueltas = $total_entregados + $total_cancelados;

$title = 'Balance';
require __DIR__ . '/_header.php';
?>

<style>
    body { background: #ffffff !important; }
    .wrap { padding-top: 10px; }

    .balance-title { text-align: center; margin-bottom: 25px; }
    .balance-title h1 { font-size: 20px; font-weight: 800; color: var(--text); }

    /* Premium Virtual Card - RESTORED PREVIOUS MODEL */
    .virtual-card {
        width: 100%;
        height: 200px;
        background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        border-radius: 24px;
        padding: 24px;
        position: relative;
        color: #ffffff;
        box-shadow: 0 20px 40px rgba(37, 99, 235, 0.25);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    
    /* Reflective Diagonal Lines */
    .virtual-card::before {
        content: '';
        position: absolute;
        top: -50%; left: -20%; width: 140%; height: 200%;
        background: repeating-linear-gradient(
            45deg,
            rgba(255, 255, 255, 0.03) 0px,
            rgba(255, 255, 255, 0.03) 20px,
            transparent 20px,
            transparent 40px
        );
        transform: rotate(-15deg);
        pointer-events: none;
    }
    
    .card-top { display: flex; justify-content: space-between; align-items: flex-start; position: relative; z-index: 2; }
    .card-user-name { font-size: 13px; font-weight: 600; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px; }
    
    .card-avatar-logo {
        width: 45px; height: 35px;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(5px);
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    .card-avatar-logo span { font-size: 20px; filter: grayscale(1) brightness(2); opacity: 0.8; }

    .card-middle { position: relative; z-index: 2; margin-top: 10px; }
    .card-balance-label { font-size: 11px; opacity: 0.7; text-transform: uppercase; margin-bottom: 4px; display: block; }
    .card-amount { font-size: 32px; font-weight: 800; letter-spacing: -0.5px; }

    .card-bottom { display: flex; justify-content: flex-end; position: relative; z-index: 2; }
    .card-date { font-size: 12px; font-weight: 700; opacity: 0.8; font-family: monospace; }

    /* Summary Section */
    .summary-section { text-align: center; margin-top: 45px; }
    .summary-section h2 { font-size: 24px; color: #1e3a8a; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 6px; }
    .summary-section p { font-size: 13px; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin: 0; opacity: 0.8; }

    /* Donut Chart Bento */
    .chart-box {
        margin-top: 30px;
        background: #f8fafc;
        border-radius: 24px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .chart-box h3 { font-size: 16px; font-weight: 800; color: var(--text); align-self: flex-start; margin-bottom: 25px; }
    
    .chart-container {
        display: flex;
        align-items: center;
        gap: 30px;
        width: 100%;
    }
    
    .donut-viz { position: relative; width: 120px; height: 120px; flex-shrink: 0; }
    .donut-svg { transform: rotate(-90deg); }
    .donut-bg { fill: none; stroke: #e2e8f0; stroke-width: 12; }
    .donut-delivered { 
        fill: none; stroke: var(--primary); stroke-width: 12; 
        stroke-linecap: round; transition: stroke-dasharray 1s ease;
    }
    .donut-canceled { 
        fill: none; stroke: var(--danger); stroke-width: 12; 
        stroke-linecap: round; transition: stroke-dasharray 1s ease;
    }
    .donut-text-center {
        position: absolute; inset: 0;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        text-align: center;
    }
    .donut-text-center b { font-size: 11px; color: var(--muted); text-transform: uppercase; font-weight: 800; line-height: 1; }
    .donut-text-center span { font-size: 20px; font-weight: 800; color: var(--text); }

    /* Legend */
    .legend { display: flex; flex-direction: column; gap: 15px; }
    .legend-item { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 700; color: var(--text); }
    .dot { width: 10px; height: 10px; border-radius: 50%; }
    .dot.blue { background: var(--primary); }
    .dot.red { background: var(--danger); }
</style>

<div class="balance-title">
    <h1>Balance</h1>
</div>

<!-- Tarjeta Virtual Central -->
<div class="virtual-card">
    <div class="card-top">
        <div class="card-user-name">
            <span style="font-size: 10px; opacity: 0.6; font-weight: 400; text-transform: uppercase; display: block; margin-bottom: 2px;">Nombre</span>
            <?= esc($user['name']) ?>
        </div>
        <div class="card-avatar-logo">
            <span>👤</span>
        </div>
    </div>
    
    <div class="card-middle">
        <span class="card-balance-label">Ingresos de hoy</span>
        <div class="card-amount">Gs. <?= number_format($total_earnings, 0, ',', '.') ?></div>
    </div>
    
    <div class="card-bottom">
        <div class="card-date">FECHA: <?= date('d/m') ?></div>
    </div>
</div>

<!-- Sección Resumen -->
<div class="summary-section">
    <h2>Resumen</h2>
    <p>Detalles de entregas</p>
</div>

<!-- Gráfico de Dona -->
<div class="chart-box">
    <h3>Total de entregas</h3>
    
    <div class="chart-container">
        <div class="donut-viz">
            <svg viewBox="0 0 100 100" class="donut-svg">
                <circle cx="50" cy="50" r="40" class="donut-bg"></circle>
                <?php 
                    $p_entregados = $total_vueltas > 0 ? ($total_entregados / $total_vueltas) * 251.2 : 0;
                    $p_cancelados = $total_vueltas > 0 ? ($total_cancelados / $total_vueltas) * 251.2 : 0;
                ?>
                <!-- Entregados (Azul Eléctrico) -->
                <circle cx="50" cy="50" r="40" class="donut-delivered" 
                        style="stroke-dasharray: <?= $p_entregados ?> 251.2;"></circle>
                <!-- Cancelados (Rojo) -->
                <circle cx="50" cy="50" r="40" class="donut-canceled" 
                        style="stroke-dasharray: <?= $p_cancelados ?> 251.2; stroke-dashoffset: -<?= $p_entregados ?>;"></circle>
            </svg>
            <div class="donut-text-center">
                <span><?= $total_vueltas ?></span>
                <b>TOTAL</b>
            </div>
        </div>
        
        <div class="legend">
            <div class="legend-item">
                <div class="dot blue"></div>
                Entregados
            </div>
            <div class="legend-item">
                <div class="dot red"></div>
                Cancelados
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_header.php'; ?>
