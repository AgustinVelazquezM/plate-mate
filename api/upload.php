<?php
require_once 'config.php';

$user = validateToken();
if ($user->rol !== 'admin' && $user->rol !== 'manager') {
    jsonResponse(["error" => "No autorizado"], 403);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(["error" => "No se recibió archivo"], 400);
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($_FILES['image']['type'], $allowedTypes)) {
    jsonResponse(["error" => "Tipo de archivo no permitido"], 400);
}

if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
    jsonResponse(["error" => "Archivo demasiado grande (máx 5MB)"], 400);
}

$uploadDir = '../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
$restaurantDir = $uploadDir . 'restaurant_' . $user->restaurante_id . '/';
if (!file_exists($restaurantDir)) {
    mkdir($restaurantDir, 0777, true);
}

$ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
$fileName = uniqid() . '_' . time() . '.' . $ext;
$filePath = $restaurantDir . $fileName;

if (move_uploaded_file($_FILES['image']['tmp_name'], $filePath)) {
    $imageUrl = 'uploads/restaurant_' . $user->restaurante_id . '/' . $fileName;
    jsonResponse(["success" => true, "image_url" => $imageUrl, "file_name" => $fileName]);
} else {
    jsonResponse(["error" => "Error al guardar archivo"], 500);
}
?>