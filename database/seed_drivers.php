<?php
require_once __DIR__ . '/../bootstrap.php';

echo "=== REGISTRANDO Y PREPARANDO REPARTIDORES DE PRUEBA ===\n";

$drivers = [
    [
        'name' => 'Carlos López',
        'email' => 'carlos.repartidor@gmail.com',
        'phone' => '0981123456',
        'password' => '123456',
        'lat' => -25.3550000,
        'lng' => -57.5750000
    ],
    [
        'name' => 'Juan Pérez',
        'email' => 'juan.repartidor@gmail.com',
        'phone' => '0982654321',
        'password' => '123456',
        'lat' => -25.3260000,
        'lng' => -57.5810000
    ]
];

foreach ($drivers as $d) {
    $existing = app_one("SELECT id FROM users WHERE email = ?", 's', [$d['email']]);
    $hash = password_hash($d['password'], PASSWORD_BCRYPT);
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    if ($existing) {
        $driverId = (int)$existing['id'];
        app_exec("
            UPDATE users SET 
                role = 'repartidor',
                name = ?,
                phone = ?,
                password_hash = ?,
                is_online = 1,
                last_ping = NOW(),
                ubicacion_actualizada_en = NOW(),
                latitude = ?,
                longitude = ?,
                subscription_status = 'active',
                subscription_expires_at = ?,
                status_doc_ci = 'approved',
                status_doc_licencia = 'approved',
                status_doc_habilitacion = 'approved',
                status_doc_cedula_verde = 'approved',
                updated_at = NOW()
            WHERE id = ?
        ", 'sssddsi', [$d['name'], $d['phone'], $hash, $d['lat'], $d['lng'], $expires, $driverId]);
        echo "[OK] Repartidor actualizador (ID: $driverId) {$d['name']} ({$d['email']})\n";
    } else {
        app_exec("
            INSERT INTO users (
                role, name, email, phone, password_hash, is_online, last_ping, 
                ubicacion_actualizada_en, latitude, longitude, subscription_status, subscription_expires_at,
                status_doc_ci, status_doc_licencia, status_doc_habilitacion, status_doc_cedula_verde,
                created_at, updated_at
            ) VALUES (
                'repartidor', ?, ?, ?, ?, 1, NOW(), 
                NOW(), ?, ?, 'active', ?,
                'approved', 'approved', 'approved', 'approved',
                NOW(), NOW()
            )
        ", 'ssssdds', [$d['name'], $d['email'], $d['phone'], $hash, $d['lat'], $d['lng'], $expires]);
        $driverId = app_db()->insert_id;
        echo "[OK] Repartidor creado (ID: $driverId) {$d['name']} ({$d['email']})\n";
    }
}

echo "=== REPARTIDORES LISTOS PARA OPERAR ===\n";
