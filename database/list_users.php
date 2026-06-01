<?php
require_once __DIR__ . '/../lib/db.php';
$res = app_all('SELECT email, role, password_hash FROM users');
foreach($res as $u) {
    echo "Email: " . $u['email'] . " | Rol: " . $u['role'] . " | Clave 123456: " . (password_verify('123456', $u['password_hash']) ? 'SÍ' : 'NO') . "\n";
}
