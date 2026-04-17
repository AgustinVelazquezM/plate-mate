<?php
require_once 'config.php';

$user = validateToken();
$method = $_SERVER['REQUEST_METHOD'];

if ($user->rol !== 'admin' && $user->rol !== 'manager') {
    jsonResponse(["error" => "No autorizado"], 403);
}

switch ($method) {
    case 'GET': handleGet($user); break;
    case 'POST': handlePost($user); break;
    case 'PUT': handlePut($user); break;
    case 'DELETE': handleDelete($user); break;
    default: http_response_code(405); echo json_encode(["error" => "Método no permitido"]); break;
}

function handleGet($user) {
    $conn = getConnection();
    $itemId = isset($_GET['item_id']) ? intval($_GET['item_id']) : null;
    $scheduleId = isset($_GET['id']) ? intval($_GET['id']) : null;

    if ($scheduleId) {
        $stmt = $conn->prepare("
            SELECT h.*, i.nombre as item_nombre
            FROM horarios_item h
            JOIN items_menu i ON h.item_id = i.id
            JOIN categorias_menu c ON i.categoria_id = c.id
            JOIN menus m ON c.menu_id = m.id
            WHERE h.id = ? AND m.restaurante_id = ?
        ");
        $stmt->bind_param("ii", $scheduleId, $user->restaurante_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            jsonResponse(["error" => "Horario no encontrado"], 404);
        }
        jsonResponse($result->fetch_assoc());
    } else {
        $sql = "
            SELECT h.*, i.nombre as item_nombre
            FROM horarios_item h
            JOIN items_menu i ON h.item_id = i.id
            JOIN categorias_menu c ON i.categoria_id = c.id
            JOIN menus m ON c.menu_id = m.id
            WHERE m.restaurante_id = ?
        ";
        $params = [$user->restaurante_id];
        $types = "i";
        
        if ($itemId) {
            $sql .= " AND h.item_id = ?";
            $types .= "i";
            $params[] = $itemId;
        }
        
        $sql .= " ORDER BY FIELD(h.dia_semana, 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'), h.hora_inicio";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $horarios = $result->fetch_all(MYSQLI_ASSOC);
        
        jsonResponse($horarios);
    }
}

function handlePost($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['item_id']) || !isset($data['dia_semana'])) {
        jsonResponse(["error" => "Datos incompletos"], 400);
    }
    
    $conn = getConnection();

    $stmt = $conn->prepare("
        SELECT i.id FROM items_menu i
        JOIN categorias_menu c ON i.categoria_id = c.id
        JOIN menus m ON c.menu_id = m.id
        WHERE i.id = ? AND m.restaurante_id = ?
    ");
    $stmt->bind_param("ii", $data['item_id'], $user->restaurante_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Ítem no válido"], 400);
    }

    $itemId = intval($data['item_id']);
    $diaSemana = sanitizeInput($data['dia_semana']);
    $horaInicio = isset($data['hora_inicio']) ? sanitizeInput($data['hora_inicio']) : null;
    $horaFin = isset($data['hora_fin']) ? sanitizeInput($data['hora_fin']) : null;
    $activo = isset($data['activo']) ? (int)$data['activo'] : 1;

    $stmt = $conn->prepare("
        INSERT INTO horarios_item (item_id, dia_semana, hora_inicio, hora_fin, activo)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssi", $itemId, $diaSemana, $horaInicio, $horaFin, $activo);
    
    if ($stmt->execute()) {
        $scheduleId = $conn->insert_id;
        if (function_exists('registrarCambio')) {
            registrarCambio($conn, 'horarios_item', $scheduleId, $user->id, 'creacion', "Horario creado");
        }
        jsonResponse(["success" => true, "message" => "Horario creado", "horario_id" => $scheduleId], 201);
    } else {
        jsonResponse(["error" => "Error al crear horario: " . $stmt->error], 500);
    }
}

function handlePut($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['id'])) {
        jsonResponse(["error" => "ID del horario requerido"], 400);
    }
    
    $conn = getConnection();
    $scheduleId = intval($data['id']);

    $stmt = $conn->prepare("
        SELECT h.id FROM horarios_item h
        JOIN items_menu i ON h.item_id = i.id
        JOIN categorias_menu c ON i.categoria_id = c.id
        JOIN menus m ON c.menu_id = m.id
        WHERE h.id = ? AND m.restaurante_id = ?
    ");
    $stmt->bind_param("ii", $scheduleId, $user->restaurante_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Horario no encontrado"], 404);
    }

    $updates = [];
    $params = [];
    $types = "";
    $fields = ['dia_semana' => 's', 'hora_inicio' => 's', 'hora_fin' => 's', 'activo' => 'i'];
    
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
    
    $types .= 'i';
    $params[] = $scheduleId;
    
    $sql = "UPDATE horarios_item SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        if (function_exists('registrarCambio')) {
            registrarCambio($conn, 'horarios_item', $scheduleId, $user->id, 'actualizacion', "Horario actualizado");
        }
        jsonResponse(["success" => true, "message" => "Horario actualizado"]);
    } else {
        jsonResponse(["error" => "Error al actualizar: " . $stmt->error], 500);
    }
}

function handleDelete($user) {
    $scheduleId = isset($_GET['id']) ? intval($_GET['id']) : null;
    if (!$scheduleId) {
        jsonResponse(["error" => "ID del horario requerido"], 400);
    }
    
    $conn = getConnection();

    $stmt = $conn->prepare("
        SELECT h.id FROM horarios_item h
        JOIN items_menu i ON h.item_id = i.id
        JOIN categorias_menu c ON i.categoria_id = c.id
        JOIN menus m ON c.menu_id = m.id
        WHERE h.id = ? AND m.restaurante_id = ?
    ");
    $stmt->bind_param("ii", $scheduleId, $user->restaurante_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Horario no encontrado"], 404);
    }

    $stmt = $conn->prepare("DELETE FROM horarios_item WHERE id = ?");
    $stmt->bind_param("i", $scheduleId);
    
    if ($stmt->execute()) {
        if (function_exists('registrarCambio')) {
            registrarCambio($conn, 'horarios_item', $scheduleId, $user->id, 'eliminacion', "Horario eliminado");
        }
        jsonResponse(["success" => true, "message" => "Horario eliminado"]);
    } else {
        jsonResponse(["error" => "Error al eliminar"], 500);
    }
}
?>