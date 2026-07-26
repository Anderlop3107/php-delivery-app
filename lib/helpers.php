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
 * Regla Justa de Expiración Semanal para Repartidores:
 * - Si se inscribe Lunes, Martes o Miércoles (días 1, 2, 3): Expira el Próximo Lunes a las 12:00 hs.
 * - Si se inscribe Jueves, Viernes, Sábado o Domingo (días 4, 5, 6, 7): Expira el Lunes Subsecuente (+1 semana justa).
 */
function get_next_driver_expiration_date(?DateTimeInterface $from = null): string
{
    $now = $from ? DateTime::createFromInterface($from) : new DateTime();
    $dow = (int)$now->format('N');
    
    if ($dow <= 3) {
        $nextMonday = new DateTime('next Monday 12:00:00');
        return $nextMonday->format('Y-m-d H:i:s');
    } else {
        $twoMondaysAhead = (new DateTime('next Monday 12:00:00'))->modify('+1 week');
        return $twoMondaysAhead->format('Y-m-d H:i:s');
    }
}

/**
 * Regla Justa de Expiración Mensual para Comercios:
 * - Si se inscribe del día 01 al 20 del mes: Expira el 01 del Siguiente Mes a las 12:00 hs.
 * - Si se inscribe del día 21 al último día del mes: Expira el 01 del Mes Subsecuente (Mes + 2) a las 12:00 hs.
 */
function get_next_store_expiration_date(?DateTimeInterface $from = null): string
{
    $now = $from ? DateTime::createFromInterface($from) : new DateTime();
    $day = (int)$now->format('j');
    
    if ($day <= 20) {
        $nextMonth = new DateTime('first day of next month 12:00:00');
        return $nextMonth->format('Y-m-d H:i:s');
    } else {
        $twoMonthsAhead = (new DateTime('first day of next month 12:00:00'))->modify('+1 month');
        return $twoMonthsAhead->format('Y-m-d H:i:s');
    }
}
