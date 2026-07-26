<?php
/**
 * Cron Job Unificado de Suscripciones (Repartidores & Comercios)
 * 
 * Reglas de Negocio:
 * 
 * 🛵 REPARTIDORES (Ciclo Semanal - Todos los Lunes):
 * - 10:00 hs: Notificación Push / Alerta de aviso "Tu suscripción vence hoy a las 12:00 hs".
 * - 12:00 hs: Expiración automática (subscription_status = 'expired'), desconexión (is_online = 0) y notificación Push.
 * 
 * 🏬 COMERCIOS (Ciclo Mensual - El 01 de cada mes):
 * - Día 01 a las 10:00 hs: Notificación Push / Alerta de aviso "Tu suscripción mensual vence hoy a las 12:00 hs".
 * - Día 01 a las 12:00 hs: Expiración automática (subscription_status = 'expired') y notificación Push.
 */

require_once __DIR__ . '/bootstrap.php';

$action = $argv[1] ?? $_GET['action'] ?? 'auto_check';

header('Content-Type: application/json; charset=utf-8');

$now = new DateTime();
$todayStr = $now->format('Y-m-d');
$isMonday = (int)$now->format('N') === 1;
$dayOfMonth = (int)$now->format('j');
$currentHour = (int)$now->format('H');

$logMessages = [];

// ==========================================
// 1. PROCESAR REPARTIDORES (SEMANAL - LUNES)
// ==========================================

// A) Aviso 10:00 AM Lunes
if ($action === 'driver_10am' || ($action === 'auto_check' && $isMonday && $currentHour >= 10 && $currentHour < 12)) {
    $drivers = app_all("SELECT id, name, email FROM users WHERE role = 'repartidor'");
    $notifiedDrivers = 0;

    foreach ($drivers as $d) {
        $driverId = (int)$d['id'];
        $alreadySent = app_one("
            SELECT id FROM app_notifications 
            WHERE user_id = ? AND type = 'sub_warning_driver_10am' AND DATE(created_at) = ?
        ", 'is', [$driverId, $todayStr]);

        if (!$alreadySent) {
            app_exec("
                INSERT INTO app_notifications (user_id, type, title, message, is_read, created_at)
                VALUES (?, 'sub_warning_driver_10am', ?, ?, 0, NOW())
            ", 'iss', [
                $driverId,
                '⚠️ Tu suscripción vence en 2 horas (12:00 hs)',
                'Tu suscripción semanal vence hoy a las 12:00 hs. Sube tu comprobante de pago desde tu perfil para continuar recibiendo pedidos.'
            ]);
            $notifiedDrivers++;
        }
    }
    $logMessages[] = "Repartidores (10:00 AM): {$notifiedDrivers} notificaciones de aviso enviadas.";
}

// B) Expiración 12:00 PM Lunes
if ($action === 'driver_12pm' || ($action === 'auto_check' && (($isMonday && $currentHour >= 12) || true))) {
    $expiredDrivers = app_all("
        SELECT id, name, email 
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
        app_exec("UPDATE users SET subscription_status = 'expired', is_online = 0, updated_at = NOW() WHERE id = ?", 'i', [$driverId]);

        $alreadySent = app_one("
            SELECT id FROM app_notifications 
            WHERE user_id = ? AND type = 'sub_expired_driver_12pm' AND DATE(created_at) = ?
        ", 'is', [$driverId, $todayStr]);

        if (!$alreadySent) {
            app_exec("
                INSERT INTO app_notifications (user_id, type, title, message, is_read, created_at)
                VALUES (?, 'sub_expired_driver_12pm', ?, ?, 0, NOW())
            ", 'iss', [
                $driverId,
                '🔴 Tu suscripción ha expirado',
                'Tu suscripción semanal ha expirado a las 12:00 hs. Entra a tu Perfil y sube tu comprobante de pago para que el administrador reactive tu cuenta.'
            ]);
        }
        $expiredCount++;
    }
    $logMessages[] = "Repartidores (12:00 PM): {$expiredCount} cuentas marcadas como expiradas/desconectadas.";
}

// ==========================================
// 2. PROCESAR COMERCIOS (MENSUAL - DÍA 01)
// ==========================================

// A) Aviso 10:00 AM Día 01
if ($action === 'store_10am' || ($action === 'auto_check' && $dayOfMonth === 1 && $currentHour >= 10 && $currentHour < 12)) {
    $stores = app_all("SELECT id, name, business_name, email FROM users WHERE role = 'local'");
    $notifiedStores = 0;

    foreach ($stores as $s) {
        $storeId = (int)$s['id'];
        $alreadySent = app_one("
            SELECT id FROM app_notifications 
            WHERE user_id = ? AND type = 'sub_warning_store_10am' AND DATE(created_at) = ?
        ", 'is', [$storeId, $todayStr]);

        if (!$alreadySent) {
            app_exec("
                INSERT INTO app_notifications (user_id, type, title, message, is_read, created_at)
                VALUES (?, 'sub_warning_store_10am', ?, ?, 0, NOW())
            ", 'iss', [
                $storeId,
                '⚠️ Tu suscripción de comercio vence hoy a las 12:00 hs',
                'Tu suscripción mensual de comercio vence hoy a las 12:00 hs. Por favor sube tu comprobante de pago desde tu perfil para continuar realizando envíos.'
            ]);
            $notifiedStores++;
        }
    }
    $logMessages[] = "Comercios (Día 01 - 10:00 AM): {$notifiedStores} notificaciones de aviso enviadas.";
}

// B) Expiración 12:00 PM Día 01
if ($action === 'store_12pm' || ($action === 'auto_check' && (($dayOfMonth === 1 && $currentHour >= 12) || true))) {
    $expiredStores = app_all("
        SELECT id, name, business_name, email 
        FROM users 
        WHERE role = 'local' 
          AND (
              subscription_expires_at IS NULL 
              OR subscription_expires_at <= NOW()
              OR subscription_status != 'active'
          )
    ");

    $expiredCount = 0;
    foreach ($expiredStores as $s) {
        $storeId = (int)$s['id'];
        app_exec("UPDATE users SET subscription_status = 'expired', updated_at = NOW() WHERE id = ?", 'i', [$storeId]);

        $alreadySent = app_one("
            SELECT id FROM app_notifications 
            WHERE user_id = ? AND type = 'sub_expired_store_12pm' AND DATE(created_at) = ?
        ", 'is', [$storeId, $todayStr]);

        if (!$alreadySent) {
            app_exec("
                INSERT INTO app_notifications (user_id, type, title, message, is_read, created_at)
                VALUES (?, 'sub_expired_store_12pm', ?, ?, 0, NOW())
            ", 'iss', [
                $storeId,
                '🔴 Suscripción de Comercio Expirada',
                'La suscripción mensual de tu comercio ha expirado a las 12:00 hs. Entra a tu Perfil y sube tu comprobante de pago para que el administrador reactive tu cuenta.'
            ]);
        }
        $expiredCount++;
    }
    $logMessages[] = "Comercios (Día 01 - 12:00 PM): {$expiredCount} comercios suspendidos por expiración.";
}

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'action_executed' => $action,
    'logs' => $logMessages
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
