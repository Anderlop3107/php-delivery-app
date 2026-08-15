<?php

declare(strict_types=1);

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'delivery_app_db');
define('DB_PORT', 3306);

if (!defined('DELIVERY_APP_BASE_PATH')) {
    define('DELIVERY_APP_BASE_PATH', '/php-delivery-app');
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
