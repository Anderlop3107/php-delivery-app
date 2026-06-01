<?php
require_once __DIR__ . '/../bootstrap.php';
$pedidos = app_all("SELECT * FROM deliveries WHERE customer_name = ?", 's', ['Usuario de Prueba']);
print_r($pedidos);
