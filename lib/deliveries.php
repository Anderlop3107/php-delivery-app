<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function update_delivery_status(int $deliveryId, string $status, int $userId, ?string $notes = null): bool
{
    // 1. UPDATE principal — esto es lo crítico; si falla, retornar false.
    try {
        app_exec(
            "UPDATE deliveries SET status = ?, updated_at = NOW() WHERE id = ?",
            'si',
            [$status, $deliveryId]
        );
    } catch (Throwable $e) {
        error_log("Error updating delivery status: " . $e->getMessage());
        return false;
    }

    // 2. Log de auditoría — secundario; si falla NO se bloquea el éxito.
    try {
        app_exec(
            "INSERT INTO delivery_logs (delivery_id, status, user_id, notes) VALUES (?, ?, ?, ?)",
            'isis',
            [$deliveryId, $status, $userId, $notes]
        );
    } catch (Throwable $e) {
        error_log("Warning: delivery_log insert failed (non-blocking): " . $e->getMessage());
    }

    return true;
}
