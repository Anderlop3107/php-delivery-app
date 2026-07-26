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
        'en_puerta' => 'Entregando Pedido',
        'entregado' => '¡Pedido Entregado!',
        'cancelado' => 'Pedido Cancelado',
        'rechazado' => 'Pedido Rechazado',
    ];

    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Calcula la fecha de expiración semanal del repartidor (Próximo Lunes 12:00:00).
 * Si hoy es lunes antes de las 12:00 hs, retorna hoy 12:00:00.
 * Si ya pasaron las 12:00 hs de hoy o es cualquier otro día, retorna el próximo lunes a las 12:00:00.
 */
function get_next_driver_expiration_date(): string
{
    $now = time();
    $todayMondayNoon = strtotime('this Monday 12:00:00');
    
    if (date('N', $now) === '1' && $now < $todayMondayNoon) {
        return date('Y-m-d H:i:s', $todayMondayNoon);
    }
    
    return date('Y-m-d H:i:s', strtotime('next Monday 12:00:00'));
}

/**
 * Calcula la fecha de expiración mensual del comercio (El 01 de cada mes a las 12:00:00).
 * Si hoy es el día 1 del mes antes de las 12:00 hs, retorna hoy 12:00:00.
 * Si ya pasaron las 12:00 hs del día 1 o es cualquier otro día, retorna el 01 del próximo mes a las 12:00:00.
 */
function get_next_store_expiration_date(): string
{
    $now = time();
    $todayFirstNoon = strtotime('first day of this month 12:00:00');
    
    if (date('j', $now) === '1' && $now < $todayFirstNoon) {
        return date('Y-m-d H:i:s', $todayFirstNoon);
    }
    
    return date('Y-m-d H:i:s', strtotime('first day of next month 12:00:00'));
}
