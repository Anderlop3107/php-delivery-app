<?php
require_once __DIR__ . '/../lib/db.php';

$stmt = app_db()->prepare("SELECT email, password_hash FROM users WHERE email = ?");
$email = 'carlos@delivery.com';
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if ($res) {
    echo "Usuario: " . $res['email'] . "\n";
    echo "Hash: " . $res['password_hash'] . "\n";
    if (password_verify('123456', $res['password_hash'])) {
        echo "Contraseña válida: SÍ\n";
    } else {
        echo "Contraseña válida: NO\n";
    }
} else {
    echo "Usuario no encontrado.\n";
}
