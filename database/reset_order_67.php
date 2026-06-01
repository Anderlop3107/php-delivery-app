<?php
require_once __DIR__ . '/../lib/db.php';
app_exec("UPDATE deliveries SET status = 'pendiente', repartidor_user_id = NULL WHERE id = 67");
echo "Pedido reseteado.\n";
