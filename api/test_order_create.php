<?php
// test_order_create.php - Archivo de prueba para diagnosticar el error
require_once 'config.php';

header('Content-Type: application/json');

// Datos de prueba para crear un pedido
$testData = [
    "tipo" => "mesa",
    "numero_mesa" => "5",
    "cliente_nombre" => "Juan Pérez",
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

echo "=== DATOS DE PRUEBA ===\n";
echo json_encode($testData, JSON_PRETTY_PRINT);
echo "\n\n";

// Probar la conexión a la base de datos
echo "=== PROBANDO CONEXIÓN A BD ===\n";
$conn = getConnection();
echo "✅ Conexión exitosa\n\n";

// Probar que existe el ítem
echo "=== VERIFICANDO ÍTEM ID 1 ===\n";
$stmt = $conn->prepare("SELECT id, nombre, precio_base FROM items_menu WHERE id = ?");
$stmt->bind_param("i", $testData['detalles'][0]['item_id']);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
if ($item) {
    echo "✅ Ítem encontrado:\n";
    print_r($item);
} else {
    echo "❌ Ítem no encontrado\n";
}
echo "\n";

// Probar la función de creación de pedido directamente
echo "=== CREANDO PEDIDO DE PRUEBA ===\n";

$numeroPedido = 'TEST-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
$subtotal = 85.00 * 2;
$iva = $subtotal * 0.16;
$total = $subtotal + $iva;

echo "Número pedido: $numeroPedido\n";
echo "Subtotal: $subtotal\n";
echo "IVA: $iva\n";
echo "Total: $total\n\n";

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        INSERT INTO pedidos (
            restaurante_id, usuario_id, numero_pedido, estado, tipo,
            numero_mesa, telefono_cliente, notas,
            subtotal, descuento, iva, total, metodo_pago, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $restaurante_id = 1;
    $usuario_id = 1;
    $estado = 'pendiente';
    $tipo = 'mesa';
    $numero_mesa = '5';
    $telefono_cliente = '555-1234';
    $notas = '';
    $descuento = 0;
    $metodo_pago = 'efectivo';
    
    $stmt->bind_param(
        "iissssssddddss",
        $restaurante_id, $usuario_id, $numeroPedido, $estado, $tipo,
        $numero_mesa, $telefono_cliente, $notas,
        $subtotal, $descuento, $iva, $total, $metodo_pago
    );
    
    if ($stmt->execute()) {
        $pedidoId = $conn->insert_id;
        echo "✅ Pedido insertado con ID: $pedidoId\n";
        
        // Insertar detalle
        $stmt = $conn->prepare("
            INSERT INTO detalles_pedido (pedido_id, item_id, cantidad, precio_unitario, subtotal, notas)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiidds",
            $pedidoId,
            $testData['detalles'][0]['item_id'],
            $testData['detalles'][0]['cantidad'],
            $testData['detalles'][0]['precio_unitario'],
            $subtotal,
            null
        );
        
        if ($stmt->execute()) {
            echo "✅ Detalle insertado correctamente\n";
            $conn->commit();
            echo "\n🎉 PEDIDO CREADO EXITOSAMENTE!\n";
            echo "ID: $pedidoId\n";
            echo "Número: $numeroPedido\n";
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