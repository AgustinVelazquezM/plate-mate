<?php
// test_create_order.php - Archivo de prueba para crear pedidos
require_once 'config.php';

header('Content-Type: application/json');

// Obtener el token de la sesión actual
$headers = getallheaders();
$auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';
$token = str_replace('Bearer ', '', $auth);

echo json_encode([
    'step' => 1,
    'message' => 'Iniciando prueba',
    'token_received' => !empty($token)
], JSON_PRETTY_PRINT);
echo "\n\n";

// Validar token
$user = validateToken();
echo json_encode([
    'step' => 2,
    'message' => 'Token validado',
    'user' => $user
], JSON_PRETTY_PRINT);
echo "\n\n";

// Datos de prueba
$testData = [
    "tipo" => "mesa",
    "numero_mesa" => "5",
    "cliente_nombre" => "Cliente Test",
    "telefono_cliente" => "555-1234",
    "metodo_pago" => "efectivo",
    "detalles" => [
        [
            "item_id" => 1,
            "cantidad" => 2,
            "precio_unitario" => 85.00
        ]
    ]
];

echo json_encode([
    'step' => 3,
    'message' => 'Datos de prueba',
    'data' => $testData
], JSON_PRETTY_PRINT);
echo "\n\n";

$conn = getConnection();

// Verificar estructura de la tabla pedidos
$result = $conn->query("DESCRIBE pedidos");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

echo json_encode([
    'step' => 4,
    'message' => 'Estructura de tabla pedidos',
    'columns' => $columns
], JSON_PRETTY_PRINT);
echo "\n\n";

// Intentar insertar un pedido
$restauranteId = $user->restaurante_id ?? 1;
$usuarioId = $user->id ?? 1;
$numeroPedido = 'TEST-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
$subtotal = 170;
$iva = 27.2;
$total = 197.2;
$estado = 'pendiente';
$tipo = 'mesa';
$numeroMesa = '5';
$telefonoCliente = '555-1234';
$notas = '';
$descuento = 0;
$metodoPago = 'efectivo';
$fechaPedido = date('Y-m-d H:i:s');

// Construir consulta dinámica según las columnas existentes
$insertFields = [];
$values = [];
$types = "";
$params = [];

if (in_array('restaurante_id', $columns)) {
    $insertFields[] = 'restaurante_id';
    $values[] = '?';
    $types .= 'i';
    $params[] = $restauranteId;
}
if (in_array('usuario_id', $columns)) {
    $insertFields[] = 'usuario_id';
    $values[] = '?';
    $types .= 'i';
    $params[] = $usuarioId;
}
if (in_array('numero_pedido', $columns)) {
    $insertFields[] = 'numero_pedido';
    $values[] = '?';
    $types .= 's';
    $params[] = $numeroPedido;
}
if (in_array('estado', $columns)) {
    $insertFields[] = 'estado';
    $values[] = '?';
    $types .= 's';
    $params[] = $estado;
}
if (in_array('tipo', $columns)) {
    $insertFields[] = 'tipo';
    $values[] = '?';
    $types .= 's';
    $params[] = $tipo;
}
if (in_array('numero_mesa', $columns)) {
    $insertFields[] = 'numero_mesa';
    $values[] = '?';
    $types .= 's';
    $params[] = $numeroMesa;
}
if (in_array('telefono_cliente', $columns)) {
    $insertFields[] = 'telefono_cliente';
    $values[] = '?';
    $types .= 's';
    $params[] = $telefonoCliente;
}
if (in_array('notas', $columns)) {
    $insertFields[] = 'notas';
    $values[] = '?';
    $types .= 's';
    $params[] = $notas;
}
if (in_array('subtotal', $columns)) {
    $insertFields[] = 'subtotal';
    $values[] = '?';
    $types .= 'd';
    $params[] = $subtotal;
}
if (in_array('descuento', $columns)) {
    $insertFields[] = 'descuento';
    $values[] = '?';
    $types .= 'd';
    $params[] = $descuento;
}
if (in_array('iva', $columns)) {
    $insertFields[] = 'iva';
    $values[] = '?';
    $types .= 'd';
    $params[] = $iva;
}
if (in_array('total', $columns)) {
    $insertFields[] = 'total';
    $values[] = '?';
    $types .= 'd';
    $params[] = $total;
}
if (in_array('metodo_pago', $columns)) {
    $insertFields[] = 'metodo_pago';
    $values[] = '?';
    $types .= 's';
    $params[] = $metodoPago;
}
if (in_array('fecha_pedido', $columns)) {
    $insertFields[] = 'fecha_pedido';
    $values[] = '?';
    $types .= 's';
    $params[] = $fechaPedido;
}

$sql = "INSERT INTO pedidos (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $values) . ")";

echo json_encode([
    'step' => 5,
    'message' => 'SQL generada',
    'sql' => $sql,
    'types' => $types,
    'params_count' => count($params)
], JSON_PRETTY_PRINT);
echo "\n\n";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'step' => 6,
        'error' => 'Error preparando consulta',
        'sql_error' => $conn->error
    ], JSON_PRETTY_PRINT);
    exit();
}

$stmt->bind_param($types, ...$params);
if ($stmt->execute()) {
    $pedidoId = $conn->insert_id;
    echo json_encode([
        'step' => 7,
        'success' => true,
        'message' => 'Pedido creado exitosamente',
        'pedido_id' => $pedidoId,
        'numero_pedido' => $numeroPedido
    ], JSON_PRETTY_PRINT);
    
    // Insertar detalle
    $stmt2 = $conn->prepare("
        INSERT INTO detalles_pedido (pedido_id, item_id, cantidad, precio_unitario, subtotal)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt2->bind_param("iiidd", $pedidoId, 1, 2, 85.00, 170.00);
    if ($stmt2->execute()) {
        echo "\n\n" . json_encode([
            'step' => 8,
            'success' => true,
            'message' => 'Detalle insertado correctamente'
        ], JSON_PRETTY_PRINT);
    } else {
        echo "\n\n" . json_encode([
            'step' => 8,
            'error' => 'Error insertando detalle',
            'sql_error' => $stmt2->error
        ], JSON_PRETTY_PRINT);
    }
} else {
    echo json_encode([
        'step' => 7,
        'error' => 'Error ejecutando consulta',
        'sql_error' => $stmt->error
    ], JSON_PRETTY_PRINT);
}

$conn->close();
?>