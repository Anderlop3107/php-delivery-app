<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();

// Obtener fecha seleccionada (por defecto hoy)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Lógica de filtrado
$where = "DATE(d.created_at) = ?";
$types = "s";
$params = [$selectedDate];

if ($user['role'] === 'local') {
    $where .= " AND d.local_user_id = ?";
    $types .= "i";
    $params[] = (int) $user['id'];
} elseif ($user['role'] === 'repartidor') {
    $where .= " AND d.repartidor_user_id = ?";
    $types .= "i";
    $params[] = (int) $user['id'];
}

$rows = app_all(
    "SELECT d.*, l.name AS local_name, r.name AS repartidor_name, l.business_name
     FROM deliveries d
     LEFT JOIN users l ON l.id = d.local_user_id
     LEFT JOIN users r ON r.id = d.repartidor_user_id
     WHERE $where
     ORDER BY d.created_at DESC",
    $types,
    $params
);

// Resumen del día
$daySummary = [
    'count' => count($rows),
    'total_amount' => array_sum(array_column($rows, 'amount')),
];

// Configuración del Calendario
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfMonth = date('w', strtotime("$year-$month-01"));
$monthsNames = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

$title = 'Historial';
require __DIR__ . '/_header.php';
?>

<style>
    .history-header { background: #fff; padding: 15px; border-radius: 0 0 24px 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); margin: -25px -20px 20px; border-bottom: 1px solid var(--border); }
    .calendar-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
    .month-selector { border: none; font-size: 1.1rem; font-weight: 800; color: var(--text); background: transparent; outline: none; cursor: pointer; }
    
    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
    .calendar-day-label { font-size: 9px; font-weight: 800; color: #cbd5e1; text-transform: uppercase; margin-bottom: 5px; }
    .calendar-day { height: 32px; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; color: #64748b; border-radius: 10px; cursor: pointer; text-decoration: none; transition: all 0.2s; }
    .calendar-day.active { background: var(--primary) !important; color: #ffffff !important; box-shadow: 0 4px 10px rgba(255, 140, 66, 0.3); }
    .calendar-day.today { color: var(--primary); border: 1.5px solid var(--primary); }

    .day-summary-row { display: flex; gap: 10px; margin-top: 15px; }
    .summary-mini-card { flex: 1; background: var(--primary-soft); padding: 10px 15px; border-radius: 14px; border: 1px solid rgba(255, 140, 66, 0.1); }
    .summary-mini-card span { display: block; font-size: 9px; font-weight: 800; color: var(--primary); text-transform: uppercase; opacity: 0.8; }
    .summary-mini-card b { font-size: 15px; color: var(--text); }

    .order-card { background: #fff; border-radius: 20px; padding: 15px; margin-bottom: 12px; border: 1px solid var(--border); display: flex; align-items: center; gap: 15px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
    .order-icon { width: 42px; height: 42px; border-radius: 12px; background: var(--primary-soft); display: flex; align-items: center; justify-content: center; color: var(--primary); }
    .order-info { flex: 1; }
    .order-info h4 { margin: 0; font-size: 14px; color: var(--text); }
    .order-info p { margin: 2px 0 0; font-size: 12px; color: #64748b; font-weight: 500; }
    .order-amount { text-align: right; }
    .order-amount b { display: block; color: var(--text); font-size: 14px; }
    .order-amount span { font-size: 9px; color: #10b981; font-weight: 800; text-transform: uppercase; }

    /* Modal Styles */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000; display: none; align-items: flex-end; }
    .modal-content { background: #fff; width: 100%; border-radius: 30px 30px 0 0; padding: 30px 20px 50px; transform: translateY(100%); transition: transform 0.3s ease-out; }
    .modal-overlay.active { display: flex; }
    .modal-overlay.active .modal-content { transform: translateY(0); }
    
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .modal-close { background: #f1f5f9; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    
    .detail-row { display: flex; justify-content: space-between; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f1f5f9; }
    .detail-row span { color: #64748b; font-size: 13px; }
    .detail-row b { color: var(--text); font-size: 13px; text-align: right; }
</style>

<div class="history-header">
    <div class="calendar-top">
        <select class="month-selector" onchange="changeMonth(this.value)">
            <?php foreach ($monthsNames as $num => $name): ?>
                <option value="<?= $num ?>" <?= $month == $num ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
        </select>
        <span style="font-weight: 800; color: #94a3b8; font-size: 14px;"><?= $year ?></span>
    </div>

    <div class="calendar-grid">
        <div class="calendar-day-label">D</div>
        <div class="calendar-day-label">L</div>
        <div class="calendar-day-label">M</div>
        <div class="calendar-day-label">X</div>
        <div class="calendar-day-label">J</div>
        <div class="calendar-day-label">V</div>
        <div class="calendar-day-label">S</div>

        <?php 
        for ($i = 0; $i < $firstDayOfMonth; $i++) echo '<div></div>';
        for ($day = 1; $day <= $daysInMonth; $day++):
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $isActive = $selectedDate === $dateStr;
            $isToday = date('Y-m-d') === $dateStr;
        ?>
            <a href="?date=<?= $dateStr ?>&month=<?= $month ?>&year=<?= $year ?>" 
               class="calendar-day <?= $isActive ? 'active' : '' ?> <?= $isToday ? 'today' : '' ?>">
                <?= $day ?>
            </a>
        <?php endfor; ?>
    </div>

    <div class="day-summary-row">
        <div class="summary-mini-card">
            <span>Entregas</span>
            <b><?= $daySummary['count'] ?></b>
        </div>
        <div class="summary-mini-card">
            <span>Recaudado</span>
            <b><?= number_format($daySummary['total_amount'], 0, ',', '.') ?> Gs.</b>
        </div>
    </div>
</div>

<div class="history-list">
    <?php if (empty($rows)): ?>
        <div style="text-align: center; padding: 40px 20px;">
            <p class="muted">No hay pedidos registrados para esta fecha.</p>
        </div>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <div class="order-card" onclick='showDetails(<?= json_encode($row) ?>)'>
                <div class="order-icon">
                    <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 11-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                </div>
                <div class="order-info">
                    <h4>ID #<?= $row['id'] ?></h4>
                    <p><?= esc($row['customer_name'] ?: 'Cliente Anonimo') ?></p>
                </div>
                <div class="order-amount">
                    <b><?= number_format($row['amount'], 0, ',', '.') ?> Gs.</b>
                    <span><?= strtoupper($row['status']) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal de Detalles -->
<div id="modal" class="modal-overlay" onclick="closeModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3 id="modal-title">Detalles del Pedido</h3>
            <div class="modal-close" onclick="closeModal()">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </div>
        </div>
        
        <div id="modal-body">
            <!-- Dinámico -->
        </div>

        <button type="button" id="modal-gps-btn" style="width: 100%; margin-top: 20px; background: #fff; color: #0C3A5B; border: 2px solid #0C3A5B;">
            📍 Ver en Google Maps
        </button>
    </div>
</div>

<script>
    function changeMonth(m) {
        window.location.href = `?month=${m}&year=<?= $year ?>&date=<?= $selectedDate ?>`;
    }

    function showDetails(order) {
        const body = document.getElementById('modal-body');
        const total = parseInt(order.amount) + parseInt(order.delivery_cost);
        
        body.innerHTML = `
            <div class="detail-row"><span>Cliente</span><b>${order.customer_name || 'Sin nombre'}</b></div>
            <div class="detail-row"><span>Dirección</span><b>${order.delivery_address}</b></div>
            <div class="detail-row"><span>Producto</span><b>${order.amount.toLocaleString()} Gs.</b></div>
            <div class="detail-row"><span>Delivery</span><b>${order.delivery_cost.toLocaleString()} Gs.</b></div>
            <div class="detail-row" style="border:none; margin-top:10px;"><span style="color:#0C3A5B; font-weight:800;">TOTAL FINAL</span><b style="color:#0C3A5B; font-size:18px;">${total.toLocaleString()} Gs.</b></div>
            <div class="detail-row"><span>Repartidor</span><b>${order.repartidor_name || 'Sin asignar'}</b></div>
        `;

        const gpsBtn = document.getElementById('modal-gps-btn');
        if (order.delivery_latitude && order.delivery_longitude) {
            gpsBtn.style.display = 'block';
            gpsBtn.onclick = () => window.open(`https://www.google.com/maps/search/?api=1&query=${order.delivery_latitude},${order.delivery_longitude}`);
        } else {
            gpsBtn.style.display = 'none';
        }

        document.getElementById('modal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('modal').classList.remove('active');
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
