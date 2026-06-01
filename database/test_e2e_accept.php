<?php
require_once __DIR__ . '/../bootstrap.php';

$orderId = 67; // ID del pedido creado en el paso anterior
$repartidorId = 3; // Carlos

echo "--- ETAPA: Aceptando Pedido #$orderId por Repartidor #$repartidorId ---\n";

// Simulamos la lógica de api_accept_order.php
$updated = app_exec("
    UPDATE deliveries 
    SET repartidor_user_id = ?, status = 'aceptado', updated_at = NOW()
    WHERE id = ? AND status = 'pendiente' AND repartidor_user_id IS NULL
", 'ii', [$repartidorId, $orderId]);

if ($updated > 0) {
    echo "[OK] Pedido aceptado correctamente.\n";
    
    // Verificar en BD
    $order = app_one("SELECT status, repartidor_user_id FROM deliveries WHERE id = ?", 'i', [$orderId]);
    if ($order && $order['status'] === 'aceptado' && (int)$order['repartidor_user_id'] === $repartidorId) {
        echo "[OK] Verificación de base de datos exitosa: Estado 'aceptado', Repartidor #$repartidorId asignado.\n";
    } else {
        echo "[X] ERROR: Datos de la base de datos no coinciden.\n";
    }
} else {
    echo "[X] ERROR: No se pudo aceptar el pedido. ¿Ya fue tomado o el ID es incorrecto?\n";
}
