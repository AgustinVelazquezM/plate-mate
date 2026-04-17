<?php
require_once 'config.php';

// Validar token
$user = validateToken();
$method = $_SERVER['REQUEST_METHOD'];

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
    
    $menuId = isset($_GET['id']) ? intval($_GET['id']) : null;
    $activeOnly = isset($_GET['active']) ? filter_var($_GET['active'], FILTER_VALIDATE_BOOLEAN) : false;
    
    if ($menuId) {
        $stmt = $conn->prepare("
            SELECT m.*,
                   (SELECT COUNT(*) FROM categorias_menu c WHERE c.menu_id = m.id) as num_categorias,
                   (SELECT COUNT(*) FROM categorias_menu c JOIN items_menu i ON c.id = i.categoria_id WHERE c.menu_id = m.id) as num_items
            FROM menus m
            WHERE m.id = ? AND m.restaurante_id = ?
        ");
        $stmt->bind_param("ii", $menuId, $user->restaurante_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            jsonResponse(["error" => "Menú no encontrado"], 404);
        }
        
        jsonResponse($result->fetch_assoc());
    } else {
        $sql = "
            SELECT m.*,
                   (SELECT COUNT(*) FROM categorias_menu c WHERE c.menu_id = m.id) as num_categorias,
                   (SELECT COUNT(*) FROM categorias_menu c JOIN items_menu i ON c.id = i.categoria_id WHERE c.menu_id = m.id) as num_items
            FROM menus m
            WHERE m.restaurante_id = ?
        ";
        $params = [$user->restaurante_id];
        $types = "i";
        
        if ($activeOnly) {
            $sql .= " AND m.activo = 1";
        }
        
        $sql .= " ORDER BY m.nombre";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $menus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        jsonResponse($menus);
    }
}

function handlePost($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['nombre'])) {
        jsonResponse(["error" => "El nombre del menú es requerido"], 400);
    }
    
    $conn = getConnection();
    
    $tiposPermitidos = ['regular', 'temporal', 'especial'];
    $tipo = isset($data['tipo']) && in_array($data['tipo'], $tiposPermitidos) ? $data['tipo'] : 'regular';
    
    $nombre = sanitizeInput($data['nombre']);
    $descripcion = isset($data['descripcion']) ? sanitizeInput($data['descripcion']) : null;
    $activo = isset($data['activo']) ? (int)$data['activo'] : 1;
    $fechaInicio = isset($data['fecha_inicio']) ? sanitizeInput($data['fecha_inicio']) : null;
    $fechaFin = isset($data['fecha_fin']) ? sanitizeInput($data['fecha_fin']) : null;
    
    $stmt = $conn->prepare("
        INSERT INTO menus (restaurante_id, nombre, descripcion, tipo, activo, fecha_inicio, fecha_fin)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssiss", $user->restaurante_id, $nombre, $descripcion, $tipo, $activo, $fechaInicio, $fechaFin);
    
    if ($stmt->execute()) {
        $menuId = $conn->insert_id;
        registrarCambio($conn, 'menus', $menuId, $user->id, 'creacion', "Menú creado: $nombre");
        jsonResponse(["success" => true, "message" => "Menú creado exitosamente", "menu_id" => $menuId], 201);
    } else {
        jsonResponse(["error" => "Error al crear el menú: " . $conn->error], 500);
    }
}

function handlePut($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['id'])) {
        jsonResponse(["error" => "ID del menú requerido"], 400);
    }
    
    $conn = getConnection();
    $menuId = intval($data['id']);
    
    $stmt = $conn->prepare("SELECT id FROM menus WHERE id = ? AND restaurante_id = ?");
    $stmt->bind_param("ii", $menuId, $user->restaurante_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Menú no encontrado"], 404);
    }
    
    $updates = [];
    $params = [];
    $types = "";
    
    $fields = ['nombre' => 's', 'descripcion' => 's', 'tipo' => 's', 'activo' => 'i', 'fecha_inicio' => 's', 'fecha_fin' => 's'];
    
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
    
    if (isset($data['tipo'])) {
        $tiposPermitidos = ['regular', 'temporal', 'especial'];
        if (!in_array($data['tipo'], $tiposPermitidos)) {
            jsonResponse(["error" => "Tipo de menú no válido"], 400);
        }
    }
    
    $types .= "ii";
    $params[] = $menuId;
    $params[] = $user->restaurante_id;
    
    $sql = "UPDATE menus SET " . implode(', ', $updates) . " WHERE id = ? AND restaurante_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        registrarCambio($conn, 'menus', $menuId, $user->id, 'actualizacion', "Menú actualizado");
        jsonResponse(["success" => true, "message" => "Menú actualizado exitosamente"]);
    } else {
        jsonResponse(["error" => "Error al actualizar el menú"], 500);
    }
}

function handleDelete($user) {
    $menuId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if (!$menuId) {
        jsonResponse(["error" => "ID del menú requerido"], 400);
    }
    
    $conn = getConnection();
    
    $stmt = $conn->prepare("SELECT id, nombre FROM menus WHERE id = ? AND restaurante_id = ?");
    $stmt->bind_param("ii", $menuId, $user->restaurante_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        jsonResponse(["error" => "Menú no encontrado"], 404);
    }
    $menu = $result->fetch_assoc();
    
    // Soft delete
    $stmt = $conn->prepare("UPDATE menus SET activo = 0 WHERE id = ?");
    $stmt->bind_param("i", $menuId);
    
    if ($stmt->execute()) {
        registrarCambio($conn, 'menus', $menuId, $user->id, 'eliminacion', "Menú desactivado: " . $menu['nombre']);
        jsonResponse(["success" => true, "message" => "Menú desactivado exitosamente"]);
    } else {
        jsonResponse(["error" => "Error al desactivar el menú"], 500);
    }
}
?>