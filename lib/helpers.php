<?php

declare(strict_types=1);

function esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gs(float|int|string|null $amount): string
{
    return 'Gs. ' . number_format((float) ($amount ?? 0), 0, ',', '.');
}

function dt(?string $value): string
{
    if (!$value) {
        return 'N/A';
    }
    try {
        return (new DateTime($value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return 'N/A';
    }
}

function delivery_status_text(string $status): string
{
    $map = [
        'pendiente' => 'Esperando Repartidor...',
        'aceptado' => 'Repartidor Asignado',
        'en_camino_al_local' => 'En camino al local',
        'repartidor_en_local' => '¡Repartidor en tu local!',
        'en_camino_al_cliente' => 'Pedido en camino al cliente',
        'entregado' => '¡Pedido Entregado!',
        'cancelado' => 'Pedido Cancelado',
        'rechazado' => 'Pedido Rechazado',
    ];

    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}
