<?php

declare(strict_types=1);

// Database configuration
// --- DETECCIÓN DE ENTORNO (PRODUCCIÓN vs LOCAL) ---
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Si el host NO es localhost ni 127.0.0.1, asumimos que es producción (Hostinger)
$is_production = !in_array(explode(':', $host)[0], ['localhost', '127.0.0.1'], true);

if ($is_production) {
    // 🌐 Credenciales de Hostinger
    define('DB_HOST', 'localhost'); // En Hostinger suele ser localhost
    define('DB_USER', 'u129292689_envios_admin'); // Cambiá esto por el user real que te dio Hostinger
    define('DB_PASS', 'Anderlop79@'); // Tu contraseña real
    define('DB_NAME', 'u129292689_envios_db');

    // En producción la app está en la raíz
    define('DELIVERY_APP_BASE_PATH', '');
} else {
    // 💻 Credenciales de XAMPP (Local)
    define('DB_HOST', '127.0.0.1');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'delivery_app_db');
    define('DB_PORT', 3306);

    // En local está en una subcarpeta
    define('DELIVERY_APP_BASE_PATH', '/php-delivery-app');
}

// (Opcional) Si la constante de puerto no está definida en prod, le asignamos la default
if (!defined('DB_PORT')) {
    define('DB_PORT', 3306);
}

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly'  => true,
        'cookie_secure'    => isset($_SERVER['HTTPS']),
        'cookie_samesite'  => 'Lax',
        'use_strict_mode'  => true,
        'cookie_lifetime'  => 0,
        'gc_maxlifetime'   => 7200,
    ]);
}


function delivery_app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return rtrim(DELIVERY_APP_BASE_PATH, '/') . ($path !== '' ? '/' . $path : '');
}
