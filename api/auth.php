<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['action'])) {
    jsonResponse(["error" => "Acción no especificada"], 400);
}

switch ($data['action']) {
    case 'login':
        handleLogin($data);
        break;
    case 'register':
        handleRegister($data);
        break;
    case 'logout':
        jsonResponse(["success" => true, "message" => "Sesión cerrada"]);
        break;
    default:
        jsonResponse(["error" => "Acción no válida"], 400);
}

function handleLogin($data) {
    if (!isset($data['email']) || !isset($data['password'])) {
        jsonResponse(["error" => "Email y contraseña requeridos"], 400);
    }
    
    $conn = getConnection();
    $email = sanitizeInput($data['email']);
    $password = $data['password'];

    // Demo user para pruebas
    if ($email === 'demo@demo.com' && $password === 'demo123') {
        $token = base64_encode(json_encode([
            'id' => 1,
            'email' => 'demo@demo.com',
            'nombre' => 'Demo User',
            'rol' => 'admin',
            'restaurante_id' => 1,
            'exp' => time() + (24 * 60 * 60)
        ]));

        jsonResponse([
            "success" => true,
            "token" => $token,
            "user" => [
                "id" => 1,
                "email" => "demo@demo.com",
                "name" => "Demo User",
                "role" => "admin",
                "restaurant_id" => 1,
                "restaurant_name" => "Demo Restaurant"
            ]
        ]);
        return;
    }

    // Buscar en base de datos real
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse(["error" => "Usuario no encontrado"], 401);
    }
    
    $user = $result->fetch_assoc();

    if (password_verify($password, $user['contrasena_hash'])) {
        $token = base64_encode(json_encode([
            'id' => $user['id'],
            'email' => $user['email'],
            'nombre' => $user['nombre'],
            'rol' => $user['rol'],
            'restaurante_id' => $user['restaurante_id'],
            'exp' => time() + (24 * 60 * 60)
        ]));

        // Obtener nombre del restaurante
        $restaurantName = null;
        if ($user['restaurante_id']) {
            $stmt2 = $conn->prepare("SELECT nombre FROM restaurantes WHERE id = ?");
            $stmt2->bind_param("i", $user['restaurante_id']);
            $stmt2->execute();
            $restaurantResult = $stmt2->get_result();
            if ($restaurantResult->num_rows > 0) {
                $restaurantName = $restaurantResult->fetch_assoc()['nombre'];
            }
        }

        jsonResponse([
            "success" => true,
            "token" => $token,
            "user" => [
                "id" => $user['id'],
                "email" => $user['email'],
                "name" => $user['nombre'],
                "role" => $user['rol'],
                "restaurant_id" => $user['restaurante_id'],
                "restaurant_name" => $restaurantName
            ]
        ]);
    } else {
        jsonResponse(["error" => "Contraseña incorrecta"], 401);
    }
}

function handleRegister($data) {
    if (!isset($data['restaurant_name']) || !isset($data['email']) || !isset($data['password']) || !isset($data['name'])) {
        jsonResponse(["error" => "Datos incompletos"], 400);
    }
    
    $conn = getConnection();
    $email = sanitizeInput($data['email']);

    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(["error" => "El email ya está registrado"], 400);
    }

    $conn->begin_transaction();

    try {
        $restaurantName = sanitizeInput($data['restaurant_name']);
        $phone = isset($data['phone']) ? sanitizeInput($data['phone']) : '';

        $stmt = $conn->prepare("INSERT INTO restaurantes (nombre, telefono, email) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $restaurantName, $phone, $email);
        $stmt->execute();
        $restaurantId = $conn->insert_id;

        $name = sanitizeInput($data['name']);
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        $role = 'admin';

        $stmt = $conn->prepare("INSERT INTO usuarios (restaurante_id, email, contrasena_hash, nombre, rol) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $restaurantId, $email, $passwordHash, $name, $role);
        $stmt->execute();
        $userId = $conn->insert_id;

        // Crear menú por defecto
        $stmt = $conn->prepare("INSERT INTO menus (restaurante_id, nombre, descripcion) VALUES (?, 'Menú Principal', 'Menú principal del restaurante')");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $menuId = $conn->insert_id;

        $defaultCategories = ['Entradas', 'Platos Fuertes', 'Postres', 'Bebidas'];
        foreach ($defaultCategories as $category) {
            $order = array_search($category, $defaultCategories) + 1;
            $stmt = $conn->prepare("INSERT INTO categorias_menu (menu_id, nombre, orden) VALUES (?, ?, ?)");
            $stmt->bind_param("isi", $menuId, $category, $order);
            $stmt->execute();
        }

        $conn->commit();

        $token = base64_encode(json_encode([
            'id' => $userId,
            'email' => $email,
            'nombre' => $name,
            'rol' => $role,
            'restaurante_id' => $restaurantId,
            'exp' => time() + (24 * 60 * 60)
        ]));

        jsonResponse([
            "success" => true,
            "message" => "Registro exitoso",
            "token" => $token,
            "user" => [
                "id" => $userId,
                "email" => $email,
                "name" => $name,
                "role" => $role,
                "restaurant_id" => $restaurantId,
                "restaurant_name" => $restaurantName
            ]
        ], 201);
        
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(["error" => "Error en el registro: " . $e->getMessage()], 500);
    }
}
?>
