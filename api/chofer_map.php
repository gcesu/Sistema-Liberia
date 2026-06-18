<?php
/**
 * Endpoint público — mapa link_param → nombre
 * No requiere autenticación (los choferes acceden sin login)
 */
require_once '../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $stmt = $pdo->query("SELECT link_param, nombre FROM choferes WHERE link_param IS NOT NULL AND link_param != '' ORDER BY nombre");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['link_param']] = $r['nombre'];
    }
    echo json_encode($map);
} catch (PDOException $e) {
    echo json_encode([]);
}
?>
