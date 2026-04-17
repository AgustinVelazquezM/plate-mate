<?php
require_once 'config.php';

header('Content-Type: application/json');

$type = isset($_GET['type']) ? $_GET['type'] : 'dashboard';

if ($type === 'dashboard') {
    $conn = getConnection();
    
    // Obtener usuario autenticado
    $headers = getallheaders();
    $auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    $token = str_replace('Bearer ', '', $auth);
    
    $restaurantId = 1;
    if (!empty($token)) {
        try {
            $decoded = json_decode(base64_decode($token), true);
            if (isset($decoded['restaurante_id'])) {
                $restaurantId = $decoded['restaurante_id'];
            }
        } catch (Exception $e) {
            // Usar demo
        }
    }
    
    // Obtener pedidos de hoy
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as revenue 
        FROM pedidos 
        WHERE restaurante_id = ? AND DATE(fecha_pedido) = ? AND estado != 'cancelado'
    ");
    $stmt->bind_param("is", $restaurantId, $today);
    $stmt->execute();
    $todayData = $stmt->get_result()->fetch_assoc();
    
    // Obtener pedidos de ayer para la tendencia
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as revenue 
        FROM pedidos 
        WHERE restaurante_id = ? AND DATE(fecha_pedido) = ? AND estado != 'cancelado'
    ");
    $stmt->bind_param("is", $restaurantId, $yesterday);
    $stmt->execute();
    $yesterdayData = $stmt->get_result()->fetch_assoc();
    
    // Calcular tendencias
    $ordersTrend = 0;
    if ($yesterdayData['total'] > 0) {
        $ordersTrend = (($todayData['total'] - $yesterdayData['total']) / $yesterdayData['total']) * 100;
    }
    
    $revenueTrend = 0;
    if ($yesterdayData['revenue'] > 0) {
        $revenueTrend = (($todayData['revenue'] - $yesterdayData['revenue']) / $yesterdayData['revenue']) * 100;
    }
    
    // Obtener ítems activos
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total FROM items_menu i
        JOIN categorias_menu c ON i.categoria_id = c.id
        JOIN menus m ON c.menu_id = m.id
        WHERE m.restaurante_id = ? AND i.disponible = 1
    ");
    $stmt->bind_param("i", $restaurantId);
    $stmt->execute();
    $activeItems = $stmt->get_result()->fetch_assoc();
    
    // Obtener pedidos pendientes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total FROM pedidos 
        WHERE restaurante_id = ? AND estado IN ('pendiente', 'confirmado', 'en_preparacion')
    ");
    $stmt->bind_param("i", $restaurantId);
    $stmt->execute();
    $pendingOrders = $stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'todayOrders' => (int)$todayData['total'],
        'todayOrdersTrend' => round($ordersTrend, 1),
        'todayRevenue' => (float)$todayData['revenue'],
        'todayRevenueTrend' => round($revenueTrend, 1),
        'activeItems' => (int)$activeItems['total'],
        'pendingOrders' => (int)$pendingOrders['total']
    ]);
    
} elseif ($type === 'popular') {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $conn = getConnection();
    
    $headers = getallheaders();
    $auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    $token = str_replace('Bearer ', '', $auth);
    
    $restaurantId = 1;
    if (!empty($token)) {
        try {
            $decoded = json_decode(base64_decode($token), true);
            if (isset($decoded['restaurante_id'])) {
                $restaurantId = $decoded['restaurante_id'];
            }
        } catch (Exception $e) {
            // Usar demo
        }
    }
    
    $stmt = $conn->prepare("
        SELECT 
            i.id,
            i.nombre,
            c.nombre as categoria,
            COALESCE(SUM(dp.cantidad), 0) as ventas,
            COALESCE(SUM(dp.subtotal), 0) as ingresos
        FROM items_menu i
        JOIN categorias_menu c ON i.categoria_id = c.id
        JOIN menus m ON c.menu_id = m.id
        LEFT JOIN detalles_pedido dp ON i.id = dp.item_id
        LEFT JOIN pedidos p ON dp.pedido_id = p.id
        WHERE m.restaurante_id = ? 
            AND (p.estado IS NULL OR p.estado NOT IN ('cancelado'))
        GROUP BY i.id
        ORDER BY ventas DESC, ingresos DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $restaurantId, $limit);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode($items);
    
} else {
    echo json_encode([]);
}
?>