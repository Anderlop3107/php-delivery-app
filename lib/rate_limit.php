<?php
declare(strict_types=1);

/**
 * Rate Limiting por IP usando base de datos.
 * Tabla: rate_limits (ip VARCHAR(45), endpoint VARCHAR(100), attempts INT, window_start DATETIME)
 */

function rate_limit_check(string $endpoint, int $maxAttempts = 60, int $windowSeconds = 60): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Lista blanca: Ignorar rate limit para endpoints de polling muy frecuentes
    $polling_endpoints = [
        'api_admin_action.php',
        'api_check_new_orders.php',
        'api_get_active_deliveries.php',
        'api_get_order_live_location.php',
        'api_driver_active_count.php',
        'api_check_approval.php'
    ];
    if (in_array($endpoint, $polling_endpoints, true)) {
        return true;
    }

    
    // Limpiar entradas viejas (más de 1 hora)
    try {
        app_exec("
            DELETE FROM rate_limits 
            WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
    } catch (Throwable $e) {
        // Si la tabla no existe aún, ignorar
        return true;
    }
    
    // Buscar intentos actuales
    $row = app_one("
        SELECT attempts, window_start 
        FROM rate_limits 
        WHERE ip = ? AND endpoint = ? 
        LIMIT 1
    ", 'ss', [$ip, $endpoint]);
    
    if (!$row) {
        // Primera petición en la ventana
        app_exec("
            INSERT INTO rate_limits (ip, endpoint, attempts, window_start) 
            VALUES (?, ?, 1, NOW())
        ", 'ss', [$ip, $endpoint]);
        return true;
    }
    
    $windowStart = strtotime($row['window_start']);
    $elapsed = time() - $windowStart;
    
    if ($elapsed > $windowSeconds) {
        // Ventana expirada, resetear
        app_exec("
            UPDATE rate_limits 
            SET attempts = 1, window_start = NOW() 
            WHERE ip = ? AND endpoint = ?
        ", 'ss', [$ip, $endpoint]);
        return true;
    }
    
    if ((int)$row['attempts'] >= $maxAttempts) {
        return false; // Límite excedido
    }
    
    // Incrementar contador
    app_exec("
        UPDATE rate_limits 
        SET attempts = attempts + 1 
        WHERE ip = ? AND endpoint = ?
    ", 'ss', [$ip, $endpoint]);
    
    return true;
}

function rate_limit_deny(): void
{
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Demasiadas peticiones. Intentá de nuevo en unos segundos.'
    ]);
    exit;
}
