<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();

$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

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

$rows = app_all(
    "SELECT d.*, l.business_name as local_name, r.name as repartidor_name
     FROM deliveries d
     LEFT JOIN users l ON l.id = d.local_user_id
     LEFT JOIN users r ON r.id = d.repartidor_user_id
     WHERE $where
     ORDER BY d.created_at DESC",
    $types,
    $params
);

$totalDeliveries = count($rows);
$totalEarnings = 0.0;
$totalProducts = 0.0;

foreach ($rows as $r) {
    if ($r['status'] === 'entregado') {
        $totalEarnings += (float)$r['delivery_cost'];
        $totalProducts += (float)$r['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Entregas - <?= htmlspecialchars($startDate) ?> a <?= htmlspecialchars($endDate) ?></title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #334155;
            margin: 20px;
            font-size: 11px;
            line-height: 1.4;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .header-table td {
            vertical-align: middle;
        }
        .title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 5px;
            text-transform: uppercase;
            letter-spacing: -0.5px;
        }
        .subtitle {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
        }
        .meta-box {
            text-align: right;
            font-size: 11px;
            color: #475569;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            text-align: center;
            background: #f8fafc;
        }
        .stat-card small {
            display: block;
            font-size: 9px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .stat-card b {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }
        .stat-card.primary b {
            color: #2563eb;
        }
        .stat-card.success b {
            color: #10b981;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .report-table th {
            background: #f1f5f9;
            border-bottom: 2px solid #cbd5e1;
            color: #475569;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 9px;
            padding: 10px 8px;
            text-align: left;
            letter-spacing: 0.5px;
        }
        .report-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 8px;
            vertical-align: middle;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-entregado { background: #d1fae5; color: #065f46; }
        .status-pendiente { background: #fef3c7; color: #92400e; }
        .status-cancelado { background: #fee2e2; color: #991b1b; }
        .status-otros { background: #e0f2fe; color: #075985; }
        
        .btn-print {
            padding: 8px 16px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 11px;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
            transition: all 0.2s;
        }
        .btn-print:hover {
            background: #1d4ed8;
        }
        
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
            @page { size: portrait; margin: 1.5cm; }
        }
    </style>
</head>
<body>

    <table class="header-table">
        <tr>
            <td>
                <h1 class="title">Reporte de Entregas</h1>
                <span class="subtitle">Período: <?= htmlspecialchars(date('d/m/Y', strtotime($startDate))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($endDate))) ?></span>
            </td>
            <td class="meta-box">
                <b>Usuario:</b> <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars(strtoupper($user['role'])) ?>)<br>
                <b>Generado:</b> <?= date('d/m/Y H:i') ?><br>
                <button class="no-print btn-print" onclick="window.print()" style="margin-top: 8px;">🖨️ Imprimir / PDF</button>
            </td>
        </tr>
    </table>

    <div class="stats-grid">
        <div class="stat-card">
            <small>Total Pedidos</small>
            <b><?= $totalDeliveries ?></b>
        </div>
        <div class="stat-card success">
            <small>Ingresos de Envíos (Completados)</small>
            <b>Gs. <?= number_format($totalEarnings, 0, ',', '.') ?></b>
        </div>
        <div class="stat-card primary">
            <small>Monto de Productos</small>
            <b>Gs. <?= number_format($totalProducts, 0, ',', '.') ?></b>
        </div>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Local</th>
                <th>Repartidor</th>
                <th>Cliente</th>
                <th>Dirección</th>
                <th style="text-align: right;">Envío</th>
                <th style="text-align: right;">Producto</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; color: #94a3b8; padding: 30px; font-weight: 600;">No se encontraron pedidos en este rango de fechas.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): 
                    $status = strtolower($row['status']);
                    $badgeClass = 'status-otros';
                    if ($status === 'entregado') $badgeClass = 'status-entregado';
                    elseif ($status === 'pendiente') $badgeClass = 'status-pendiente';
                    elseif ($status === 'cancelado' || $status === 'rechazado') $badgeClass = 'status-cancelado';
                    
                    $total = $row['amount'] + $row['delivery_cost'];
                ?>
                    <tr>
                        <td><b>#<?= $row['id'] ?></b></td>
                        <td><?= date('d/m/y H:i', strtotime($row['created_at'])) ?></td>
                        <td><span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td><?= htmlspecialchars($row['local_name'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['repartidor_name'] ?: 'No asignado') ?></td>
                        <td><?= htmlspecialchars($row['customer_name'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['delivery_address'] ?: 'N/A') ?></td>
                        <td style="text-align: right;">Gs. <?= number_format((float)$row['delivery_cost'], 0, ',', '.') ?></td>
                        <td style="text-align: right;">Gs. <?= number_format((float)$row['amount'], 0, ',', '.') ?></td>
                        <td style="text-align: right;"><b>Gs. <?= number_format((float)$total, 0, ',', '.') ?></b></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <script>
        window.onload = () => {
            setTimeout(() => {
                window.print();
            }, 600);
        };
    </script>
</body>
</html>
