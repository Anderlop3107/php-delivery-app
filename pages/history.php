<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();

// 1. Obtener rango de fechas y página actual
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Lógica de filtrado
$where = "DATE(d.created_at) BETWEEN ? AND ?";
$types = "ss";
$params = [$startDate, $endDate];

if ($user['role'] === 'local') {
    $where .= " AND d.local_user_id = ?";
    $types .= "i";
    $params[] = (int) $user['id'];
} elseif ($user['role'] === 'repartidor') {
    $where .= " AND d.repartidor_user_id = ?";
    $types .= "i";
    $params[] = (int) $user['id'];
}

// Obtener el total de registros para calcular las páginas
$countRow = app_one(
    "SELECT COUNT(*) AS total FROM deliveries d WHERE $where",
    $types,
    $params
);
$totalOrders = (int)($countRow['total'] ?? 0);
$totalPages = ceil($totalOrders / $limit);

// Preparar parámetros con límite y desplazamiento
$paramsWithLimit = $params;
$paramsWithLimit[] = $limit;
$paramsWithLimit[] = $offset;
$typesWithLimit = $types . "ii";

$rows = app_all(
    "SELECT d.*, l.name AS local_name, r.name AS repartidor_name, l.business_name
     FROM deliveries d
     LEFT JOIN users l ON l.id = d.local_user_id
     LEFT JOIN users r ON r.id = d.repartidor_user_id
     WHERE $where
     ORDER BY d.created_at DESC
     LIMIT ? OFFSET ?",
    $typesWithLimit,
    $paramsWithLimit
);

function getPageUrl($p, $startDate, $endDate, $month, $year) {
    return '?' . http_build_query([
        'page' => $p,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'month' => $month,
        'year' => $year
    ]);
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfMonth = date('w', strtotime("$year-$month-01"));
$monthsNames = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];
$monthName = $monthsNames[$month];

$title = 'Historial';
require __DIR__ . '/_header.php';
?>

<style>
    .history-header-bento { 
        background: #fff; padding: 24px; border-radius: 0 0 32px 32px; 
        box-shadow: var(--shadow); margin: -25px -20px 25px; border-bottom: 1px solid rgba(0,0,0,0.02);
    }
    
    .calendar-top-bento { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .month-selector-bento { 
        border: none; font-size: 1.25rem; font-weight: 800; color: var(--text); background: transparent; outline: none; cursor: pointer; padding: 0;
    }
    
    /* Gap 0 for seamless strip */
    .calendar-grid-bento { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0; text-align: center; }
    .calendar-day-label-bento { font-size: 9px; font-weight: 800; color: #cbd5e1; text-transform: uppercase; margin-bottom: 8px; }
    
    .calendar-day-bento { 
        height: 34px; width: 100%;
        display: flex; align-items: center; justify-content: center; 
        font-size: 12px; font-weight: 600; color: #475569;
        cursor: pointer; text-decoration: none; transition: all 0.2s; 
    }

    /* Range Selection Styles - Solid, transparent blue strip */
    .calendar-day-bento.active { background: var(--primary) !important; color: #ffffff !important; box-shadow: 0 8px 15px rgba(37, 99, 235, 0.2); transform: scale(1.05); border-radius: 8px !important; }
    .calendar-day-bento.in-range { background: var(--primary-soft); color: var(--primary); border-radius: 0; }
    .calendar-day-bento.today { color: var(--primary); border: 2px solid var(--primary-soft); border-radius: 8px; }

    /* Bento Export Buttons */
    .export-btn-bento {
        padding: 8px 14px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600; /* Fine typography */
        text-decoration: none;
        letter-spacing: -0.1px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid #cbd5e1; /* Soft slate border */
        background: #ffffff;
        color: #475569; /* Sleek slate color */
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .export-btn-bento svg {
        color: #64748b; /* Lighter icon color */
        transition: color 0.2s;
    }
    .export-btn-bento:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        color: var(--primary);
    }
    .export-btn-bento:hover svg {
        color: var(--primary);
    }
    .export-btn-bento:active {
        transform: scale(0.97);
        background: #f1f5f9;
    }

    /* History List Items */
    .order-card-bento { 
        background: #fff; border-radius: var(--card-radius); padding: 20px; margin-bottom: 12px; 
        box-shadow: var(--shadow); border: 1px solid rgba(0,0,0,0.01);
        display: flex; align-items: center; gap: 16px; cursor: pointer; transition: transform 0.2s ease;
    }
    .order-card-bento:active { transform: scale(0.98); }
    
    .order-icon-bento { 
        width: 48px; height: 48px; border-radius: 16px; background: var(--primary-soft); 
        display: flex; align-items: center; justify-content: center; color: var(--primary); 
    }
    .order-info-bento { flex: 1; }
    .order-info-bento h4 { margin: 0; font-size: 15px; font-weight: 800; color: var(--text); }
    .order-info-bento p { margin: 4px 0 0; font-size: 12px; color: var(--muted); font-weight: 500; }
    
    .order-amount-bento { text-align: right; }
    .order-amount-bento b { display: block; color: var(--text); font-size: 15px; font-weight: 800; }
    
    /* Tech Status Pills */
    .pill-tech {
        display: inline-block; padding: 4px 10px; border-radius: 8px; font-size: 9px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px;
    }
    .pill-delivered { background: #ecfdf5; color: #10b981; }
    .pill-pending { background: #fffbeb; color: #f59e0b; }
    .pill-canceled { background: #fef2f2; color: #ef4444; }
    .pill-process { background: #eff6ff; color: var(--primary); }

    /* Glass Modal */
    .modal-overlay-glass { 
        position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
        background: rgba(15, 23, 42, 0.3); backdrop-filter: blur(8px); z-index: 3000; 
        display: none; align-items: flex-end; 
    }
    .modal-content-tech { 
        background: #FFFFFF;
        width: 100%;
        border-radius: 32px 32px 0 0;
        padding: 40px 28px 60px;
        transform: translateY(100%);
        transition: transform 0.4s cubic-bezier(0.19, 1, 0.22, 1);
        box-shadow: 0 -20px 50px rgba(0,0,0,0.15);
        border-top: 6px solid var(--primary); /* Premium Accent */
    }
    .modal-overlay-glass.active { display: flex; }
    .modal-overlay-glass.active .modal-content-tech { transform: translateY(0); }
    
    .detail-row-tech { display: flex; justify-content: space-between; margin-bottom: 18px; padding-bottom: 18px; border-bottom: 1px solid var(--bg); }
    .detail-row-tech span { color: var(--muted); font-size: 13px; font-weight: 600; text-transform: uppercase; }
    .detail-row-tech b { color: var(--text); font-size: 14px; text-align: right; font-weight: 700; }

    .btn-maps-tech { 
        width: 100%; margin-top: 24px; background: var(--primary); color: #fff; border: none; border-radius: 18px; 
        padding: 18px; font-weight: 700; font-size: 15px; display: flex; align-items: center; 
        justify-content: center; gap: 12px; box-shadow: 0 10px 20px var(--primary-soft);
    }
    
    /* Premium Banner - Refined */
    .premium-banner {
        display: flex; align-items: center; gap: 18px;
        background: rgba(37, 99, 235, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
        padding: 20px; border-radius: 20px;
        margin-bottom: 16px; color: #fff; box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    .crown-icon {
        width: 56px; height: 56px; background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(5px); border-radius: 16px; 
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; flex-shrink: 0; border: 1px solid rgba(255, 255, 255, 0.2);
    }

    /* Pagination Styles */
    .pagination-container-tech {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 24px;
        margin-bottom: 30px;
        padding: 12px;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: var(--shadow);
        border: 1px solid rgba(0,0,0,0.02);
    }
    .pagination-btn-tech {
        padding: 10px 18px;
        font-size: 13px;
        font-weight: 700;
        color: var(--primary);
        background: var(--primary-soft);
        border-radius: 12px;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .pagination-btn-tech:hover:not(.disabled) {
        background: var(--primary);
        color: #fff;
        transform: translateY(-1px);
    }
    .pagination-btn-tech.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
        color: var(--muted);
        background: var(--bg);
    }
    .pagination-pages-tech {
        display: flex;
        gap: 6px;
        align-items: center;
    }
    .pagination-page-btn-tech {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 700;
        color: var(--text);
        text-decoration: none;
        transition: all 0.2s ease;
        background: var(--bg);
    }
    .pagination-page-btn-tech:hover {
        background: rgba(37, 99, 235, 0.1);
        color: var(--primary);
    }
    .pagination-page-btn-tech.active {
        background: var(--primary);
        color: #ffffff;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
    }
</style>

<div class="history-header-bento">
    <div class="calendar-top-bento">
        <select class="month-selector-bento" onchange="changeMonth(this.value)">
            <?php foreach ($monthsNames as $num => $name): ?>
                <option value="<?= $num ?>" <?= $month == $num ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
        </select>
        
        <div style="display: flex; gap: 8px;">
            <a href="export_pdf.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" target="_blank" class="export-btn-bento" title="Exportar a PDF / Imprimir">
                <svg style="width: 14px; height: 14px; stroke: currentColor;" fill="none" stroke-width="2.2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 9V2h12v7"></path>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                <span>Exportar PDF</span>
            </a>
        </div>
    </div>

    <div class="calendar-grid-bento">
        <div class="calendar-day-label-bento">D</div><div class="calendar-day-label-bento">L</div><div class="calendar-day-label-bento">M</div><div class="calendar-day-label-bento">X</div><div class="calendar-day-label-bento">J</div><div class="calendar-day-label-bento">V</div><div class="calendar-day-label-bento">S</div>
        <?php 
        $pad = ($firstDayOfMonth == 0) ? 6 : $firstDayOfMonth - 1;
        for ($i = 0; $i < $pad; $i++) echo '<div></div>';
        for ($day = 1; $day <= $daysInMonth; $day++):
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        ?>
            <div class="calendar-day-bento" id="day-<?= $dateStr ?>" onclick="selectDate('<?= $dateStr ?>')">
                <?= $day ?>
            </div>
        <?php endfor; ?>
    </div>
</div>

<div class="premium-banner">
    <div class="crown-icon">
        <img src="../uploads/logos/corona.png" alt="Crown" style="width: 100px; height: 100px; object-fit: contain;">
    </div>
    <div>
        <h4 style="margin:0; font-size:14px; color:#fff; font-weight: 800;">Cantidad de pedidos</h4>
        <p style="margin:0; font-size:12px; opacity:0.8; font-weight: 600;"><?= $totalOrders ?> Pedidos realizados</p>
    </div>
</div>

<div class="history-list">
    <?php foreach ($rows as $row): 
        $s = $row['status'];
        $pill_class = 'pill-process';
        if ($s === 'entregado') $pill_class = 'pill-delivered';
        if ($s === 'pendiente') $pill_class = 'pill-pending';
        if ($s === 'cancelado' || $s === 'rechazado') $pill_class = 'pill-canceled';
    ?>
        <div class="order-card-bento" onclick='showDetails(<?= json_encode($row) ?>)'>
            <div class="order-icon-bento"><svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 11-8 0v4M5 9h14l1 12H4L5 9z"></path></svg></div>
            <div class="order-info-bento">
                <h4>ID #<?= $row['id'] ?></h4>
                <p><?= esc($row['customer_name'] ?: 'Cliente') ?></p>
                <span class="pill-tech <?= $pill_class ?>"><?= str_replace('_', ' ', $s) ?></span>
            </div>
            <div class="order-amount-bento">
                <b><?= number_format($row['delivery_cost'], 0, ',', '.') ?> Gs.</b>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination-container-tech">
    <a href="<?= getPageUrl($page - 1, $startDate, $endDate, $month, $year) ?>" class="pagination-btn-tech <?= $page <= 1 ? 'disabled' : '' ?>">&larr; Anterior</a>
    
    <div class="pagination-pages-tech">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= getPageUrl($p, $startDate, $endDate, $month, $year) ?>" class="pagination-page-btn-tech <?= $page === $p ? 'active' : '' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>
    </div>
    
    <a href="<?= getPageUrl($page + 1, $startDate, $endDate, $month, $year) ?>" class="pagination-btn-tech <?= $page >= $totalPages ? 'disabled' : '' ?>">Siguiente &rarr;</a>
</div>
<?php endif; ?>

<div id="modal" class="modal-overlay-glass" onclick="closeModal()">
    <div class="modal-content-tech" onclick="event.stopPropagation()">
        <div class="modal-header-tech">
            <h3 style="font-size: 20px;">Detalles de Envío</h3>
        </div>
        <div id="modal-body"></div>
        <button type="button" id="modal-gps-btn" class="btn-maps-tech">Localizar en Maps</button>
    </div>
</div>

<script>
    let startDate = '<?= $startDate ?>';
    let endDate = '<?= $endDate ?>';

    function selectDate(date) {
        if (!startDate || (startDate && endDate)) {
            startDate = date; endDate = null;
        } else {
            endDate = date;
            if (endDate < startDate) { [startDate, endDate] = [endDate, startDate]; }
            window.location.href = `?start_date=${startDate}&end_date=${endDate}&month=<?= $month ?>&year=<?= $year ?>`;
        }
        updateCalendar();
    }

    function updateCalendar() {
        document.querySelectorAll('.calendar-day-bento').forEach(el => {
            const d = el.id.replace('day-', '');
            el.classList.remove('active', 'in-range', 'start-point', 'end-point');
            
            if (d === startDate || d === endDate) { el.classList.add('active'); }
            if (startDate && endDate && d > startDate && d < endDate) { el.classList.add('in-range'); }
            if (startDate && d === startDate) { el.classList.add('start-point'); }
            if (endDate && d === endDate) { el.classList.add('end-point'); }
        });
    }
    updateCalendar(); // Inicializar

    function changeMonth(m) { 
        let y = <?= $year ?>;
        if(m > 12) { m = 1; y++; } else if(m < 1) { m = 12; y--; }
        window.location.href = `?month=${m}&year=${y}&start_date=${startDate}&end_date=${endDate}`; 
    }
    function showDetails(order) {
        const body = document.getElementById('modal-body');
        const total = parseInt(order.amount) + parseInt(order.delivery_cost);
        body.innerHTML = `
            <div class="detail-row-tech"><span>Cliente</span><b>${order.customer_name || 'Sin nombre'}</b></div>
            <div class="detail-row-tech"><span>Dirección</span><b>${order.delivery_address}</b></div>
            <div class="detail-row-tech"><span>Producto</span><b>${parseInt(order.amount).toLocaleString('de-DE')} Gs.</b></div>
            <div class="detail-row-tech"><span>Envío</span><b>${parseInt(order.delivery_cost).toLocaleString('de-DE')} Gs.</b></div>
            <div class="detail-row-tech" style="border:none; margin-top:10px;"><span style="color:var(--primary); font-weight:800;">TOTAL</span><b style="color:var(--primary); font-size:18px;">${total.toLocaleString('de-DE')} Gs.</b></div>
        `;
        const gpsBtn = document.getElementById('modal-gps-btn');
        if (order.delivery_latitude && order.delivery_longitude) {
            gpsBtn.style.display = 'flex';
            gpsBtn.onclick = () => window.open(`https://www.google.com/maps/search/?api=1&query=${order.delivery_latitude},${order.delivery_longitude}`);
        } else {
            gpsBtn.style.display = 'none';
        }
        document.getElementById('modal').classList.add('active');
    }
    function closeModal() { document.getElementById('modal').classList.remove('active'); }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
