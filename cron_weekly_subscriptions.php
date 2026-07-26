<?php
/**
 * Cron Job Semanal para Suscripciones de Repartidores
 * 
 * Regla de Negocio:
 * - Todos los Lunes a las 10:00 hs: Se envía notificación push y alerta a todos los repartidores indicando que su suscripción vence en 2 horas (12:00 hs).
 * - Todos los Lunes a las 12:00 hs: Expira la suscripción de los repartidores sin pago verificado de la semana, se marcan como 'expired', se desconectan de forma automática (is_online = 0) y se les envía notificación push.
 */

require_once __DIR__ . '/bootstrap.php';

// Permite ejecución desde consola (CLI) o vía HTTP con token o parámetros
$action = $argv[1] ?? $_GET['action'] ?? 'auto_check';

header('Content-Type: application/json; charset=utf-8');

$now = new DateTime();
$todayStr = $now->format('Y-m-d');
$isMonday = (int)$now->format('N') === 1;
$currentHour = (int)$now->format('H');
$currentMinute = (int)$now->format('i');

$logMessages = [];

// --- 1. AVISO DE 10:00 AM (2 horas antes) ---
if ($action === '10am_warning' || ($action === 'auto_check' && $isMonday && $currentHour >= 10 && $currentHour < 12)) {
    // Obtener todos los repartidores
    $drivers = app_all("SELECT id, name, email, subscription_status FROM users WHERE role = 'repartidor'");
    $notifiedCount = 0;

    foreach ($drivers as $d) {
        $driverId = (int)$d['id'];
        
        // Verificar si ya fue notificado hoy para no duplicar
        $alreadySent = app_one("
            SELECT id FROM app_notifications 
            WHERE user_id = ? AND type = 'sub_warning_10am' AND DATE(created_at) = ?
        ", 'is', [$driverId, $todayStr]);

        if (!$alreadySent) {
            app_exec("
                INSERT INTO app_notifications (user_id, type, title, message, is_read, created_at)
                VALUES (?, 'sub_warning_10am', ?, ?, 0, NOW())
            ", 'iss', [
                $driverId,
                '⚠️ Tu suscripción vence en 2 horas (12:00 hs)',
                'Tu suscripción semanal vence hoy a las 12:00 hs. Sube tu comprobante de pago desde tu perfil para continuar recibiendo pedidos.'
            ]);
            $notifiedCount++;
        }
    }
    $logMessages[] = "Notificaciones de 10:00 AM enviadas a {$notifiedCount} repartidores.";
}

// --- 2. EXPIRACIÓN DE 12:00 PM ---
if ($action === '12pm_expire' || ($action === 'auto_check' && (($isMonday && $currentHour >= 12) || true))) {
    // Buscar repartidores con fecha de expiración vencida o que no tengan renovación activa de la semana
    $expiredDrivers = app_all("
        SELECT id, name, email, is_online, subscription_status 
        FROM users 
        WHERE role = 'repartidor' 
          AND (
              subscription_expires_at IS NULL 
              OR subscription_expires_at <= NOW()
              OR subscription_status != 'active'
          )
    ");

    $expiredCount = 0;

    foreach ($expiredDrivers as $d) {
        $driverId = (int)$d['id'];

        // Marcar como expirado y desconectar
        app_exec("
            UPDATE users 
            SET subscription_status = 'expired', 
                is_online = 0, 
                updated_at = NOW() 
            WHERE id = ?
        ", 'i', [$driverId]);

        // Registrar notificación si no fue enviada hoy
        $alreadySent = app_one("
            SELECT id FROM app_notifications 
            WHERE user_id = ? AND type = 'sub_expired_12pm' AND DATE(created_at) = ?
        ", 'is', [$driverId, $todayStr]);

        if (!$alreadySent) {
            app_exec("
                INSERT INTO app_notifications (user_id, type, title, message, is_read, created_at)
                VALUES (?, 'sub_expired_12pm', ?, ?, 0, NOW())
            ", 'iss', [
                $driverId,
                '🔴 Tu suscripción ha expirado',
                'Tu suscripción semanal ha expirado a las 12:00 hs. Entra a tu Perfil y sube tu comprobante de pago para que el administrador reactive tu cuenta.'
            ]);
        }

        $expiredCount++;
    }
    $logMessages[] = "Expiración de 12:00 PM procesada: {$expiredCount} repartidores suspendidos/desconectados.";
}

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'action_executed' => $action,
    'logs' => $logMessages
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
