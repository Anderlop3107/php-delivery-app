<?php
require_once __DIR__ . '/../lib/db.php';

$newPass = password_hash('123456', PASSWORD_DEFAULT);
$affected = app_exec("UPDATE users SET password_hash = ? WHERE role = 'repartidor'", 's', [$newPass]);

echo "Se actualizaron $affected repartidores con la contraseña: 123456\n";
