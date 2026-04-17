<?php
require_once 'config.php';

header("Content-Type: application/json; charset=UTF-8");

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
    case 'request_reset':
        handleRequestReset($data);
        break;
    case 'reset_password':
        handleResetPassword($data);
        break;
    default:
        jsonResponse(["error" => "Acción no válida"], 400);
}

function handleRequestReset($data) {
    if (!isset($data['email'])) {
        jsonResponse(["error" => "Email requerido"], 400);
    }
    
    $conn = getConnection();
    $email = sanitizeInput($data['email']);
    $userType = isset($data['user_type']) ? $data['user_type'] : 'restaurante';
    
    // Determinar qué tabla usar
    $table = ($userType === 'cliente') ? 'clientes_usuarios' : 'usuarios';
    
    $stmt = $conn->prepare("SELECT id FROM $table WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Por seguridad, siempre devolvemos éxito aunque no exista
        jsonResponse(["success" => true, "message" => "Si el email existe, recibirás instrucciones"]);
    }
    
    // En un sistema real, aquí enviarías un email con un token
    // Para demo, simplemente simulamos éxito
    jsonResponse(["success" => true, "message" => "Email verificado"]);
}

function handleResetPassword($data) {
    if (!isset($data['email']) || !isset($data['new_password'])) {
        jsonResponse(["error" => "Email y nueva contraseña requeridos"], 400);
    }
    
    $conn = getConnection();
    $email = sanitizeInput($data['email']);
    $newPassword = $data['new_password'];
    $userType = isset($data['user_type']) ? $data['user_type'] : 'restaurante';
    
    if (strlen($newPassword) < 6) {
        jsonResponse(["error" => "La contraseña debe tener al menos 6 caracteres"], 400);
    }
    
    // Determinar qué tabla usar
    $table = ($userType === 'cliente') ? 'clientes_usuarios' : 'usuarios';
    
    $stmt = $conn->prepare("SELECT id FROM $table WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        jsonResponse(["error" => "Usuario no encontrado"], 404);
    }
    
    $user = $result->fetch_assoc();
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("UPDATE $table SET contrasena_hash = ? WHERE id = ?");
    $stmt->bind_param("si", $passwordHash, $user['id']);
    
    if ($stmt->execute()) {
        jsonResponse(["success" => true, "message" => "Contraseña actualizada"]);
    } else {
        jsonResponse(["error" => "Error al actualizar"], 500);
    }
}
?>