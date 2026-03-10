<?php
/**
 * Script de backfill: Poblar el campo 'destino' en la tabla viajes
 * para todas las reservas existentes usando raw_data de WooCommerce.
 * 
 * Ejecutar UNA VEZ desde terminal o navegador:
 *   php api/backfill_destino.php
 *   O visitar: https://tu-sitio.com/api/backfill_destino.php
 * 
 * Este script es seguro de ejecutar múltiples veces:
 * actualiza viajes donde destino está vacío/NULL/'Transfer' y limpia contaminación legacy.
 */

require_once '../config/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Backfill de campo 'destino' en tabla viajes ===\n\n";

// Obtener viajes sin destino real (vacío, NULL o Transfer)
$stmt = $pdo->query("
    SELECT v.id, v.reserva_id, v.item_index, v.hotel, v.destino
    FROM viajes v
    WHERE v.destino IS NULL OR TRIM(v.destino) = '' OR LOWER(TRIM(v.destino)) = 'transfer'
    ORDER BY v.reserva_id, v.item_index
");
$viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Viajes sin destino: " . count($viajes) . "\n\n";

if (count($viajes) === 0) {
    echo "¡Todos los viajes ya tienen destino! Nada que hacer.\n";
    exit;
}

// Agrupar por reserva_id para no leer raw_data múltiples veces
$viajesPorReserva = [];
foreach ($viajes as $v) {
    $viajesPorReserva[$v['reserva_id']][] = $v;
}

$updated = 0;
$skipped = 0;
$errors = 0;

$updateStmt = $pdo->prepare("UPDATE viajes SET destino = ? WHERE id = ?");
$clearHotelStmt = $pdo->prepare("UPDATE viajes SET hotel = '' WHERE id = ?");

foreach ($viajesPorReserva as $reservaId => $viajesDeReserva) {
    // Obtener raw_data de la reserva
    $rawStmt = $pdo->prepare("SELECT raw_data FROM reservas WHERE id = ?");
    $rawStmt->execute([$reservaId]);
    $rawData = $rawStmt->fetchColumn();

    if (!$rawData) {
        echo "  [SKIP] Reserva #$reservaId: sin raw_data\n";
        $skipped += count($viajesDeReserva);
        continue;
    }

    $order = json_decode($rawData, true);
    if (!$order || !isset($order['line_items'])) {
        echo "  [SKIP] Reserva #$reservaId: raw_data inválido o sin line_items\n";
        $skipped += count($viajesDeReserva);
        continue;
    }

    $lineItems = $order['line_items'];

    foreach ($viajesDeReserva as $viaje) {
        $itemIndex = $viaje['item_index'];
        // Para roundtrip departures, el item_index real es item_index - 1000
        $originalIndex = $itemIndex >= 1000 ? $itemIndex - 1000 : $itemIndex;

        $candidateIndexes = [$originalIndex];
        if ($originalIndex > 0) {
            $candidateIndexes[] = $originalIndex - 1;
        }
        $candidateIndexes[] = 0;
        $candidateIndexes = array_values(array_unique($candidateIndexes));

        $productName = null;
        foreach ($candidateIndexes as $candidateIdx) {
            if (!isset($lineItems[$candidateIdx])) {
                continue;
            }
            $candidateName = trim((string) ($lineItems[$candidateIdx]['name'] ?? ''));
            if ($candidateName !== '' && strtolower($candidateName) !== 'transfer') {
                $productName = $candidateName;
                break;
            }
        }

        $productName = trim((string) $productName);
        $hotelLegacy = trim((string) ($viaje['hotel'] ?? ''));

        // Si raw_data no trae nombre útil, usar hotel legacy como rescate
        if ($productName === '' || strtolower($productName) === 'transfer') {
            if ($hotelLegacy !== '' && strtolower($hotelLegacy) !== 'transfer') {
                $productName = $hotelLegacy;
            }
        }

        if ($productName !== '' && strtolower($productName) !== 'transfer') {
            try {
                $updateStmt->execute([$productName, $viaje['id']]);
                $updated++;
                echo "  [OK] Viaje #{$viaje['id']} (Reserva #$reservaId, idx $itemIndex): '$productName'\n";

                // Si hotel era igual al destino (contaminación legacy), limpiarlo
                if ($hotelLegacy !== '' && strcasecmp($hotelLegacy, $productName) === 0) {
                    $clearHotelStmt->execute([$viaje['id']]);
                    echo "  [CLEAN] Viaje #{$viaje['id']}: hotel legacy limpiado\n";
                }
            } catch (Exception $e) {
                $errors++;
                echo "  [ERROR] Viaje #{$viaje['id']}: " . $e->getMessage() . "\n";
            }
        } else {
            $skipped++;
            echo "  [SKIP] Viaje #{$viaje['id']} (Reserva #$reservaId): sin nombre de producto\n";
        }
    }
}

echo "\n=== Resumen ===\n";
echo "Actualizados: $updated\n";
echo "Omitidos: $skipped\n";
echo "Errores: $errors\n";
echo "\nBackfill completado.\n";
?>
