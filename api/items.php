<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Verificar que la respuesta siempre sea JSON
header('Content-Type: application/json');

$user = validateToken();
$method = $_SERVER['REQUEST_METHOD'];

if ($user->rol !== 'admin' && $user->rol !== 'manager') {
    jsonResponse(["error" => "No autorizado"], 403);
}

try {
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
} catch (Exception $e) {
    jsonResponse(["error" => "Error en el servidor: " . $e->getMessage()], 500);
}

function handleGet($user) {
    $conn = getConnection();
    $itemId = isset($_GET['id']) ? intval($_GET['id']) : null;
    $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    $search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;

    if ($itemId) {
        // Obtener un ítem específico
        $stmt = $conn->prepare("
            SELECT i.*, c.nombre as categoria_nombre, c.id as categoria_id, m.nombre as menu_nombre
            FROM items_menu i
            JOIN categorias_menu c ON i.categoria_id = c.id
            JOIN menus m ON c.menu_id = m.id
            WHERE i.id = ? AND m.restaurante_id = ?
        ");
        $stmt->bind_param("ii", $itemId, $user->restaurante_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            jsonResponse(["error" => "Ítem no encontrado"], 404);
        }
        
        $item = $result->fetch_assoc();

        // Información nutricional
        $stmt = $conn->prepare("SELECT * FROM informacion_nutricional WHERE item_id = ?");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $nutrition = $stmt->get_result()->fetch_assoc();
        if ($nutrition) $item['informacion_nutricional'] = $nutrition;

        // Alérgenos
        $stmt = $conn->prepare("
            SELECT a.* FROM alergenos a
            JOIN items_alergenos ia ON a.id = ia.alergeno_id
            WHERE ia.item_id = ?
        ");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $item['alergenos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Horarios
        $stmt = $conn->prepare("SELECT * FROM horarios_item WHERE item_id = ? ORDER BY dia_semana, hora_inicio");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $item['horarios'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Opciones de personalización
        $stmt = $conn->prepare("
            SELECT op.*,
                   (SELECT GROUP_CONCAT(CONCAT(id, '|', valor, '|', precio_extra, '|', disponible) SEPARATOR ';')
                    FROM valores_opcion WHERE opcion_id = op.id) as valores
            FROM opciones_personalizacion op
            WHERE op.item_id = ?
            ORDER BY op.orden
        ");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $opciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($opciones as &$opcion) {
            if ($opcion['valores']) {
                $valores = [];
                $pares = explode(';', $opcion['valores']);
                foreach ($pares as $par) {
                    list($id, $valor, $precio, $disponible) = explode('|', $par);
                    $valores[] = ['id' => $id, 'valor' => $valor, 'precio_extra' => $precio, 'disponible' => $disponible];
                }
                $opcion['valores'] = $valores;
            } else {
                $opcion['valores'] = [];
            }
        }
        $item['opciones_personalizacion'] = $opciones;

        jsonResponse($item);
    } else {
        // Lista de ítems
        $sql = "
            SELECT i.*, c.nombre as categoria_nombre, m.nombre as menu_nombre
            FROM items_menu i
            JOIN categorias_menu c ON i.categoria_id = c.id
            JOIN menus m ON c.menu_id = m.id
            WHERE m.restaurante_id = ?
        ";
        $params = [$user->restaurante_id];
        $types = "i";

        if ($categoryId) {
            $sql .= " AND i.categoria_id = ?";
            $types .= "i";
            $params[] = $categoryId;
        }
        if ($search) {
            $sql .= " AND (i.nombre LIKE ? OR i.descripcion LIKE ?)";
            $types .= "ss";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        if ($status === 'available') {
            $sql .= " AND i.disponible = 1";
        } elseif ($status === 'unavailable') {
            $sql .= " AND i.disponible = 0";
        }
        $sql .= " ORDER BY i.nombre";

        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        jsonResponse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
}

function handlePost($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    // Verificar que los datos sean válidos
    if (!$data) {
        jsonResponse(["error" => "Datos JSON inválidos"], 400);
    }
    
    if (!isset($data['nombre']) || !isset($data['precio_base']) || !isset($data['categoria_id'])) {
        jsonResponse(["error" => "Datos incompletos"], 400);
    }

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Verificar categoría
        $stmt = $conn->prepare("
            SELECT c.* FROM categorias_menu c
            JOIN menus m ON c.menu_id = m.id
            WHERE c.id = ? AND m.restaurante_id = ?
        ");
        $stmt->bind_param("ii", $data['categoria_id'], $user->restaurante_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception("Categoría no válida");
        }

        // Insertar ítem
        $nombre = sanitizeInput($data['nombre']);
        $descripcion = isset($data['descripcion']) ? sanitizeInput($data['descripcion']) : '';
        $precioBase = floatval($data['precio_base']);
        $precioPromocional = isset($data['precio_promocional']) ? floatval($data['precio_promocional']) : null;
        $disponible = isset($data['disponible']) ? (int)$data['disponible'] : 1;
        $destacado = isset($data['destacado']) ? (int)$data['destacado'] : 0;
        $calorias = isset($data['calorias']) ? intval($data['calorias']) : null;
        $tiempoPreparacion = isset($data['tiempo_preparacion']) ? intval($data['tiempo_preparacion']) : null;
        $imagenUrl = isset($data['imagen_url']) ? sanitizeInput($data['imagen_url']) : null;
        $ingredientes = isset($data['ingredientes']) ? sanitizeInput($data['ingredientes']) : null;

        $stmt = $conn->prepare("
            INSERT INTO items_menu (
                categoria_id, nombre, descripcion, precio_base, precio_promocional,
                disponible, destacado, calorias, tiempo_preparacion, imagen_url, ingredientes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "issddiiiiss",
            $data['categoria_id'], $nombre, $descripcion, $precioBase, $precioPromocional,
            $disponible, $destacado, $calorias, $tiempoPreparacion, $imagenUrl, $ingredientes
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Error al crear el ítem: " . $stmt->error);
        }
        
        $itemId = $conn->insert_id;

        // Información nutricional
        if (isset($data['informacion_nutricional'])) {
            $nut = $data['informacion_nutricional'];
            $stmt = $conn->prepare("
                INSERT INTO informacion_nutricional (
                    item_id, proteinas_g, carbohidratos_g, grasas_g,
                    grasas_saturadas_g, fibra_g, azucar_g, sodio_mg, colesterol_mg
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "idddddddd",
                $itemId,
                $nut['proteinas_g'] ?? null,
                $nut['carbohidratos_g'] ?? null,
                $nut['grasas_g'] ?? null,
                $nut['grasas_saturadas_g'] ?? null,
                $nut['fibra_g'] ?? null,
                $nut['azucar_g'] ?? null,
                $nut['sodio_mg'] ?? null,
                $nut['colesterol_mg'] ?? null
            );
            $stmt->execute();
        }

        // Alérgenos
        if (isset($data['alergenos']) && is_array($data['alergenos'])) {
            foreach ($data['alergenos'] as $alergenoId) {
                $stmt = $conn->prepare("INSERT INTO items_alergenos (item_id, alergeno_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $itemId, intval($alergenoId));
                $stmt->execute();
            }
        }

        $conn->commit();
        jsonResponse(["success" => true, "message" => "Ítem creado", "item_id" => $itemId], 201);
        
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(["error" => $e->getMessage()], 500);
    }
}

function handlePut($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!$data) {
        jsonResponse(["error" => "Datos JSON inválidos"], 400);
    }
    
    if (!isset($data['id'])) {
        jsonResponse(["error" => "ID del ítem requerido"], 400);
    }
    
    $conn = getConnection();
    $itemId = intval($data['id']);

    // Verificar propiedad
    $checkStmt = $conn->prepare("
        SELECT i.id 
        FROM items_menu i
        JOIN categorias_menu c ON i.categoria_id = c.id
        JOIN menus m ON c.menu_id = m.id
        WHERE i.id = ? AND m.restaurante_id = ?
    ");
    $checkStmt->bind_param("ii", $itemId, $user->restaurante_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        jsonResponse(["error" => "Ítem no encontrado"], 404);
    }

    // Construir consulta de actualización (INCLUYENDO descripcion)
    $updates = [];
    $params = [];
    $types = "";
    
    // Lista completa de campos que se pueden actualizar
    $camposPermitidos = [
        'nombre' => 's',
        'descripcion' => 's',  // <-- AHORA SÍ INCLUIDA
        'precio_base' => 'd',
        'precio_promocional' => 'd',
        'disponible' => 'i',
        'destacado' => 'i',
        'calorias' => 'i',
        'tiempo_preparacion' => 'i',
        'imagen_url' => 's',
        'ingredientes' => 's',
        'categoria_id' => 'i'
    ];
    
    foreach ($camposPermitidos as $campo => $tipo) {
        if (isset($data[$campo])) {
            $updates[] = "$campo = ?";
            $params[] = $data[$campo];
            $types .= $tipo;
        }
    }
    
    if (empty($updates)) {
        jsonResponse(["error" => "No hay datos para actualizar"], 400);
    }
    
    $types .= "i";
    $params[] = $itemId;
    
    $sql = "UPDATE items_menu SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(["error" => "Error preparando consulta: " . $conn->error], 500);
    }
    
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        jsonResponse(["success" => true, "message" => "Ítem actualizado correctamente"]);
    } else {
        jsonResponse(["error" => "Error al ejecutar: " . $stmt->error], 500);
    }
}
function handleDelete($user) {
    $itemId = isset($_GET['id']) ? intval($_GET['id']) : null;
    if (!$itemId) {
        jsonResponse(["error" => "ID del ítem requerido"], 400);
    }
    
    $conn = getConnection();

    // Verificar propiedad
    $stmt = $conn->prepare("
        SELECT i.* FROM items_menu i
        JOIN categorias_menu c ON i.categoria_id = c.id
        JOIN menus m ON c.menu_id = m.id
        WHERE i.id = ? AND m.restaurante_id = ?
    ");
    $stmt->bind_param("ii", $itemId, $user->restaurante_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        jsonResponse(["error" => "Ítem no encontrado"], 404);
    }

    $stmt = $conn->prepare("DELETE FROM items_menu WHERE id = ?");
    $stmt->bind_param("i", $itemId);
    
    if ($stmt->execute()) {
        if (function_exists('registrarCambio')) {
            registrarCambio($conn, 'items_menu', $itemId, $user->id, 'eliminacion', "Ítem eliminado");
        }
        jsonResponse(["success" => true, "message" => "Ítem eliminado"]);
    } else {
        jsonResponse(["error" => "Error al eliminar"], 500);
    }
}
?>