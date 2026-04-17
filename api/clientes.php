<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(["success" => true]);
    exit();
}

function validateClienteToken() {
    $headers = getallheaders();
    $auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    $token = str_replace('Bearer ', '', $auth);
    
    if (empty($token)) {
        return null;
    }
    
    try {
        $decoded = json_decode(base64_decode($token), true);
        if (isset($decoded['exp']) && $decoded['exp'] < time()) {
            return null;
        }
        return (object) $decoded;
    } catch (Exception $e) {
        return null;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// Rutas públicas (no requieren token)
if ($method === 'GET' && isset($_GET['tipo'])) {
    $tipo = $_GET['tipo'];
    
    if ($tipo === 'restaurantes') {
        $conn = getConnection();
        $result = $conn->query("SELECT id, nombre, direccion FROM restaurantes WHERE activo = 1 ORDER BY nombre");
        $restaurantes = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($restaurantes);
        exit();
    }
    
    if ($tipo === 'items') {
        $restaurante_id = isset($_GET['restaurante_id']) ? intval($_GET['restaurante_id']) : 0;
        if (!$restaurante_id) {
            echo json_encode([]);
            exit();
        }
        
        $conn = getConnection();
        $stmt = $conn->prepare("
            SELECT i.id, i.nombre, i.descripcion, i.precio_base, i.calorias, 
                   COALESCE(c.nombre, 'Sin categoria') as categoria
            FROM items_menu i
            LEFT JOIN categorias_menu c ON i.categoria_id = c.id
            LEFT JOIN menus m ON c.menu_id = m.id
            WHERE m.restaurante_id = ? AND i.disponible = 1
        ");
        $stmt->bind_param("i", $restaurante_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($items);
        exit();
    }
}

// Rutas protegidas (requieren token)
$user = validateClienteToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(["error" => "Token inválido o expirado"]);
    exit();
}

// GET autenticado
if ($method === 'GET') {
    $conn = getConnection();
    $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
    
    if ($tipo === 'historial') {
        $stmt = $conn->prepare("
            SELECT r.*, res.nombre as restaurante_nombre,
                   (SELECT COUNT(*) FROM registro_items WHERE registro_id = r.id) as total_items,
                   (SELECT SUM(calorias_consumidas) FROM registro_items WHERE registro_id = r.id) as total_calorias
            FROM registros_comida r
            LEFT JOIN restaurantes res ON r.restaurante_id = res.id
            WHERE r.usuario_id = ?
            ORDER BY r.fecha_registro DESC
        ");
        $stmt->bind_param("i", $user->id);
        $stmt->execute();
        $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($registros);
        exit();
    }
    
    if ($tipo === 'favoritos') {
        $stmt = $conn->prepare("
            SELECT f.*, i.nombre, i.calorias, c.nombre as categoria, res.nombre as restaurante_nombre
            FROM favoritos f
            JOIN items_menu i ON f.item_id = i.id
            JOIN categorias_menu c ON i.categoria_id = c.id
            JOIN menus m ON c.menu_id = m.id
            JOIN restaurantes res ON m.restaurante_id = res.id
            WHERE f.usuario_id = ?
        ");
        $stmt->bind_param("i", $user->id);
        $stmt->execute();
        $favoritos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($favoritos);
        exit();
    }
    
    if ($tipo === 'estadisticas') {
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(AVG(total_calorias), 0) as promedio_calorias,
                COUNT(*) as total_registros
            FROM (
                SELECT SUM(calorias_consumidas) as total_calorias
                FROM registro_items
                WHERE registro_id IN (SELECT id FROM registros_comida WHERE usuario_id = ?)
                GROUP BY registro_id
            ) as stats
        ");
        $stmt->bind_param("i", $user->id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stats['por_dia'] = [];
        echo json_encode($stats);
        exit();
    }
    
    if ($tipo === 'perfil') {
        $stmt = $conn->prepare("SELECT * FROM clientes_usuarios WHERE id = ?");
        $stmt->bind_param("i", $user->id);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        
        if ($profile) {
            echo json_encode([
                "id" => $profile['id'],
                "name" => $profile['nombre'],
                "email" => $profile['email'],
                "edad" => $profile['edad'],
                "peso" => $profile['peso'],
                "altura" => $profile['altura'],
                "objetivo_calorias" => $profile['objetivo_calorias_diarias'],
                "tema" => $profile['tema'] ?? 'default'
            ]);
        } else {
            echo json_encode(["error" => "Perfil no encontrado"]);
        }
        exit();
    }
    
    echo json_encode(["error" => "Tipo no válido"]);
    exit();
}

// POST
if ($method === 'POST') {
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(["error" => "Datos inválidos"]);
        exit();
    }
    
    $conn = getConnection();
    $accion = isset($data['accion']) ? $data['accion'] : '';
    
    if ($accion === 'registrar_comida') {
        if (!isset($data['fecha']) || !isset($data['tipo_comida']) || !isset($data['items'])) {
            echo json_encode(["error" => "Datos incompletos"]);
            exit();
        }
        
        $conn->begin_transaction();
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO registros_comida (usuario_id, restaurante_id, fecha_registro, tipo_comida, notas)
                VALUES (?, ?, ?, ?, ?)
            ");
            $restaurante_id = isset($data['restaurante_id']) ? intval($data['restaurante_id']) : null;
            $notas = isset($data['notas']) ? $data['notas'] : null;
            $stmt->bind_param("iisss", $user->id, $restaurante_id, $data['fecha'], $data['tipo_comida'], $notas);
            $stmt->execute();
            $registro_id = $conn->insert_id;
            
            foreach ($data['items'] as $item) {
                $stmt = $conn->prepare("SELECT calorias FROM items_menu WHERE id = ?");
                $stmt->bind_param("i", $item['item_id']);
                $stmt->execute();
                $item_data = $stmt->get_result()->fetch_assoc();
                $calorias = ($item_data ? $item_data['calorias'] : 0) * $item['cantidad'];
                
                $stmt = $conn->prepare("INSERT INTO registro_items (registro_id, item_id, cantidad, calorias_consumidas) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiii", $registro_id, $item['item_id'], $item['cantidad'], $calorias);
                $stmt->execute();
            }
            
            $conn->commit();
            echo json_encode(["success" => true, "message" => "Comida registrada"]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["error" => $e->getMessage()]);
        }
        exit();
    }
    
    if ($accion === 'favorito') {
        if (!isset($data['item_id']) || !isset($data['accion_favorito'])) {
            echo json_encode(["error" => "Datos incompletos"]);
            exit();
        }
        
        if ($data['accion_favorito'] === 'agregar') {
            $stmt = $conn->prepare("INSERT IGNORE INTO favoritos (usuario_id, item_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user->id, $data['item_id']);
            $stmt->execute();
            echo json_encode(["success" => true, "message" => "Agregado a favoritos"]);
        } else {
            $stmt = $conn->prepare("DELETE FROM favoritos WHERE usuario_id = ? AND item_id = ?");
            $stmt->bind_param("ii", $user->id, $data['item_id']);
            $stmt->execute();
            echo json_encode(["success" => true, "message" => "Eliminado de favoritos"]);
        }
        exit();
    }
    
    echo json_encode(["error" => "Acción no válida"]);
    exit();
}

// PUT
if ($method === 'PUT') {
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(["error" => "Datos inválidos"]);
        exit();
    }
    
    $conn = getConnection();
    $accion = isset($data['accion']) ? $data['accion'] : '';
    
    if ($accion === 'actualizar_perfil') {
        $updates = [];
        $params = [];
        $types = "";
        
        if (isset($data['nombre'])) {
            $updates[] = "nombre = ?";
            $params[] = $data['nombre'];
            $types .= "s";
        }
        if (isset($data['edad'])) {
            $updates[] = "edad = ?";
            $params[] = intval($data['edad']);
            $types .= "i";
        }
        if (isset($data['peso'])) {
            $updates[] = "peso = ?";
            $params[] = floatval($data['peso']);
            $types .= "d";
        }
        if (isset($data['altura'])) {
            $updates[] = "altura = ?";
            $params[] = floatval($data['altura']);
            $types .= "d";
        }
        if (isset($data['objetivo_calorias'])) {
            $updates[] = "objetivo_calorias_diarias = ?";
            $params[] = intval($data['objetivo_calorias']);
            $types .= "i";
        }
        if (isset($data['tema'])) {
            $updates[] = "tema = ?";
            $params[] = $data['tema'];
            $types .= "s";
        }
        
        if (empty($updates)) {
            echo json_encode(["error" => "No hay datos para actualizar"]);
            exit();
        }
        
        $types .= "i";
        $params[] = $user->id;
        
        $sql = "UPDATE clientes_usuarios SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            // Obtener datos actualizados
            $stmt = $conn->prepare("SELECT * FROM clientes_usuarios WHERE id = ?");
            $stmt->bind_param("i", $user->id);
            $stmt->execute();
            $updatedUser = $stmt->get_result()->fetch_assoc();
            
            echo json_encode([
                "success" => true,
                "message" => "Perfil actualizado",
                "user" => [
                    "id" => $updatedUser['id'],
                    "email" => $updatedUser['email'],
                    "name" => $updatedUser['nombre'],
                    "edad" => $updatedUser['edad'],
                    "peso" => $updatedUser['peso'],
                    "altura" => $updatedUser['altura'],
                    "objetivo_calorias" => $updatedUser['objetivo_calorias_diarias'],
                    "tema" => $updatedUser['tema'] ?? 'default'
                ]
            ]);
        } else {
            echo json_encode(["error" => "Error al actualizar"]);
        }
        exit();
    }
    
    echo json_encode(["error" => "Acción no válida"]);
    exit();
}

// DELETE
if ($method === 'DELETE') {
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(["error" => "Datos inválidos"]);
        exit();
    }
    
    $conn = getConnection();
    $accion = isset($data['accion']) ? $data['accion'] : '';
    
    if ($accion === 'eliminar_registro') {
        if (!isset($data['registro_id'])) {
            echo json_encode(["error" => "ID requerido"]);
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM registros_comida WHERE id = ? AND usuario_id = ?");
        $stmt->bind_param("ii", $data['registro_id'], $user->id);
        
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Registro eliminado"]);
        } else {
            echo json_encode(["error" => "Error al eliminar"]);
        }
        exit();
    }
    
    echo json_encode(["error" => "Acción no válida"]);
    exit();
}

echo json_encode(["error" => "Método no soportado"]);
?>