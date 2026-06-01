<?php
require_once __DIR__ . '/../lib/db.php';
$res = app_one('SELECT * FROM deliveries LIMIT 1');
if ($res) {
    print_r(array_keys($res));
} else {
    echo "No hay entregas en la tabla.";
}
