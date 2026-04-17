<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

header('Content-Type: application/json');

$user = validateToken();
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($user->restaurante_id) || empty($user->restaurante_id)) {
    $user->restaurante_id = 1;
}

try {
    switch ($method) {
        case 'GET': 
            handleGet($user); 
            break;
        case 'POST': 
            handlePost($user); 
            break;
        case 'PUT': 
            handlePut($user); 
            break;
        case 'DELETE': 
            handleDelete($user); 
            break;
        default:
            http_response_code(405);
            echo json_encode(["error" => "Método no permitido"]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error en el servidor: " . $e->getMessage()]);
    exit();
}

function handleGet($user) {
    $conn = getConnection();
    $orderId = isset($_GET['id']) ? $_GET['id'] : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : null;
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : null;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    if ($orderId) {
        if (is_numeric($orderId)) {
            $stmt = $conn->prepare("
                SELECT p.*, u.nombre as usuario_nombre, u.email as usuario_email
                FROM pedidos p
                LEFT JOIN usuarios u ON p.usuario_id = u.id
                WHERE p.id = ? AND p.restaurante_id = ?
            ");
            if (!$stmt) {
                jsonResponse(["error" => "Error en consulta: " . $conn->error], 500);
            }
            $stmt->bind_param("ii", $orderId, $user->restaurante_id);
        } else {
            $stmt = $conn->prepare("
                SELECT p.*, u.nombre as usuario_nombre, u.email as usuario_email
                FROM pedidos p
                LEFT JOIN usuarios u ON p.usuario_id = u.id
                WHERE p.numero_pedido = ? AND p.restaurante_id = ?
            ");
            if (!$stmt) {
                jsonResponse(["error" => "Error en consulta: " . $conn->error], 500);
            }
            $stmt->bind_param("si", $orderId, $user->restaurante_id);
        }
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) {
            jsonResponse(["error" => "Pedido no encontrado"], 404);
        }

        $stmt = $conn->prepare("
            SELECT dp.*, i.nombre as item_nombre, i.descripcion as item_descripcion
            FROM detalles_pedido dp
            JOIN items_menu i ON dp.item_id = i.id
            WHERE dp.pedido_id = ?
        ");
        if (!$stmt) {
            jsonResponse(["error" => "Error en consulta de detalles: " . $conn->error], 500);
        }
        $stmt->bind_param("i", $order['id']);
        $stmt->execute();
        $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $order['detalles'] = $detalles;
        jsonResponse($order);
    } else {
        $sql = "
            SELECT p.id, p.restaurante_id, p.usuario_id, p.numero_pedido, p.estado, p.tipo,
                   p.numero_mesa, p.direccion_entrega, p.telefono_cliente, p.notas,
                   p.subtotal, p.descuento, p.iva, p.total, p.metodo_pago,
                   p.fecha_pedido,
                   COALESCE(u.nombre, p.telefono_cliente, 'Cliente') as usuario_nombre,
                   COUNT(dp.id) as num_items
            FROM pedidos p
            LEFT JOIN usuarios u ON p.usuario_id = u.id
            LEFT JOIN detalles_pedido dp ON p.id = dp.pedido_id
            WHERE p.restaurante_id = ?
        ";
        $params = [$user->restaurante_id];
        $types = "i";

        if ($status) {
            $sql .= " AND p.estado = ?";
            $types .= "s";
            $params[] = $status;
        }
        if ($dateFrom) {
            $sql .= " AND DATE(p.fecha_pedido) >= ?";
            $types .= "s";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND DATE(p.fecha_pedido) <= ?";
            $types .= "s";
            $params[] = $dateTo;
        }
        $sql .= " GROUP BY p.id ORDER BY p.fecha_pedido DESC LIMIT ? OFFSET ?";
        $types .= "ii";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            jsonResponse(["error" => "Error en consulta: " . $conn->error], 500);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $countSql = "SELECT COUNT(*) as total FROM pedidos WHERE restaurante_id = ?";
        $countParams = [$user->restaurante_id];
        $countTypes = "i";
        if ($status) {
            $countSql .= " AND estado = ?";
            $countTypes .= "s";
            $countParams[] = $status;
        }
        if ($dateFrom) {
            $countSql .= " AND DATE(fecha_pedido) >= ?";
            $countTypes .= "s";
            $countParams[] = $dateFrom;
        }
        if ($dateTo) {
            $countSql .= " AND DATE(fecha_pedido) <= ?";
            $countTypes .= "s";
            $countParams[] = $dateTo;
        }
        $stmt = $conn->prepare($countSql);
        if (!$stmt) {
            jsonResponse(["error" => "Error en consulta de conteo: " . $conn->error], 500);
        }
        $stmt->bind_param($countTypes, ...$countParams);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        jsonResponse([
            'orders' => $orders,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
}

function handlePost($user) {
    $input = file_get_contents("php://input");
    
    if (empty($input)) {
        jsonResponse(["error" => "No se recibieron datos"], 400);
    }
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(["error" => "JSON inválido: " . json_last_error_msg()], 400);
    }
    
    if (!isset($data['detalles']) || !is_array($data['detalles']) || empty($data['detalles'])) {
        jsonResponse(["error" => "El pedido debe contener al menos un ítem"], 400);
    }

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $restauranteId = isset($user->restaurante_id) ? $user->restaurante_id : 1;
        $numeroPedido = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $subtotal = 0;
        $detallesProcesados = [];

        foreach ($data['detalles'] as $index => $detalle) {
            if (!isset($detalle['item_id']) || empty($detalle['item_id'])) {
                throw new Exception("Ítem #" . ($index + 1) . ": ID de ítem no especificado");
            }
            
            $stmt = $conn->prepare("SELECT id, nombre, precio_base FROM items_menu WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Error preparando consulta de ítem: " . $conn->error);
            }
            $stmt->bind_param("i", $detalle['item_id']);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            
            if (!$item) {
                throw new Exception("Ítem no encontrado: ID " . $detalle['item_id']);
            }
            
            $precioUnitario = isset($detalle['precio_unitario']) ? floatval($detalle['precio_unitario']) : floatval($item['precio_base']);
            $cantidad = isset($detalle['cantidad']) ? intval($detalle['cantidad']) : 1;
            
            if ($cantidad <= 0) {
                throw new Exception("La cantidad debe ser mayor a 0 para el ítem: " . $item['nombre']);
            }
            
            $detalleSubtotal = $precioUnitario * $cantidad;
            $subtotal += $detalleSubtotal;
            
            $detallesProcesados[] = [
                'item_id' => $detalle['item_id'],
                'item_nombre' => $item['nombre'],
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'subtotal' => $detalleSubtotal,
                'notas' => isset($detalle['notas']) ? trim($detalle['notas']) : null
            ];
        }

        $iva = $subtotal * 0.16;
        $total = $subtotal + $iva;
        
        $usuarioId = isset($user->id) ? $user->id : 1;
        
        if (isset($data['cliente_nombre']) && !empty(trim($data['cliente_nombre']))) {
            $clienteNombre = trim($data['cliente_nombre']);
            $telefonoCliente = isset($data['telefono_cliente']) ? trim($data['telefono_cliente']) : null;
            
            if ($telefonoCliente) {
                $stmt = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ? AND rol = 'cliente' LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $telefonoCliente);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    if ($existing) {
                        $usuarioId = $existing['id'];
                    }
                }
            }
        }

        $notasGenerales = isset($data['notas']) ? trim($data['notas']) : null;
        $itemsNotas = [];
        foreach ($detallesProcesados as $detalle) {
            if (!empty($detalle['notas'])) {
                $itemsNotas[] = $detalle['item_nombre'] . ": " . $detalle['notas'];
            }
        }
        
        if (!empty($itemsNotas) && $notasGenerales) {
            $notasFinales = $notasGenerales . "\n\n--- Notas por ítem ---\n" . implode("\n", $itemsNotas);
        } elseif (!empty($itemsNotas)) {
            $notasFinales = "Notas por ítem:\n" . implode("\n", $itemsNotas);
        } else {
            $notasFinales = $notasGenerales;
        }

        $estado = isset($data['estado']) ? $data['estado'] : 'pendiente';
        $tipo = isset($data['tipo']) ? $data['tipo'] : 'mesa';
        $numeroMesa = isset($data['numero_mesa']) && !empty($data['numero_mesa']) ? $data['numero_mesa'] : null;
        $direccionEntrega = isset($data['direccion_entrega']) && !empty($data['direccion_entrega']) ? $data['direccion_entrega'] : null;
        $telefonoCliente = isset($data['telefono_cliente']) && !empty($data['telefono_cliente']) ? $data['telefono_cliente'] : null;
        $descuento = isset($data['descuento']) ? floatval($data['descuento']) : 0;
        $metodoPago = isset($data['metodo_pago']) ? $data['metodo_pago'] : 'efectivo';

        // Insertar pedido con los campos correctos de tu tabla
        $stmt = $conn->prepare("
            INSERT INTO pedidos (
                restaurante_id, usuario_id, numero_pedido, estado, tipo,
                numero_mesa, direccion_entrega, telefono_cliente, notas,
                subtotal, descuento, iva, total, metodo_pago, fecha_pedido
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            throw new Exception("Error preparando insert: " . $conn->error);
        }
        
        $stmt->bind_param(
            "iissssssddddss",
            $restauranteId,
            $usuarioId,
            $numeroPedido,
            $estado,
            $tipo,
            $numeroMesa,
            $direccionEntrega,
            $telefonoCliente,
            $notasFinales,
            $subtotal,
            $descuento,
            $iva,
            $total,
            $metodoPago
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Error al crear pedido: " . $stmt->error);
        }
        $pedidoId = $conn->insert_id;

        // Insertar detalles del pedido
        foreach ($detallesProcesados as $detalle) {
            $stmt = $conn->prepare("
                INSERT INTO detalles_pedido (pedido_id, item_id, cantidad, precio_unitario, subtotal, notas)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new Exception("Error preparando insert detalle: " . $conn->error);
            }
            $stmt->bind_param(
                "iiidds",
                $pedidoId,
                $detalle['item_id'],
                $detalle['cantidad'],
                $detalle['precio_unitario'],
                $detalle['subtotal'],
                $detalle['notas']
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error al insertar detalle del pedido: " . $stmt->error);
            }
        }

        $conn->commit();
        
        jsonResponse([
            "success" => true,
            "message" => "Pedido creado exitosamente",
            "pedido_id" => $pedidoId,
            "numero_pedido" => $numeroPedido,
            "total" => $total,
            "subtotal" => $subtotal,
            "iva" => $iva
        ], 201);
        
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(["error" => $e->getMessage()], 500);
    }
}

function handlePut($user) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['id'])) {
        jsonResponse(["error" => "ID del pedido requerido"], 400);
    }
    
    $conn = getConnection();
    $pedidoId = intval($data['id']);

    $stmt = $conn->prepare("SELECT id, estado FROM pedidos WHERE id = ? AND restaurante_id = ?");
    if (!$stmt) {
        jsonResponse(["error" => "Error en consulta: " . $conn->error], 500);
    }
    $stmt->bind_param("ii", $pedidoId, $user->restaurante_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        jsonResponse(["error" => "Pedido no encontrado"], 404);
    }
    
    $pedidoActual = $result->fetch_assoc();
    $updates = [];
    $params = [];
    $types = "";
    
    $fields = [
        'estado' => 's', 'tipo' => 's', 'numero_mesa' => 's',
        'direccion_entrega' => 's', 'telefono_cliente' => 's', 'notas' => 's',
        'descuento' => 'd', 'metodo_pago' => 's'
    ];
    
    foreach ($fields as $field => $type) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
            $types .= $type;
        }
    }
    
    if (empty($updates)) {
        jsonResponse(["error" => "No hay datos para actualizar"], 400);
    }
    
    $types .= "ii";
    $params[] = $pedidoId;
    $params[] = $user->restaurante_id;

    $sql = "UPDATE pedidos SET " . implode(', ', $updates) . " WHERE id = ? AND restaurante_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(["error" => "Error preparando actualización: " . $conn->error], 500);
    }
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        if (isset($data['descuento'])) {
            $stmt = $conn->prepare("
                UPDATE pedidos p
                SET p.subtotal = (SELECT COALESCE(SUM(subtotal), 0) FROM detalles_pedido WHERE pedido_id = p.id),
                    p.iva = p.subtotal * 0.16,
                    p.total = p.subtotal + p.iva - p.descuento
                WHERE p.id = ?
            ");
            if ($stmt) {
                $stmt->bind_param("i", $pedidoId);
                $stmt->execute();
            }
        }
        
        jsonResponse(["success" => true, "message" => "Pedido actualizado correctamente"]);
    } else {
        jsonResponse(["error" => "Error al actualizar el pedido: " . $stmt->error], 500);
    }
}
function handleDelete($user) {
    $pedidoId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if (!$pedidoId) {
        jsonResponse(["error" => "ID del pedido requerido"], 400);
    }
    
    $conn = getConnection();

    // Verificar que el pedido pertenece al restaurante
    $stmt = $conn->prepare("SELECT id, estado, numero_pedido FROM pedidos WHERE id = ? AND restaurante_id = ?");
    if (!$stmt) {
        jsonResponse(["error" => "Error en consulta: " . $conn->error], 500);
    }
    $stmt->bind_param("ii", $pedidoId, $user->restaurante_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        jsonResponse(["error" => "Pedido no encontrado"], 404);
    }
    
    $pedido = $result->fetch_assoc();
    
    // Iniciar transacción para eliminar también los detalles
    $conn->begin_transaction();
    
    try {
        // Primero eliminar los detalles del pedido
        $stmt = $conn->prepare("DELETE FROM detalles_pedido WHERE pedido_id = ?");
        $stmt->bind_param("i", $pedidoId);
        if (!$stmt->execute()) {
            throw new Exception("Error al eliminar detalles: " . $stmt->error);
        }
        
        // Luego eliminar el pedido
        $stmt = $conn->prepare("DELETE FROM pedidos WHERE id = ?");
        $stmt->bind_param("i", $pedidoId);
        if (!$stmt->execute()) {
            throw new Exception("Error al eliminar pedido: " . $stmt->error);
        }
        
        $conn->commit();
        
        // Registrar el cambio si la función existe
        if (function_exists('registrarCambio')) {
            registrarCambio($conn, 'pedidos', $pedidoId, $user->id, 'eliminacion', "Pedido eliminado permanentemente: " . $pedido['numero_pedido']);
        }
        
        jsonResponse(["success" => true, "message" => "Pedido eliminado permanentemente"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(["error" => "Error al eliminar: " . $e->getMessage()], 500);
    }
}