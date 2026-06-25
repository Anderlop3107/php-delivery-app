<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
require_role(['repartidor']);

$user = current_user();
$user_id = (int)$user['id'];

// 1. Datos diarios (Hoy)
$data_day = app_one("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'entregado' THEN delivery_cost END), 0) as earnings,
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as entregados,
        COUNT(CASE WHEN status IN ('cancelado', 'rechazado') THEN 1 END) as cancelados
    FROM deliveries 
    WHERE repartidor_user_id = ? AND DATE(created_at) = DATE(NOW())
", "i", [$user_id]);

// 2. Datos semanales (Últimos 7 días)
$data_week = app_one("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'entregado' THEN delivery_cost END), 0) as earnings,
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as entregados,
        COUNT(CASE WHEN status IN ('cancelado', 'rechazado') THEN 1 END) as cancelados
    FROM deliveries 
    WHERE repartidor_user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
", "i", [$user_id]);

// 3. Datos mensuales (Últimos 30 días)
$data_month = app_one("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'entregado' THEN delivery_cost END), 0) as earnings,
        COUNT(CASE WHEN status = 'entregado' THEN 1 END) as entregados,
        COUNT(CASE WHEN status IN ('cancelado', 'rechazado') THEN 1 END) as cancelados
    FROM deliveries 
    WHERE repartidor_user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
", "i", [$user_id]);

// Codificar estadísticas en JSON para alternar por JS
$stats_json = json_encode([
    'hoy' => [
        'earnings' => (float)$data_day['earnings'],
        'entregados' => (int)$data_day['entregados'],
        'cancelados' => (int)$data_day['cancelados'],
        'vueltas' => (int)$data_day['entregados'] + (int)$data_day['cancelados'],
        'label' => 'hoy'
    ],
    'semana' => [
        'earnings' => (float)$data_week['earnings'],
        'entregados' => (int)$data_week['entregados'],
        'cancelados' => (int)$data_week['cancelados'],
        'vueltas' => (int)$data_week['entregados'] + (int)$data_week['cancelados'],
        'label' => 'esta semana'
    ],
    'mes' => [
        'earnings' => (float)$data_month['earnings'],
        'entregados' => (int)$data_month['entregados'],
        'cancelados' => (int)$data_month['cancelados'],
        'vueltas' => (int)$data_month['entregados'] + (int)$data_month['cancelados'],
        'label' => 'este mes'
    ]
]);

$title = 'Balance';
require __DIR__ . '/_header.php';
?>

<style>
    body { background: #ffffff !important; }
    .wrap { padding-top: 10px; }

    .balance-title { text-align: center; margin-bottom: 20px; }
    .balance-title h1 { font-size: 20px; font-weight: 800; color: var(--text); margin: 0; }

    /* Premium Segmented Control */
    .segmented-control-tech {
        display: flex;
        background: rgba(241, 245, 249, 0.85);
        padding: 5px;
        border-radius: 20px;
        margin-bottom: 25px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
    .segment-btn {
        flex: 1;
        padding: 12px 10px;
        border: 0;
        background: transparent;
        font-weight: 800;
        font-size: 11px;
        color: #64748b;
        cursor: pointer;
        border-radius: 16px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        text-transform: uppercase;
        letter-spacing: 0.8px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    .segment-btn:active {
        transform: scale(0.95);
    }
    .segment-btn.active {
        background: #ffffff;
        color: var(--primary);
        font-weight: 850;
        box-shadow: 0 8px 16px -4px rgba(37, 99, 235, 0.12), 0 4px 6px -2px rgba(0,0,0,0.02);
    }
    .segment-btn svg {
        transition: transform 0.25s ease;
    }
    .segment-btn.active svg {
        transform: scale(1.1);
    }

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
    .summary-section { text-align: center; margin-top: 40px; }
    .summary-section h2 { font-size: 24px; color: #1e3a8a; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 6px; }
    .summary-section p { font-size: 13px; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin: 0; opacity: 0.8; }

    /* PROGRESS DONUT AREA */
    .progress-viz-box {
        margin-top: 30px; padding: 30px 20px 40px; background: #fff; border-radius: 30px;
        display: flex; align-items: center; justify-content: center; gap: 30px;
        border: 1px solid rgba(0,0,0,0.02);
        box-shadow: 0 4px 20px rgba(0,0,0,0.01);
    }

    .donut-container { position: relative; width: 140px; height: 140px; }
    .donut-svg { transform: rotate(-90deg); width: 100%; height: 100%; overflow: visible; }
    .donut-bg { fill: none; stroke: #f1f5f9; stroke-width: 16; }
    .donut-seg { fill: none; stroke-width: 16; stroke-linecap: butt; transition: stroke-dasharray 0.8s cubic-bezier(0.4, 0, 0.2, 1), stroke-dashoffset 0.8s ease; }
    
    .seg-delivered { stroke: var(--primary); }
    .seg-delivered.completed { stroke: #10b981; } /* Verde al completar la meta */
    .seg-canceled { stroke: #ef4444; } /* Rojo para cancelados */

    /* Animation Glow for Goal Completion */
    .goal-glow {
        position: absolute; inset: -10px; border-radius: 50%;
        border: 4px solid #10b981; opacity: 0;
        transition: opacity 0.5s ease;
    }
    @keyframes glowPulse {
        0% { transform: scale(1); opacity: 0; }
        50% { opacity: 0.3; }
        100% { transform: scale(1.15); opacity: 0; }
    }

    .donut-center {
        position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .donut-center span { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1; }
    .donut-center b { font-size: 9px; color: var(--muted); text-transform: uppercase; font-weight: 800; margin-top: 4px; }

    /* Legend Vertical */
    .v-legend { display: flex; flex-direction: column; gap: 20px; flex: 1; }
    .v-legend-item { display: flex; align-items: center; gap: 10px; }
    .dot { width: 12px; height: 12px; border-radius: 50%; }
    .dot.blue { background: var(--primary); }
    .dot.red { background: #ef4444; }
    .v-legend-item span { font-size: 14px; font-weight: 700; color: var(--muted); text-transform: lowercase; }
    .v-legend-item b { font-size: 18px; font-weight: 800; color: var(--text); margin-left: auto; font-family: monospace; }
</style>

<div class="balance-title">
    <h1>Balance</h1>
</div>

<div class="segmented-control-tech">
    <button type="button" class="segment-btn active" onclick="changePeriod('hoy', this)">
        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        Hoy
    </button>
    <button type="button" class="segment-btn" onclick="changePeriod('semana', this)">
        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
        Semana
    </button>
    <button type="button" class="segment-btn" onclick="changePeriod('mes', this)">
        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
        Mes
    </button>
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
        <span style="font-size: 11px; opacity: 0.7; text-transform: uppercase;" id="card-title">Ingresos de hoy</span>
        <div class="card-amount" id="card-amount">Gs. 0</div>
    </div>
    <div class="card-bottom"><div class="card-date" id="card-date">FECHA: <?= date('d/m') ?></div></div>
</div>

<div class="summary-section">
    <h2>Resumen</h2>
    <p id="summary-period-label">Detalles de entregas</p>
</div>

<div class="progress-viz-box">
    <div class="donut-container">
        <div class="goal-glow" id="goal-glow"></div>
        <svg viewBox="0 0 100 100" class="donut-svg">
            <!-- Base gris -->
            <circle cx="50" cy="50" r="38" class="donut-bg"></circle>
            <!-- Entregados -->
            <circle cx="50" cy="50" r="38" class="donut-seg" id="seg-delivered" style="stroke-dasharray: 0 238.76104167282;"></circle>
            <!-- Cancelados -->
            <circle cx="50" cy="50" r="38" class="donut-seg seg-canceled" id="seg-canceled" style="stroke-dasharray: 0 238.76104167282; stroke-dashoffset: 0;"></circle>
        </svg>
        <div class="donut-center">
            <span id="donut-center-val">0</span>
            <b id="donut-center-label">Meta: 5</b>
        </div>
    </div>

    <div class="v-legend">
        <div class="v-legend-item">
            <div class="dot blue" id="dot-delivered"></div>
            <span>entregados</span>
            <b id="legend-delivered-val">0</b>
        </div>
        <div class="v-legend-item">
            <div class="dot red"></div>
            <span>cancelados</span>
            <b id="legend-canceled-val">0</b>
        </div>
    </div>
</div>

<script>
    const statsData = <?= $stats_json ?>;
    const goals = {
        hoy: 5,
        semana: 25,
        mes: 100
    };

    function formatCurrency(val) {
        return new Intl.NumberFormat('es-PY', { minimumFractionDigits: 0 }).format(val);
    }

    function changePeriod(period, btn) {
        document.querySelectorAll('.segmented-control-tech .segment-btn').forEach(b => {
            b.classList.remove('active');
        });
        if (btn) {
            btn.classList.add('active');
        }
        updatePeriod(period);
    }

    function updatePeriod(period) {
        const data = statsData[period];
        const goal = goals[period];
        
        // 1. Actualizar Card Virtual
        document.getElementById('card-title').innerText = 'Ingresos ' + (period === 'hoy' ? 'de hoy' : period === 'semana' ? 'semanales' : 'mensuales');
        document.getElementById('card-amount').innerText = 'Gs. ' + formatCurrency(data.earnings);
        
        // Mostrar/ocultar fecha según el período seleccionado
        const dateEl = document.getElementById('card-date');
        if (dateEl) {
            dateEl.style.display = (period === 'hoy') ? 'block' : 'none';
        }
        
        // 2. Actualizar Textos
        document.getElementById('summary-period-label').innerText = 'Detalles de entregas (' + data.label + ')';
        document.getElementById('legend-delivered-val').innerText = data.entregados;
        document.getElementById('legend-canceled-val').innerText = data.cancelados;
        
        // 3. Actualizar Donut SVG
        const circum = 2 * Math.PI * 38; // ~238.761
        const isGoalReached = (data.vueltas >= goal);
        
        let val_e = 0;
        let val_c = 0;
        let colorClass = 'seg-delivered';
        
        if (!isGoalReached) {
            val_e = (data.entregados / goal) * circum;
            val_c = (data.cancelados / goal) * circum;
        } else {
            const total = data.vueltas || 1;
            const ratio_e = data.entregados / total;
            const ratio_c = data.cancelados / total;
            val_e = ratio_e * circum;
            val_c = ratio_c * circum;
            colorClass = 'seg-delivered completed';
        }
        
        const segDelivered = document.getElementById('seg-delivered');
        const segCanceled = document.getElementById('seg-canceled');
        const dotDelivered = document.getElementById('dot-delivered');
        
        segDelivered.setAttribute('class', 'donut-seg ' + colorClass);
        segDelivered.style.strokeDasharray = `${val_e} ${circum}`;
        
        // Si la meta está alcanzada, el color en la leyenda también cambia a verde
        if (isGoalReached) {
            dotDelivered.style.background = '#10b981';
        } else {
            dotDelivered.style.background = 'var(--primary)';
        }
        
        segCanceled.style.strokeDasharray = `${val_c} ${circum}`;
        segCanceled.style.strokeDashoffset = -val_e;
        
        // 4. Actualizar textos centrales del Donut
        document.getElementById('donut-center-val').innerText = data.entregados;
        document.getElementById('donut-center-label').innerText = 'Meta: ' + goal;
        
        // 5. Actualizar animación de brillo (Pulse)
        const glowEl = document.getElementById('goal-glow');
        if (isGoalReached) {
            glowEl.style.animation = 'glowPulse 2s infinite';
            glowEl.style.opacity = '1';
        } else {
            glowEl.style.animation = 'none';
            glowEl.style.opacity = '0';
        }
    }

    // Inicializar por defecto
    window.addEventListener('DOMContentLoaded', () => {
        updatePeriod('hoy');
    });
</script>

<?php require __DIR__ . '/_footer.php'; ?>
