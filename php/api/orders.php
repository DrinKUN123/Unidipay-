<?php
/**
 * Orders API
 * Manage orders: create, read, update status
 */

require_once '../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'all') {
                getAllOrders();
            } elseif ($action === 'single' && isset($_GET['id'])) {
                getOrder($_GET['id']);
            } elseif ($action === 'stats') {
                getOrderStats();
            } else {
                getAllOrders();
            }
            break;
            
        case 'POST':
            if ($action === 'additem' && isset($_GET['id'])) {
                addItemToOrder($_GET['id']);
            } elseif ($action === 'create') {
                createOrder();
            } else {
                createOrder();
            }
            break;
            
        case 'PUT':
            if ($action === 'status' && isset($_GET['id'])) {
                updateOrderStatus($_GET['id']);
            } elseif ($action === 'edit' && isset($_GET['id'])) {
                editOrder($_GET['id']);
            } else {
                sendResponse(['error' => 'Invalid action'], 400);
            }
            break;

        case 'DELETE':
            if ($action === 'deleteitem' && isset($_GET['id']) && isset($_GET['item_id'])) {
                deleteItemFromOrder($_GET['id'], $_GET['item_id']);
            } elseif ($action === 'delete' && isset($_GET['id'])) {
                deleteOrder($_GET['id']);
            } else {
                sendResponse(['error' => 'Invalid action'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

/**
 * Get all orders with optional status filter
 */
function getAllOrders() {
    global $pdo;
    
    $status = $_GET['status'] ?? null;
    
    if ($status) {
        $stmt = $pdo->prepare("
            SELECT o.*, s.name as student_name, s.student_id
            FROM orders o
            JOIN students s ON o.student_id = s.id
            WHERE o.status = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([sanitize($status)]);
    } else {
        $stmt = $pdo->query("
            SELECT o.*, s.name as student_name, s.student_id
            FROM orders o
            JOIN students s ON o.student_id = s.id
            ORDER BY o.created_at DESC
        ");
    }
    
    $orders = $stmt->fetchAll();
    
    // Get items for each order
    foreach ($orders as &$order) {
        $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$order['id']]);
        $order['items'] = $stmt->fetchAll();
    }
    
    sendResponse(['orders' => $orders]);
}

/**
 * Get single order with items
 */
function getOrder($id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT o.*, s.name as student_name, s.student_id
        FROM orders o
        JOIN students s ON o.student_id = s.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        sendResponse(['error' => 'Order not found'], 404);
    }
    
    // Get order items
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt->execute([$id]);
    $order['items'] = $stmt->fetchAll();
    
    sendResponse(['order' => $order]);
}

/**
 * Get order statistics
 */
function getOrderStats() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'ready' THEN 1 ELSE 0 END) as ready,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) IN ('cancelled', 'canceled', 'cancel', 'void', 'voided', '') THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'completed' THEN total ELSE 0 END) as total_sales
        FROM orders
    ");
    
    $stats = $stmt->fetch();
    
    sendResponse(['stats' => $stats]);
}

/**
 * Create new order with payment
 */
function createOrder() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = validateRequired($data, ['student_id', 'nfc_card_id', 'items', 'total']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    $studentId = intval($data['student_id']);
    $nfcCardId = sanitize($data['nfc_card_id']);
    $items = $data['items'];
    $total = floatval($data['total']);
    
    if (empty($items)) {
        sendResponse(['error' => 'Order must have at least one item'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check card balance
        $stmt = $pdo->prepare("SELECT balance FROM nfc_cards WHERE id = ? FOR UPDATE");
        $stmt->execute([$nfcCardId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception('RFID Card not found');
        }
        
        if ($card['balance'] < $total) {
            throw new Exception('Insufficient balance');
        }
        
        // Create order
        $stmt = $pdo->prepare("
            INSERT INTO orders (student_id, nfc_card_id, total, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$studentId, $nfcCardId, $total]);
        $orderId = $pdo->lastInsertId();
        
        // Insert order items
        $stmt = $pdo->prepare("
            INSERT INTO order_items (order_id, menu_item_id, name, price, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($items as $item) {
            $stmt->execute([
                $orderId,
                $item['id'],
                $item['name'],
                $item['price'],
                $item['quantity'],
                $item['price'] * $item['quantity']
            ]);
        }
        
        // Deduct balance
        $balanceBefore = $card['balance'];
        $balanceAfter = $balanceBefore - $total;
        
        $stmt = $pdo->prepare("UPDATE nfc_cards SET balance = ? WHERE id = ?");
        $stmt->execute([$balanceAfter, $nfcCardId]);
        
        // Record transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (card_id, type, amount, reason, order_id, balance_before, balance_after)
            VALUES (?, 'debit', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $nfcCardId,
            $total,
            "Order #$orderId",
            $orderId,
            $balanceBefore,
            $balanceAfter
        ]);
        
        $pdo->commit();
        
        // Get created order
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        sendResponse([
            'success' => true,
            'order' => $order,
            'new_balance' => $balanceAfter
        ], 201);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => $e->getMessage()], 400);
    }
}

/**
 * Update order status
 */
function updateOrderStatus($id) {
    global $pdo;
    
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['status'])) {
        sendResponse(['error' => 'Status is required'], 400);
    }
    
    $status = sanitize($data['status']);
    $validStatuses = ['pending', 'processing', 'ready', 'completed', 'cancelled', 'canceled'];
    
    if (!in_array($status, $validStatuses)) {
        sendResponse(['error' => 'Invalid status'], 400);
    }

    if (isOrderCancelled($id)) {
        sendResponse(['error' => 'Cancelled orders are read-only'], 400);
    }
    
    // Ensure order exists before update
    $existsStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? LIMIT 1");
    $existsStmt->execute([$id]);
    if (!$existsStmt->fetch()) {
        sendResponse(['error' => 'Order not found'], 404);
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    
    // If completed, set completed_at only when the column exists
    if ($status === 'completed') {
        $columnCheck = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'completed_at'");
        $columnCheck->execute();
        if ($columnCheck->fetch()) {
            $stmt = $pdo->prepare("UPDATE orders SET completed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
        }
    }
    
    // Get updated order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    
    sendResponse([
        'success' => true,
        'order' => $order
    ]);
}

/**
 * Edit order - update payment method and status
 */
function editOrder($id) {
    global $pdo;
    
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }
    
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    // Guard against invalid JSON payloads to avoid warnings and broken responses
    if (!is_array($data)) {
        sendResponse(['error' => 'Invalid JSON payload'], 400);
    }
    
    $paymentMethod = $data['payment_method'] ?? null;
    $status = isset($data['status']) ? sanitize($data['status']) : null;
    
    $validStatuses = ['pending', 'processing', 'ready', 'completed', 'cancelled', 'canceled'];
    $validPaymentMethods = ['nfc', 'cash'];
    
    if ($status && !in_array($status, $validStatuses)) {
        sendResponse(['error' => 'Invalid status'], 400);
    }
    
    if ($paymentMethod && !in_array($paymentMethod, $validPaymentMethods)) {
        sendResponse(['error' => 'Invalid payment method'], 400);
    }

    if (isOrderCancelled($id)) {
        sendResponse(['error' => 'Cancelled orders are read-only'], 400);
    }
    
    try {
        // Ensure order exists before attempting update
        $existsStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? LIMIT 1");
        $existsStmt->execute([$id]);
        if (!$existsStmt->fetch()) {
            sendResponse(['error' => 'Order not found'], 404);
        }

        $updates = [];
        $params = [];

        if ($status) {
            $updates[] = "status = ?";
            $params[] = $status;
            
            // If completing, set timestamp only when column exists
            if ($status === 'completed') {
                $columnCheck = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'completed_at'");
                $columnCheck->execute();
                if ($columnCheck->fetch()) {
                    $updates[] = "completed_at = NOW()";
                }
            }
        }
        
        // Update payment method only if the column exists to preserve schema compatibility
        if ($paymentMethod) {
            $columnCheck = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'payment_method'");
            $columnCheck->execute();
            if ($columnCheck->fetch()) {
                $updates[] = "payment_method = ?";
                $params[] = sanitize($paymentMethod);
            }
        }
        
        if (empty($updates)) {
            sendResponse(['error' => 'No updates provided'], 400);
        }
        
        $params[] = $id;
        $query = "UPDATE orders SET " . implode(", ", $updates) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        // Get updated order
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        
        sendResponse([
            'success' => true,
            'message' => 'Order updated successfully',
            'order' => $order
        ]);
        
    } catch (Exception $e) {
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

/**
 * Add item to existing order
 */
function addItemToOrder($orderId) {
    global $pdo;
    
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $menuItemId = $data['menu_item_id'] ?? null;
    $name = $data['name'] ?? null;
    $price = floatval($data['price'] ?? 0);
    $quantity = intval($data['quantity'] ?? 1);
    $subtotal = floatval($data['subtotal'] ?? 0);
    
    if (!$menuItemId || !$name) {
        sendResponse(['error' => 'Missing required fields'], 400);
    }

    if (isOrderCancelled($orderId)) {
        sendResponse(['error' => 'Cancelled orders are read-only'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Insert order item
        $stmt = $pdo->prepare("
            INSERT INTO order_items (order_id, menu_item_id, name, price, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId, $menuItemId, $name, $price, $quantity, $subtotal]);
        
        // Update order total
        $stmt = $pdo->prepare("UPDATE orders SET total = total + ? WHERE id = ?");
        $stmt->execute([$subtotal, $orderId]);
        
        $pdo->commit();
        
        sendResponse([
            'success' => true,
            'message' => 'Item added to order'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

/**
 * Delete item from order
 */
function deleteItemFromOrder($orderId, $itemId) {
    global $pdo;
    
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }

    if (isOrderCancelled($orderId)) {
        sendResponse(['error' => 'Cancelled orders are read-only'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get item subtotal
        $stmt = $pdo->prepare("SELECT subtotal FROM order_items WHERE id = ? AND order_id = ?");
        $stmt->execute([$itemId, $orderId]);
        $item = $stmt->fetch();
        
        if (!$item) {
            sendResponse(['error' => 'Item not found'], 404);
        }
        
        // Delete item
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE id = ? AND order_id = ?");
        $stmt->execute([$itemId, $orderId]);
        
        // Update order total
        $stmt = $pdo->prepare("UPDATE orders SET total = total - ? WHERE id = ?");
        $stmt->execute([$item['subtotal'], $orderId]);
        
        $pdo->commit();
        
        sendResponse([
            'success' => true,
            'message' => 'Item deleted from order'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

/**
 * Delete entire order
 */
function deleteOrder($id) {
    global $pdo;
    
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }

    if (isOrderCancelled($id)) {
        sendResponse(['error' => 'Cancelled orders are read-only'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Delete order items first (due to foreign key)
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$id]);
        
        // Delete order
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            sendResponse(['error' => 'Order not found'], 404);
        }
        
        $pdo->commit();
        
        sendResponse([
            'success' => true,
            'message' => 'Order deleted successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

/**
 * Returns true if order status should be treated as cancelled/read-only.
 */
function isOrderCancelled($orderId) {
    global $pdo;

    $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    $normalized = strtolower(trim((string)($row['status'] ?? '')));

    return $normalized === 'cancelled'
        || $normalized === 'canceled'
        || $normalized === 'cancel'
        || $normalized === 'void'
        || $normalized === 'voided'
        || $normalized === '';
}
?>
