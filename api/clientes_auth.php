<?php
require_once 'config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($method === 'POST') {
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
        default:
            jsonResponse(["error" => "Acción no válida"], 400);
    }
} else {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
}

function handleLogin($data) {
    if (!isset($data['email']) || !isset($data['password'])) {
        jsonResponse(["error" => "Email y contraseña requeridos"], 400);
    }
    
    $conn = getConnection();
    $email = sanitizeInput($data['email']);
    $password = $data['password'];
    
    $stmt = $conn->prepare("SELECT * FROM clientes_usuarios WHERE email = ?");
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
            'tipo' => 'cliente',
            'exp' => time() + (24 * 60 * 60)
        ]));
        
        jsonResponse([
            "success" => true,
            "token" => $token,
            "user" => [
                "id" => $user['id'],
                "email" => $user['email'],
                "name" => $user['nombre'],
                "edad" => $user['edad'],
                "peso" => $user['peso'],
                "altura" => $user['altura'],
                "objetivo_calorias" => $user['objetivo_calorias_diarias']
            ]
        ]);
    } else {
        jsonResponse(["error" => "Contraseña incorrecta"], 401);
    }
}

function handleRegister($data) {
    if (!isset($data['email']) || !isset($data['password']) || !isset($data['name'])) {
        jsonResponse(["error" => "Datos incompletos"], 400);
    }
    
    $conn = getConnection();
    $email = sanitizeInput($data['email']);
    
    $stmt = $conn->prepare("SELECT id FROM clientes_usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(["error" => "El email ya está registrado"], 400);
    }
    
    $name = sanitizeInput($data['name']);
    $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
    $edad = isset($data['edad']) ? intval($data['edad']) : null;
    $peso = isset($data['peso']) ? floatval($data['peso']) : null;
    $altura = isset($data['altura']) ? floatval($data['altura']) : null;
    $objetivo = isset($data['objetivo_calorias']) ? intval($data['objetivo_calorias']) : 2000;
    
    $stmt = $conn->prepare("
        INSERT INTO clientes_usuarios (email, contrasena_hash, nombre, edad, peso, altura, objetivo_calorias_diarias)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssiddi", $email, $passwordHash, $name, $edad, $peso, $altura, $objetivo);
    
    if ($stmt->execute()) {
        $userId = $conn->insert_id;
        
        jsonResponse([
            "success" => true,
            "message" => "Registro exitoso",
            "user" => [
                "id" => $userId,
                "email" => $email,
                "name" => $name
            ]
        ], 201);
    } else {
        jsonResponse(["error" => "Error al registrar: " . $conn->error], 500);
    }
}
?>