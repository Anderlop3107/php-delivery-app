<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function check_login_attempts(string $ip): bool
{
    try {
        // Limpiar intentos viejos (más de 15 minutos)
        app_exec("
            DELETE FROM login_attempts 
            WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        
        $row = app_one("
            SELECT COUNT(*) as cnt 
            FROM login_attempts 
            WHERE ip_address = ? 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ", 's', [$ip]);
        
        return ((int)($row['cnt'] ?? 0)) < 5;
    } catch (Throwable $e) {
        return true; // Si la tabla no existe, permitir
    }
}

function record_failed_login(string $ip, string $email): void
{
    try {
        app_exec("
            INSERT INTO login_attempts (ip_address, email, attempted_at) 
            VALUES (?, ?, NOW())
        ", 'ss', [$ip, $email]);
    } catch (Throwable $e) {
        // Ignorar si la tabla no existe
    }
}

function clear_login_attempts(string $ip): void
{
    try {
        app_exec("DELETE FROM login_attempts WHERE ip_address = ?", 's', [$ip]);
    } catch (Throwable $e) {}
}

function app_login(string $email, string $password, string $ip = ''): bool
{
    if ($ip !== '' && !check_login_attempts($ip)) {
        return false;
    }

    $user = app_one(
        "SELECT id, role, name, email, phone, password_hash, subscription_status, subscription_expires_at
         FROM users
         WHERE email = ?
         LIMIT 1",
        's',
        [$email]
    );

    if (!$user || empty($user['password_hash'])) {
        if ($ip !== '') record_failed_login($ip, $email);
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        if ($ip !== '') record_failed_login($ip, $email);
        return false;
    }

    if ($user['role'] === 'repartidor') {
        $status = (string) ($user['subscription_status'] ?? 'pending');
        $expires = $user['subscription_expires_at'] ?? null;
        $expiredByDate = $expires ? strtotime($expires) < time() : true;

        if ($status !== 'active' || $expiredByDate) {
            $_SESSION['auth_user'] = [
                'id' => (int) $user['id'],
                'role' => $user['role'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'subscription_blocked' => true,
            ];
            return true;
        }
    }

    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'role' => $user['role'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'subscription_blocked' => false,
    ];

    if ($user['role'] === 'repartidor') {
        app_exec("UPDATE users SET is_online = 1 WHERE id = ?", 'i', [(int)$user['id']]);
    }

    if ($ip !== '') clear_login_attempts($ip);
    return true;
}

function current_user(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: ' . delivery_app_url('login.php'));
        exit;
    }
}

function require_role(array $roles): void
{
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        header('Location: ' . delivery_app_url('dashboard.php'));
        exit;
    }
}

function is_subscription_valid(): bool
{
    $user = current_user();
    return $user && !($user['subscription_blocked'] ?? false);
}
