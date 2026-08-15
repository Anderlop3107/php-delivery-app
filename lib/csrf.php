<?php
declare(strict_types=1);

/**
 * CSRF Token Protection
 * Genera y valida tokens anti-CSRF por sesión.
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
        http_response_code(403);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
            str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido. Recargá la página e intentá de nuevo.']);
        } else {
            echo 'Error de seguridad: Token CSRF inválido. <a href="javascript:location.reload()">Recargar página</a>';
        }
        exit;
    }
}
