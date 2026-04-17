<?php
// test_orders_debug.php - Archivo para depurar la creación de pedidos
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

header('Content-Type: application/json');

echo "=== INICIO DE DEPURACIÓN ===\n\n";

// 1. Verificar conexión a BD
echo "1. Probando conexión a BD...\n";
try {
    $conn = getConnection();
    echo "✅ Conexión exitosa\n\n";
} catch (Exception $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
    exit();
}

// 2. Verificar que existe la tabla pedidos
echo "2. Verificando tabla pedidos...\n";
$result = $conn->query("SHOW TABLES LIKE 'pedidos'");
if ($result->num_rows > 0) {
    echo "✅ Tabla pedidos existe\n\n";
} else {
    echo "❌ Tabla pedidos NO existe\n\n";
}

// 3. Verificar que existe al menos un ítem
echo "3. Verificando ítems disponibles...\n";
$result = $conn->query("SELECT id, nombre, precio_base FROM items_menu LIMIT 5");
if ($result->num_rows > 0) {
    echo "✅ Ítems encontrados:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   - ID: {$row['id']}, Nombre: {$row['nombre']}, Precio: {$row['precio_base']}\n";
    }
    echo "\n";
} else {
    echo "❌ No hay ítems en la base de datos\n\n";
}

// 4. Verificar estructura de la tabla pedidos
echo "4. Verificando estructura de la tabla pedidos...\n";
$result = $conn->query("DESCRIBE pedidos");
echo "Campos de la tabla pedidos:\n";
while ($row = $result->fetch_assoc()) {
    echo "   - {$row['Field']} ({$row['Type']})\n";
}
echo "\n";

// 5. Probar creación de pedido simple (CORREGIDO)
echo "5. Probando creación de pedido simple...\n";

$testData = [
    "tipo" => "mesa",
    "numero_mesa" => "5",
    "cliente_nombre" => "Cliente Test",
    "telefono_cliente" => "555-1234",
    "metodo_pago" => "efectivo",
    "notas" => "",
    "detalles" => [
        [
            "item_id" => 1,
            "cantidad" => 2,
            "precio_unitario" => 85.00,
            "notas" => ""
        ]
    ]
];

echo "Datos de prueba:\n";
echo json_encode($testData, JSON_PRETTY_PRINT) . "\n\n";

// Simular la creación de pedido
$conn->begin_transaction();

try {
    $restauranteId = 1;
    $usuarioId = 1;
    $numeroPedido = 'TEST-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    $subtotal = 0;
    
    foreach ($testData['detalles'] as $detalle) {
        $stmt = $conn->prepare("SELECT precio_base FROM items_menu WHERE id = ?");
        $stmt->bind_param("i", $detalle['item_id']);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        if (!$item) {
            throw new Exception("Ítem no encontrado: ID " . $detalle['item_id']);
        }
        $precioUnitario = $detalle['precio_unitario'] ?? $item['precio_base'];
        $subtotal += $precioUnitario * $detalle['cantidad'];
    }
    
    $iva = $subtotal * 0.16;
    $total = $subtotal + $iva;
    
    $estado = 'pendiente';
    $tipo = $testData['tipo'];
    $numeroMesa = $testData['numero_mesa'];
    $telefonoCliente = $testData['telefono_cliente'];
    $notas = $testData['notas'];
    $descuento = 0;
    $metodoPago = $testData['metodo_pago'];
    
    // CORRECCIÓN: La consulta SQL debe coincidir exactamente con los campos de la tabla
    // Según tu DESCRIBE, los campos son: id, restaurante_id, usuario_id, numero_pedido, estado, tipo,
    // numero_mesa, direccion_entrega, telefono_cliente, notas, subtotal, descuento, iva, total, 
    // metodo_pago, fecha_pedido, fecha_entrega_estimada, fecha_entrega_real, created_at
    
    $stmt = $conn->prepare("
        INSERT INTO pedidos (
            restaurante_id, usuario_id, numero_pedido, estado, tipo,
            numero_mesa, direccion_entrega, telefono_cliente, notas,
            subtotal, descuento, iva, total, metodo_pago, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    // CORRECCIÓN: El número de parámetros debe coincidir con los campos
    // 14 campos = 14 signos de interrogación = 14 variables en bind_param
    $direccionEntrega = null; // Campo obligatorio en la estructura pero puede ser NULL
    
    $stmt->bind_param(
        "iissssssddddss",  // 14 tipos: i,i,s,s,s,s,s,s,d,d,d,d,s,s
        $restauranteId,    // i - 1
        $usuarioId,        // i - 2
        $numeroPedido,     // s - 3
        $estado,           // s - 4
        $tipo,             // s - 5
        $numeroMesa,       // s - 6
        $direccionEntrega, // s - 7
        $telefonoCliente,  // s - 8
        $notas,            // s - 9
        $subtotal,         // d - 10
        $descuento,        // d - 11
        $iva,              // d - 12
        $total,            // d - 13
        $metodoPago        // s - 14
    );
    
    if ($stmt->execute()) {
        $pedidoId = $conn->insert_id;
        echo "✅ Pedido insertado con ID: $pedidoId\n";
        
        // Insertar detalle
        $detalle = $testData['detalles'][0];
        $detalleSubtotal = $detalle['precio_unitario'] * $detalle['cantidad'];
        
        $stmt = $conn->prepare("
            INSERT INTO detalles_pedido (pedido_id, item_id, cantidad, precio_unitario, subtotal, notas)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiidds", $pedidoId, $detalle['item_id'], $detalle['cantidad'], $detalle['precio_unitario'], $detalleSubtotal, null);
        
        if ($stmt->execute()) {
            echo "✅ Detalle insertado correctamente\n";
            $conn->commit();
            echo "\n🎉 PEDIDO CREADO EXITOSAMENTE!\n";
            echo "ID: $pedidoId\n";
            echo "Número: $numeroPedido\n";
            echo "Subtotal: $$subtotal\n";
            echo "IVA: $$iva\n";
            echo "Total: $$total\n";
        } else {
            throw new Exception("Error insertando detalle: " . $stmt->error);
        }
    } else {
        throw new Exception("Error insertando pedido: " . $stmt->error);
    }
    
} catch (Exception $e) {
    $conn->rollback();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

$conn->close();
?>