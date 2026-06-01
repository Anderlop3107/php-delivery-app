<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function update_delivery_status(int $deliveryId, string $status, int $userId, ?string $notes = null): bool
{
    try {
        app_exec(
            "UPDATE deliveries SET status = ?, updated_at = NOW() WHERE id = ?",
            'si',
            [$status, $deliveryId]
        );

        app_exec(
            "INSERT INTO delivery_logs (delivery_id, status, user_id, notes) VALUES (?, ?, ?, ?)",
            'isis',
            [$deliveryId, $status, $userId, $notes]
        );

        return true;
    } catch (Throwable $e) {
        error_log("Error updating delivery status: " . $e->getMessage());
        return false;
    }
}
