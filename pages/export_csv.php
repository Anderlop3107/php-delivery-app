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

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_entregas_' . $startDate . '_a_' . $endDate . '.csv"');

// Output UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// CSV Headers
fputcsv($output, [
    'ID Pedido', 
    'Fecha Creacion', 
    'Estado', 
    'Comercio/Local', 
    'Repartidor', 
    'Cliente', 
    'Telefono Cliente', 
    'Direccion Entrega', 
    'Monto Producto (Gs)', 
    'Costo Envio (Gs)', 
    'Pago Local?', 
    'Total Pedido (Gs)'
]);

foreach ($rows as $row) {
    $total = $row['amount'] + $row['delivery_cost'];
    fputcsv($output, [
        $row['id'],
        $row['created_at'],
        strtoupper($row['status']),
        $row['local_name'] ?: 'N/A',
        $row['repartidor_name'] ?: 'No asignado',
        $row['customer_name'] ?: 'N/A',
        $row['customer_phone'] ?: 'N/A',
        $row['delivery_address'] ?: 'N/A',
        number_format((float)$row['amount'], 0, ',', '.'),
        number_format((float)$row['delivery_cost'], 0, ',', '.'),
        $row['driver_pays_local'] ? 'SI' : 'NO',
        number_format((float)$total, 0, ',', '.')
    ]);
}

fclose($output);
exit;
