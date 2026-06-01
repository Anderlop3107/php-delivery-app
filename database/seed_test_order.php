<?php
require_once __DIR__ . '/../bootstrap.php';
app_exec("INSERT INTO deliveries (local_user_id, customer_name, customer_phone, delivery_address, amount, status, created_at) VALUES (1, 'Usuario de Prueba', '123456789', 'Calle Falsa 123', 50000, 'pendiente', NOW())");
echo "Pedido de prueba creado.\n";
