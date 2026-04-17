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
    $promotionId = isset($_GET['id']) ? intval($_GET['id']) : null;
    $activeOnly = isset($_GET['active']) ? $_GET['active'] === 'true' : false;

    if ($promotionId) {
        $stmt = $conn->prepare("
            SELECT p.*,
                   GROUP_CONCAT(pi.item_id) as items_ids
            FROM promociones p
            LEFT JOIN promociones_items pi ON p.id = pi.promocion_id
            WHERE p.id = ? AND p.restaurante_id = ?
            GROUP BY p.id
        ");
        $stmt->bind_param("ii", $promotionId, $user->restaurante_id);
        $stmt->execute();
        $promotion = $stmt->get_result()->fetch_assoc();
        if (!$promotion) {
            jsonResponse(["error" => "Promoción no encontrada"], 404);
        }
        $promotion['items_ids'] = $promotion['items_ids'] ? array_map('intval', explode(',', $promotion['items_ids'])) : [];
        jsonResponse($promotion);
    } else {
        $sql = "
            SELECT p.id, p.restaurante_id, p.nombre, p.descripcion, p.tipo, p.valor,
                   p.fecha_inicio, p.fecha_fin, p.dias_semana, p.hora_inicio, p.hora_fin,
                   p.activa, p.created_at,
                   COUNT(pi.item_id) as num_items
            FROM promociones p
            LEFT JOIN promociones_items pi ON p.id = pi.promocion_id
            WHERE p.restaurante_id = ?
        ";
        $params = [$user->restaurante_id];
        $types = "i";
        if ($activeOnly) {
            $sql .= " AND p.activa = 1 AND NOW() BETWEEN p.fecha_inicio AND p.fecha_fin";
        }
        $sql .= " GROUP BY p.id ORDER BY p.fecha_inicio DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        jsonResponse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
}

function handlePost($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['nombre']) || !isset($data['tipo']) || !isset($data['fecha_inicio']) || !isset($data['fecha_fin'])) {
        jsonResponse(["error" => "Datos incompletos"], 400);
    }
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $nombre = sanitizeInput($data['nombre']);
        $descripcion = isset($data['descripcion']) ? sanitizeInput($data['descripcion']) : null;
        $tipo = sanitizeInput($data['tipo']);
        $valor = isset($data['valor']) ? floatval($data['valor']) : null;
        $fechaInicio = sanitizeInput($data['fecha_inicio']);
        $fechaFin = sanitizeInput($data['fecha_fin']);
        $diasSemana = isset($data['dias_semana']) ? sanitizeInput($data['dias_semana']) : null;
        $horaInicio = isset($data['hora_inicio']) ? sanitizeInput($data['hora_inicio']) : null;
        $horaFin = isset($data['hora_fin']) ? sanitizeInput($data['hora_fin']) : null;
        $activa = isset($data['activa']) ? (int)$data['activa'] : 1;

        $stmt = $conn->prepare("
            INSERT INTO promociones (
                restaurante_id, nombre, descripcion, tipo, valor,
                fecha_inicio, fecha_fin, dias_semana, hora_inicio, hora_fin, activa
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isssdsssssi",
            $user->restaurante_id, $nombre, $descripcion, $tipo, $valor,
            $fechaInicio, $fechaFin, $diasSemana, $horaInicio, $horaFin, $activa
        );
        if (!$stmt->execute()) {
            throw new Exception("Error al crear promoción: " . $stmt->error);
        }
        $promotionId = $conn->insert_id;

        if (isset($data['items_ids']) && is_array($data['items_ids'])) {
            foreach ($data['items_ids'] as $itemId) {
                $stmt = $conn->prepare("
                    SELECT i.id FROM items_menu i
                    JOIN categorias_menu c ON i.categoria_id = c.id
                    JOIN menus m ON c.menu_id = m.id
                    WHERE i.id = ? AND m.restaurante_id = ?
                ");
                $stmt->bind_param("ii", $itemId, $user->restaurante_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) {
                    throw new Exception("El ítem ID $itemId no pertenece al restaurante");
                }
                $stmt = $conn->prepare("INSERT INTO promociones_items (promocion_id, item_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $promotionId, $itemId);
                $stmt->execute();
            }
        }
        $conn->commit();
        jsonResponse(["success" => true, "message" => "Promoción creada", "promocion_id" => $promotionId], 201);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(["error" => $e->getMessage()], 500);
    }
}

function handlePut($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['id'])) {
        jsonResponse(["error" => "ID de la promoción requerido"], 400);
    }
    $conn = getConnection();
    $promotionId = intval($data['id']);

    $stmt = $conn->prepare("SELECT id FROM promociones WHERE id = ? AND restaurante_id = ?");
    $stmt->bind_param("ii", $promotionId, $user->restaurante_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Promoción no encontrada"], 404);
    }

    $conn->begin_transaction();
    try {
        $updates = [];
        $params = [];
        $types = "";
        $fields = [
            'nombre' => 's', 'descripcion' => 's', 'tipo' => 's', 'valor' => 'd',
            'fecha_inicio' => 's', 'fecha_fin' => 's', 'dias_semana' => 's',
            'hora_inicio' => 's', 'hora_fin' => 's', 'activa' => 'i'
        ];
        foreach ($fields as $field => $type) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }
        if (!empty($updates)) {
            $types .= "ii";
            $params[] = $promotionId;
            $params[] = $user->restaurante_id;
            $sql = "UPDATE promociones SET " . implode(', ', $updates) . " WHERE id = ? AND restaurante_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                throw new Exception("Error al actualizar promoción: " . $stmt->error);
            }
        }

        if (isset($data['items_ids'])) {
            $stmt = $conn->prepare("DELETE FROM promociones_items WHERE promocion_id = ?");
            $stmt->bind_param("i", $promotionId);
            $stmt->execute();
            if (is_array($data['items_ids']) && !empty($data['items_ids'])) {
                foreach ($data['items_ids'] as $itemId) {
                    $stmt = $conn->prepare("
                        SELECT i.id FROM items_menu i
                        JOIN categorias_menu c ON i.categoria_id = c.id
                        JOIN menus m ON c.menu_id = m.id
                        WHERE i.id = ? AND m.restaurante_id = ?
                    ");
                    $stmt->bind_param("ii", $itemId, $user->restaurante_id);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows === 0) {
                        throw new Exception("El ítem ID $itemId no pertenece al restaurante");
                    }
                    $stmt = $conn->prepare("INSERT INTO promociones_items (promocion_id, item_id) VALUES (?, ?)");
                    $stmt->bind_param("ii", $promotionId, $itemId);
                    $stmt->execute();
                }
            }
        }
        $conn->commit();
        registrarCambio($conn, 'promociones', $promotionId, $user->id, 'actualizacion', "Promoción actualizada");
        jsonResponse(["success" => true, "message" => "Promoción actualizada"]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(["error" => $e->getMessage()], 500);
    }
}

function handleDelete($user) {
    $promotionId = isset($_GET['id']) ? intval($_GET['id']) : null;
    if (!$promotionId) {
        jsonResponse(["error" => "ID de la promoción requerido"], 400);
    }
    $conn = getConnection();

    $stmt = $conn->prepare("SELECT id FROM promociones WHERE id = ? AND restaurante_id = ?");
    $stmt->bind_param("ii", $promotionId, $user->restaurante_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Promoción no encontrada"], 404);
    }

    $stmt = $conn->prepare("DELETE FROM promociones WHERE id = ?");
    $stmt->bind_param("i", $promotionId);
    if ($stmt->execute()) {
        registrarCambio($conn, 'promociones', $promotionId, $user->id, 'eliminacion', "Promoción eliminada");
        jsonResponse(["success" => true, "message" => "Promoción eliminada"]);
    } else {
        jsonResponse(["error" => "Error al eliminar"], 500);
    }
}
?>