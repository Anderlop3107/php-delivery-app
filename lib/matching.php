<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Calcula la distancia en kilómetros entre dos coordenadas usando la fórmula de Haversine.
 */
function calcular_distancia_haversine(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): float
{
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
        return 99999.0;
    }
    
    $earth_radius = 6371.0; // Radio de la Tierra en kilómetros

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
         
    $c = 2 * asin(sqrt($a));
    return $earth_radius * $c;
}

/**
 * Obtiene los pedidos disponibles para un repartidor según su ubicación y su estado actual.
 */
function obtener_pedidos_disponibles_para_repartidor(int $repartidorId): array
{
    // 1. Obtener datos del repartidor (ubicación y conexión activa)
    // Se valida si el repartidor está activo (GPS reportado en los últimos 60 segundos)
    $driver = app_one("
        SELECT id, latitude, longitude, ubicacion_actualizada_en 
        FROM users 
        WHERE id = ? 
          AND role = 'repartidor'
          AND ubicacion_actualizada_en >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
    ", "i", [$repartidorId]);
    
    if (!$driver) {
        return [];
    }

    // 2. Obtener los pedidos activos asignados al repartidor (que no estén entregados ni cancelados)
    $activeDeliveries = app_all("
        SELECT d.*, COALESCE(d.pickup_latitude, u.latitude) as local_lat, COALESCE(d.pickup_longitude, u.longitude) as local_lng 
        FROM deliveries d
        JOIN users u ON d.local_user_id = u.id
        WHERE d.repartidor_user_id = ? AND d.status IN ('aceptado', 'repartidor_en_local', 'en_camino_al_cliente')
    ", "i", [$repartidorId]);

    // Limite de Co-batching / Pooling: Máximo 2 pedidos simultáneos
    if (count($activeDeliveries) >= 2) {
        return [];
    }

    // 3. Determinar el estado actual del repartidor (LIBRE, ASIGNADO, ENTREGANDO)
    $driverState = 'LIBRE';
    $activeDelivery = null;

    if (!empty($activeDeliveries)) {
        $activeDelivery = $activeDeliveries[0];
        foreach ($activeDeliveries as $del) {
            if ($del['status'] === 'en_camino_al_cliente') {
                $driverState = 'ENTREGANDO';
                $activeDelivery = $del; // Priorizar el viaje al cliente
                break;
            }
        }
        
        if ($driverState !== 'ENTREGANDO') {
            $driverState = 'ASIGNADO';
        }
    }

    // 4. Traer todos los pedidos pendientes disponibles
    // Se filtran los que no tienen dueño y que no estén reservados por otro conductor activo
    $pendingDeliveries = app_all("
        SELECT d.*, u.business_name as local_name, u.address as local_address, u.logo_path as local_logo, 
               COALESCE(d.pickup_latitude, u.latitude) as local_lat, COALESCE(d.pickup_longitude, u.longitude) as local_lng
        FROM deliveries d
        JOIN users u ON d.local_user_id = u.id
        WHERE d.status = 'pendiente' 
          AND d.repartidor_user_id IS NULL
          AND (d.reservado_para_repartidor_id IS NULL OR d.reservado_para_repartidor_id = ? OR d.reserva_expira_en < NOW())
        ORDER BY d.created_at DESC
    ", "i", [$repartidorId]);

    $matchedDeliveries = [];

    // 5. Aplicar las reglas específicas para cada Estado del conductor
    foreach ($pendingDeliveries as $order) {
        $distDriverToLocal = calcular_distancia_haversine(
            (float)$driver['latitude'], (float)$driver['longitude'],
            (float)$order['local_lat'], (float)$order['local_lng']
        );

        if ($driverState === 'LIBRE') {
            // Estado 3: Repartidor libre -> ve todos los pedidos
            $order['distancia_repartidor'] = $distDriverToLocal;
            $matchedDeliveries[] = $order;
        } 
        elseif ($driverState === 'ASIGNADO') {
            // Estado 1: Repartidor asignado (en camino al local o esperando)
            // Doble envío permitido si el local candidato está a <= 4 km de A o del repartidor
            $distLocalAToLocalB = calcular_distancia_haversine(
                (float)$activeDelivery['local_lat'], (float)$activeDelivery['local_lng'],
                (float)$order['local_lat'], (float)$order['local_lng']
            );

            if ($distLocalAToLocalB <= 4.0 || $distDriverToLocal <= 4.0) {
                // Mejora A: Distancia entre los dos clientes <= 5 km
                $distClientAToClientB = calcular_distancia_haversine(
                    (float)$activeDelivery['delivery_latitude'], (float)$activeDelivery['delivery_longitude'],
                    (float)$order['delivery_latitude'], (float)$order['delivery_longitude']
                );

                if ($distClientAToClientB <= 5.0) {
                    $order['distancia_repartidor'] = $distDriverToLocal;
                    $matchedDeliveries[] = $order;
                }
            }
        } 
        elseif ($driverState === 'ENTREGANDO') {
            // Estado 2: Repartidor entregando (pedido a bordo)
            // Notificación anticipada si está a <= 1 km del cliente actual
            $distDriverToClientA = calcular_distancia_haversine(
                (float)$driver['latitude'], (float)$driver['longitude'],
                (float)$activeDelivery['delivery_latitude'], (float)$activeDelivery['delivery_longitude']
            );

            if ($distDriverToClientA <= 1.0) {
                // Nuevo local a una distancia de <= 4.0 km de su posición actual
                if ($distDriverToLocal <= 4.0) {
                    $order['distancia_repartidor'] = $distDriverToLocal;
                    $matchedDeliveries[] = $order;
                }
            }
        }
    }

    // 6. Ordenar por cercanía si el repartidor está libre
    if ($driverState === 'LIBRE') {
        usort($matchedDeliveries, function (array $a, array $b): int {
            return $a['distancia_repartidor'] <=> $b['distancia_repartidor'];
        });
    }

    return $matchedDeliveries;
}
