<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

header('Content-Type: application/json');

// Obtener método de solicitud
$method = $_SERVER['REQUEST_METHOD'];

// Manejar solicitudes OPTIONS (para CORS)
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verificar que el token sea válido
try {
    $user = validateToken();
} catch (Exception $e) {
    jsonResponse(["error" => "Error de autenticación: " . $e->getMessage()], 401);
    exit();
}

if ($method === 'GET') {
    handleGet($user);
} elseif ($method === 'POST') {
    handlePost($user);
} else {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
    exit();
}

function handleGet($user) {
    try {
        $conn = getConnection();
        
        // Verificar que el restaurante_id existe
        if (!isset($user->restaurante_id) || !is_numeric($user->restaurante_id)) {
            jsonResponse(["error" => "ID de restaurante no válido en el token"], 400);
        }
        
        // Obtener datos del restaurante
        $sql = "SELECT * FROM restaurantes WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            jsonResponse([
                "error" => "Error preparando consulta de restaurantes",
                "sql_error" => $conn->error,
                "sql" => $sql
            ], 500);
        }
        
        $stmt->bind_param("i", $user->restaurante_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            jsonResponse(["error" => "Restaurante no encontrado con ID: " . $user->restaurante_id], 404);
        }
        
        $restaurant = $result->fetch_assoc();
        
        // Obtener datos del usuario (SIN teléfono, porque no existe en la tabla)
        $sql = "SELECT nombre, email FROM usuarios WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            jsonResponse([
                "error" => "Error preparando consulta de usuarios",
                "sql_error" => $conn->error,
                "sql" => $sql
            ], 500);
        }
        
        $stmt->bind_param("i", $user->id);
        $stmt->execute();
        $userResult = $stmt->get_result();
        
        if ($userResult->num_rows === 0) {
            jsonResponse(["error" => "Usuario no encontrado con ID: " . $user->id], 404);
        }
        
        $userData = $userResult->fetch_assoc();
        
        jsonResponse([
            "success" => true,
            "restaurant" => $restaurant,
            "user" => $userData
        ]);
        
    } catch (Exception $e) {
        jsonResponse(["error" => "Error en handleGet: " . $e->getMessage()], 500);
    }
}

function handlePost($user) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!$data) {
            jsonResponse(["error" => "Datos inválidos o JSON mal formado"], 400);
        }
        
        $conn = getConnection();
        $conn->begin_transaction();
        
        // Actualizar datos del restaurante
        $restaurantUpdates = [];
        $restaurantParams = [];
        $restaurantTypes = "";
        
        $restaurantFields = [
            'nombre' => 's',
            'direccion' => 's',
            'telefono' => 's',
            'email' => 's',
            'horario_apertura' => 's',
            'horario_cierre' => 's'
        ];
        
        foreach ($restaurantFields as $field => $type) {
            if (isset($data[$field])) {
                $restaurantUpdates[] = "$field = ?";
                $restaurantParams[] = $data[$field];
                $restaurantTypes .= $type;
            }
        }
        
        if (!empty($restaurantUpdates)) {
            $restaurantTypes .= "i";
            $restaurantParams[] = $user->restaurante_id;
            
            $sql = "UPDATE restaurantes SET " . implode(', ', $restaurantUpdates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Error preparando consulta de restaurantes: " . $conn->error);
            }
            $stmt->bind_param($restaurantTypes, ...$restaurantParams);
            
            if (!$stmt->execute()) {
                throw new Exception("Error al actualizar restaurante: " . $stmt->error);
            }
        }
        
        // Actualizar datos del usuario (solo nombre y email)
        $userUpdates = [];
        $userParams = [];
        $userTypes = "";
        
        if (isset($data['nombre_usuario'])) {
            $userUpdates[] = "nombre = ?";
            $userParams[] = $data['nombre_usuario'];
            $userTypes .= "s";
        }
        
        if (isset($data['email']) && $data['email'] !== $user->email) {
            // Verificar que el email no esté en uso
            $sql = "SELECT id FROM usuarios WHERE email = ? AND id != ?";
            $checkStmt = $conn->prepare($sql);
            if (!$checkStmt) {
                throw new Exception("Error preparando consulta de verificación de email: " . $conn->error);
            }
            $checkStmt->bind_param("si", $data['email'], $user->id);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception("El email ya está registrado por otro usuario");
            }
            $userUpdates[] = "email = ?";
            $userParams[] = $data['email'];
            $userTypes .= "s";
        }
        
        // NOTA: teléfono no se actualiza porque no existe en la tabla usuarios
        
        if (!empty($userUpdates)) {
            $userTypes .= "i";
            $userParams[] = $user->id;
            
            $sql = "UPDATE usuarios SET " . implode(', ', $userUpdates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Error preparando consulta de usuario: " . $conn->error);
            }
            $stmt->bind_param($userTypes, ...$userParams);
            
            if (!$stmt->execute()) {
                throw new Exception("Error al actualizar usuario: " . $stmt->error);
            }
        }
        
        $conn->commit();
        
        // Obtener datos actualizados
        $sql = "SELECT * FROM restaurantes WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user->restaurante_id);
        $stmt->execute();
        $restaurant = $stmt->get_result()->fetch_assoc();
        
        $sql = "SELECT nombre, email FROM usuarios WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user->id);
        $stmt->execute();
        $userData = $stmt->get_result()->fetch_assoc();
        
        jsonResponse([
            "success" => true,
            "message" => "Configuración guardada correctamente",
            "restaurant" => $restaurant,
            "user" => $userData
        ]);
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        jsonResponse(["error" => $e->getMessage()], 500);
    }
}
?>