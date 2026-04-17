<?php
require_once 'config.php';

// Validar token
$user = validateToken();
$method = $_SERVER['REQUEST_METHOD'];

// Verificar permisos
if ($user->rol !== 'admin' && $user->rol !== 'manager') {
    jsonResponse(["error" => "No autorizado"], 403);
}

switch ($method) {
    case 'GET':
        handleGet($user);
        break;
    case 'POST':
        handlePost($user);
        break;
    case 'PUT':
        handlePut($user);
        break;
    case 'DELETE':
        handleDelete($user);
        break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Método no permitido"]);
        break;
}

function handleGet($user) {
    $conn = getConnection();
    
    $menuId = isset($_GET['menu_id']) ? intval($_GET['menu_id']) : null;
    $categoryId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if ($categoryId) {
        // Obtener categoría específica
        $stmt = $conn->prepare("
            SELECT c.*, m.nombre as menu_nombre
            FROM categorias_menu c
            JOIN menus m ON c.menu_id = m.id
            WHERE c.id = ? AND m.restaurante_id = ?
        ");
        $stmt->bind_param("ii", $categoryId, $user->restaurante_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            jsonResponse(["error" => "Categoría no encontrada"], 404);
        }
        
        $category = $result->fetch_assoc();
        
        // Obtener ítems de la categoría
        $stmt = $conn->prepare("
            SELECT COUNT(*) as num_items 
            FROM items_menu 
            WHERE categoria_id = ? AND disponible = 1
        ");
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $itemsCount = $stmt->get_result()->fetch_assoc();
        $category['num_items'] = $itemsCount['num_items'];
        
        jsonResponse($category);
    } else {
        // Obtener lista de categorías
        $sql = "
            SELECT c.*, m.nombre as menu_nombre,
                   (SELECT COUNT(*) FROM items_menu i WHERE i.categoria_id = c.id AND i.disponible = 1) as num_items
            FROM categorias_menu c
            JOIN menus m ON c.menu_id = m.id
            WHERE m.restaurante_id = ?
        ";
        
        $params = [];
        $types = "i";
        $params[] = $user->restaurante_id;
        
        if ($menuId) {
            $sql .= " AND c.menu_id = ?";
            $types .= "i";
            $params[] = $menuId;
        }
        
        $sql .= " ORDER BY c.orden, c.nombre";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        jsonResponse($categories);
    }
}

function handlePost($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['nombre']) || !isset($data['menu_id'])) {
        jsonResponse(["error" => "Datos incompletos"], 400);
    }
    
    $conn = getConnection();
    
    // Verificar que el menú pertenezca al restaurante
    $stmt = $conn->prepare("SELECT id FROM menus WHERE id = ? AND restaurante_id = ?");
    $stmt->bind_param("ii", $data['menu_id'], $user->restaurante_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Menú no válido"], 400);
    }
    
    // Insertar categoría
    $nombre = sanitizeInput($data['nombre']);
    $descripcion = isset($data['descripcion']) ? sanitizeInput($data['descripcion']) : null;
    $orden = isset($data['orden']) ? intval($data['orden']) : 0;
    $activo = isset($data['activo']) ? (int)$data['activo'] : 1;
    
    $stmt = $conn->prepare("
        INSERT INTO categorias_menu (menu_id, nombre, descripcion, orden, activo)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issii", $data['menu_id'], $nombre, $descripcion, $orden, $activo);
    
    if ($stmt->execute()) {
        $categoryId = $conn->insert_id;
        registrarCambio($conn, 'categorias_menu', $categoryId, $user->id, 'creacion', "Categoría creada");
        jsonResponse(["success" => true, "message" => "Categoría creada exitosamente", "categoria_id" => $categoryId], 201);
    } else {
        jsonResponse(["error" => "Error al crear la categoría"], 500);
    }
}

function handlePut($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['id'])) {
        jsonResponse(["error" => "ID de la categoría requerido"], 400);
    }
    
    $conn = getConnection();
    $categoryId = intval($data['id']);
    
    // Verificar que la categoría pertenezca al restaurante
    $stmt = $conn->prepare("
        SELECT c.id 
        FROM categorias_menu c
        JOIN menus m ON c.menu_id = m.id
        WHERE c.id = ? AND m.restaurante_id = ?
    ");
    $stmt->bind_param("ii", $categoryId, $user->restaurante_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Categoría no encontrada"], 404);
    }
    
    // Construir consulta de actualización
    $updates = [];
    $params = [];
    $types = "";
    
    $fields = ['nombre' => 's', 'descripcion' => 's', 'orden' => 'i', 'activo' => 'i', 'menu_id' => 'i'];
    
    foreach ($fields as $field => $type) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
            $types .= $type;
        }
    }
    
    if (empty($updates)) {
        jsonResponse(["error" => "No hay datos para actualizar"], 400);
    }
    
    // Verificar nuevo menú si se cambia
    if (isset($data['menu_id'])) {
        $stmt = $conn->prepare("SELECT id FROM menus WHERE id = ? AND restaurante_id = ?");
        $stmt->bind_param("ii", $data['menu_id'], $user->restaurante_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            jsonResponse(["error" => "Menú no válido"], 400);
        }
    }
    
    $types .= 'i';
    $params[] = $categoryId;
    
    $sql = "UPDATE categorias_menu SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        registrarCambio($conn, 'categorias_menu', $categoryId, $user->id, 'actualizacion', "Categoría actualizada");
        jsonResponse(["success" => true, "message" => "Categoría actualizada exitosamente"]);
    } else {
        jsonResponse(["error" => "Error al actualizar la categoría"], 500);
    }
}

function handleDelete($user) {
    $categoryId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if (!$categoryId) {
        jsonResponse(["error" => "ID de la categoría requerido"], 400);
    }
    
    $conn = getConnection();
    
    // Verificar que la categoría pertenezca al restaurante
    $stmt = $conn->prepare("
        SELECT c.id 
        FROM categorias_menu c
        JOIN menus m ON c.menu_id = m.id
        WHERE c.id = ? AND m.restaurante_id = ?
    ");
    $stmt->bind_param("ii", $categoryId, $user->restaurante_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Categoría no encontrada"], 404);
    }
    
    // Verificar si la categoría tiene ítems
    $stmt = $conn->prepare("SELECT COUNT(*) as num_items FROM items_menu WHERE categoria_id = ?");
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result['num_items'] > 0) {
        jsonResponse(["error" => "No se puede eliminar una categoría que contiene ítems"], 400);
    }
    
    $stmt = $conn->prepare("DELETE FROM categorias_menu WHERE id = ?");
    $stmt->bind_param("i", $categoryId);
    
    if ($stmt->execute()) {
        registrarCambio($conn, 'categorias_menu', $categoryId, $user->id, 'eliminacion', "Categoría eliminada");
        jsonResponse(["success" => true, "message" => "Categoría eliminada exitosamente"]);
    } else {
        jsonResponse(["error" => "Error al eliminar la categoría"], 500);
    }
}
?>