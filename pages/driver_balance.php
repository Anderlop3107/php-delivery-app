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

// 2. Datos para el gráfico (Entregas diarias del repartidor)
$stats = app_one("
    SELECT 
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as entregados,
        COUNT(CASE WHEN status IN ('cancelado', 'rechazado') THEN 1 END) as cancelados
    FROM deliveries 
    WHERE repartidor_user_id = ? AND DATE(created_at) = DATE(NOW())
", "i", [(int)$user['id']]);

$total_entregados = (int)($stats['entregados'] ?? 0);
$total_cancelados = (int)($stats['cancelados'] ?? 0);
$total_vueltas = $total_entregados + $total_cancelados;

// Lógica de Meta (5 pedidos para la animación)
$goal = 5;
$isGoalReached = ($total_vueltas >= $goal);

$title = 'Balance';
require __DIR__ . '/_header.php';
?>

<style>
    body { background: #ffffff !important; }
    .wrap { padding-top: 10px; }

    .balance-title { text-align: center; margin-bottom: 25px; }
    .balance-title h1 { font-size: 20px; font-weight: 800; color: var(--text); }

    /* Virtual Card */
    .virtual-card {
        width: 100%; height: 200px;
        background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        border-radius: 24px; padding: 24px;
        position: relative; color: #ffffff;
        box-shadow: 0 20px 40px rgba(37, 99, 235, 0.25);
        overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
    }
    .virtual-card::before {
        content: ''; position: absolute; top: -50%; left: -20%; width: 140%; height: 200%;
        background: repeating-linear-gradient(45deg, rgba(255, 255, 255, 0.03) 0px, rgba(255, 255, 255, 0.03) 20px, transparent 20px, transparent 40px);
        transform: rotate(-15deg); pointer-events: none;
    }
    .card-top { display: flex; justify-content: space-between; align-items: flex-start; z-index: 2; }
    .card-user-name { font-size: 13px; font-weight: 600; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px; }
    .card-avatar-logo { width: 45px; height: 35px; background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(5px); border-radius: 10px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(255, 255, 255, 0.2); }
    .card-amount { font-size: 32px; font-weight: 800; letter-spacing: -0.5px; }
    .card-date { font-size: 12px; font-weight: 700; opacity: 0.8; font-family: monospace; }

    /* Summary Heading */
    .summary-section { text-align: center; margin-top: 45px; }
    .summary-section h2 { font-size: 24px; color: #1e3a8a; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 6px; }
    .summary-section p { font-size: 13px; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin: 0; opacity: 0.8; }

    /* PROGRESS DONUT AREA */
    .progress-viz-box {
        margin-top: 40px; padding: 40px 20px; background: #fff; border-radius: 30px;
        display: flex; align-items: center; justify-content: center; gap: 40px;
    }

    .donut-container { position: relative; width: 140px; height: 140px; }
    .donut-svg { transform: rotate(-90deg); width: 100%; height: 100%; overflow: visible; }
    .donut-bg { fill: none; stroke: #f1f5f9; stroke-width: 16; }
    .donut-seg { fill: none; stroke-width: 16; stroke-linecap: butt; transition: stroke-dasharray 1s cubic-bezier(0.4, 0, 0.2, 1); }
    
    .seg-delivered { stroke: var(--primary); }
    .seg-delivered.completed { stroke: #1e3a8a; } /* Azul oscuro al completar */
    .seg-canceled { stroke: #0ea5e9; }

    /* Animation Glow for Goal Completion */
    .goal-glow {
        position: absolute; inset: -10px; border-radius: 50%;
        border: 4px solid var(--primary); opacity: 0;
        animation: <?= $isGoalReached ? 'glowPulse 2s infinite' : 'none' ?>;
    }
    @keyframes glowPulse {
        0% { transform: scale(1); opacity: 0; }
        50% { opacity: 0.25; }
        100% { transform: scale(1.15); opacity: 0; }
    }

    .donut-center {
        position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .donut-center span { font-size: 24px; font-weight: 800; color: var(--text); }
    .donut-center b { font-size: 10px; color: var(--muted); text-transform: uppercase; font-weight: 800; }

    /* Legend Vertical */
    .v-legend { display: flex; flex-direction: column; gap: 20px; flex: 1; }
    .v-legend-item { display: flex; align-items: center; gap: 10px; }
    .dot { width: 12px; height: 12px; border-radius: 50%; }
    .dot.blue { background: var(--primary); }
    .dot.red { background: #0ea5e9; }
    .v-legend-item span { font-size: 14px; font-weight: 700; color: var(--text); text-transform: lowercase; }
    .v-legend-item b { font-size: 16px; font-weight: 800; color: var(--text); margin-left: auto; font-family: monospace; }
</style>

<div class="balance-title">
    <h1>Balance</h1>
</div>

<div class="virtual-card">
    <div class="card-top">
        <div class="card-user-name">
            <span style="font-size: 10px; opacity: 0.6; display: block; margin-bottom: 2px;">Nombre</span>
            <?= esc($user['name']) ?>
        </div>
        <div class="card-avatar-logo"><span>👤</span></div>
    </div>
    <div class="card-middle">
        <span style="font-size: 11px; opacity: 0.7; text-transform: uppercase;">Ingresos de hoy</span>
        <div class="card-amount">Gs. <?= number_format($total_earnings, 0, ',', '.') ?></div>
    </div>
    <div class="card-bottom"><div class="card-date">FECHA: <?= date('d/m') ?></div></div>
</div>

<div class="summary-section">
    <h2>Resumen</h2>
    <p>Detalles de entregas</p>
</div>

<div class="progress-viz-box">
    <div class="donut-container">
        <div class="goal-glow"></div>
        <svg viewBox="0 0 100 100" class="donut-svg">
            <!-- Base gris (100% capacidad) -->
            <circle cx="50" cy="50" r="38" class="donut-bg"></circle>
            
            <?php 
                $circum = 2 * M_PI * 38; // ~238.76
                
                if (!$isGoalReached) {
                    // Llenado progresivo basado en meta de 5
                    $val_e = ($total_entregados / 5) * $circum;
                    $val_c = ($total_cancelados / 5) * $circum;
                    $color_delivered = "seg-delivered";
                } else {
                    // Estado Finalizado: El azul oscuro domina
                    $ratio_e = $total_entregados / $total_vueltas;
                    $ratio_c = $total_cancelados / $total_vueltas;
                    $val_e = $ratio_e * $circum;
                    $val_c = $ratio_c * $circum;
                    $color_delivered = "seg-delivered completed";
                }
            ?>
            
            <!-- Entregados -->
            <circle cx="50" cy="50" r="38" class="donut-seg <?= $color_delivered ?>" 
                    style="stroke-dasharray: <?= $val_e ?> <?= $circum ?>;"></circle>
            
            <!-- Cancelados -->
            <circle cx="50" cy="50" r="38" class="donut-seg seg-canceled" 
                    style="stroke-dasharray: <?= $val_c ?> <?= $circum ?>; stroke-dashoffset: -<?= $val_e ?>;"></circle>
        </svg>
    </div>

    <div class="v-legend">
        <div class="v-legend-item">
            <div class="dot blue"></div>
            <span>entregados</span>
            <b><?= $total_entregados ?></b>
        </div>
        <div class="v-legend-item">
            <div class="dot red"></div>
            <span>cancelados</span>
            <b><?= $total_cancelados ?></b>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
