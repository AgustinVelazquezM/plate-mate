<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'plate_mate_db');

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(["error" => "Error de conexión: " . $conn->connect_error]);
        exit();
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

function validateToken() {
    $headers = getallheaders();
    
    // Buscar Authorization en diferentes formatos
    $authHeader = null;
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    }
    
    // Si no hay token, devolver usuario demo para pruebas
    if (!$authHeader) {
        error_log("No hay token, usando demo");
        return (object) [
            'id' => 1,
            'email' => 'demo@demo.com',
            'nombre' => 'Demo User',
            'rol' => 'admin',
            'restaurante_id' => 1
        ];
    }
    
    $token = str_replace('Bearer ', '', $authHeader);
    error_log("Token recibido: " . substr($token, 0, 50) . "...");
    
    // Decodificar token
    $decoded = json_decode(base64_decode($token), true);
    
    if (!$decoded) {
        error_log("Token decodificado es null");
        // Devolver demo en caso de error
        return (object) [
            'id' => 1,
            'email' => 'demo@demo.com',
            'nombre' => 'Demo User',
            'rol' => 'admin',
            'restaurante_id' => 1
        ];
    }
    
    error_log("Token decodificado: " . print_r($decoded, true));
    
    // Verificar expiración
    if (isset($decoded['exp']) && $decoded['exp'] < time()) {
        error_log("Token expirado");
        // Devolver demo en lugar de error para pruebas
        return (object) [
            'id' => 1,
            'email' => 'demo@demo.com',
            'nombre' => 'Demo User',
            'rol' => 'admin',
            'restaurante_id' => 1
        ];
    }
    
    // Asegurar que restaurante_id existe
    if (!isset($decoded['restaurante_id'])) {
        $decoded['restaurante_id'] = 1;
    }
    
    if (!isset($decoded['id'])) {
        $decoded['id'] = 1;
    }
    
    return (object) $decoded;
}

function jsonResponse($data, $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function registrarCambio($conn, $tabla, $registro_id, $usuario_id, $tipo, $descripcion) {
    $stmt = $conn->prepare("INSERT INTO registro_cambios (tabla_afectada, registro_id, usuario_id, tipo_cambio, descripcion) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("siiss", $tabla, $registro_id, $usuario_id, $tipo, $descripcion);
    $stmt->execute();
    return true;
}
?>