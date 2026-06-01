<?php
require_once __DIR__ . '/../lib/db.php';
$hash = password_hash('123456', PASSWORD_BCRYPT);
$email = 'Anderlop3107@gmail.com';
$stmt = app_db()->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
$stmt->bind_param('ss', $hash, $email);
if ($stmt->execute()) {
    echo "Contraseña de $email actualizada a: 123456\n";
} else {
    echo "Error al actualizar.\n";
}
