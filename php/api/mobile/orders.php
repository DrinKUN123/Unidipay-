<?php
require_once 'bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'POST' && $action === 'create') {
        createMobileOrder();
        exit;
    }

    if ($method === 'POST' && $action === 'cancel') {
        cancelMobileOrder();
        exit;
    }

    if ($method === 'GET' && $action === 'history') {
        getOrderHistory();
        exit;
    }

    sendResponse(['error' => 'Invalid action'], 400);
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

function createMobileOrder() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();
    $data = mobileJsonInput();

    $errors = validateRequired($data, ['items', 'total', 'order_type']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }

    $items = is_array($data['items']) ? $data['items'] : [];
    $total = floatval($data['total']);
    $orderType = sanitize($data['order_type']);

    if (empty($items)) {
        sendResponse(['error' => 'Order items are required'], 400);
    }

    if ($total <= 0) {
        sendResponse(['error' => 'Invalid order total'], 400);
    }

    if (!in_array($orderType, ['dine-in', 'take-out'])) {
        sendResponse(['error' => 'Invalid order type'], 400);
    }

    if (empty($student['nfc_card_id'])) {
        sendResponse(['error' => 'No RFID Card linked to this student'], 400);
    }

    $pdo->beginTransaction();

    try {
        $cardStmt = $pdo->prepare('SELECT balance FROM nfc_cards WHERE id = ? FOR UPDATE');
        $cardStmt->execute([$student['nfc_card_id']]);
        $card = $cardStmt->fetch();

        if (!$card) {
            throw new Exception('RFID Card not found');
        }

        $balanceBefore = floatval($card['balance']);
        if ($balanceBefore < $total) {
            throw new Exception('Insufficient balance. Current balance: PHP ' . number_format($balanceBefore, 2));
        }

        $balanceAfter = $balanceBefore - $total;

        $orderSql = 'INSERT INTO orders (student_id, nfc_card_id, total, status, created_at';
        $orderValuesSql = " VALUES (?, ?, ?, 'pending', NOW()";
        $orderParams = [intval($student['student_id']), $student['nfc_card_id'], $total];

        $hasOrderType = false;
        $checkOrderType = $pdo->query("SHOW COLUMNS FROM orders LIKE 'order_type'");
        if ($checkOrderType->fetch()) {
            $hasOrderType = true;
        }

        if ($hasOrderType) {
            $orderSql .= ', order_type';
            $orderValuesSql .= ', ?';
            $orderParams[] = $orderType;
        }

        $orderSql .= ')';
        $orderValuesSql .= ')';

        $orderStmt = $pdo->prepare($orderSql . $orderValuesSql);
        $orderStmt->execute($orderParams);
        $orderId = $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("\n            INSERT INTO order_items (order_id, menu_item_id, name, price, quantity, subtotal)\n            VALUES (?, ?, ?, ?, ?, ?)\n        ");

        foreach ($items as $item) {
            if (!isset($item['menu_item_id'], $item['name'], $item['price'], $item['quantity'])) {
                throw new Exception('Invalid item payload');
            }

            $price = floatval($item['price']);
            $quantity = intval($item['quantity']);
            $subtotal = isset($item['subtotal']) ? floatval($item['subtotal']) : ($price * $quantity);

            $itemStmt->execute([
                $orderId,
                intval($item['menu_item_id']),
                sanitize($item['name']),
                $price,
                $quantity,
                $subtotal
            ]);
        }

        $updStmt = $pdo->prepare('UPDATE nfc_cards SET balance = ?, updated_at = NOW() WHERE id = ?');
        $updStmt->execute([$balanceAfter, $student['nfc_card_id']]);

        $txnStmt = $pdo->prepare("\n            INSERT INTO transactions (card_id, type, amount, reason, order_id, balance_before, balance_after, created_at)\n            VALUES (?, 'debit', ?, ?, ?, ?, ?, NOW())\n        ");
        $txnStmt->execute([
            $student['nfc_card_id'],
            $total,
            'Mobile order #' . $orderId,
            $orderId,
            $balanceBefore,
            $balanceAfter
        ]);

        $pdo->commit();

        sendResponse([
            'success' => true,
            'message' => 'Order created successfully',
            'order_id' => intval($orderId),
            'order_number' => 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT),
            'new_balance' => $balanceAfter
        ], 201);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse(['error' => $e->getMessage()], 400);
    }
}

function getOrderHistory() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();

    $stmt = $pdo->prepare("\n        SELECT id, total, status, created_at\n        FROM orders\n        WHERE student_id = ?\n        ORDER BY created_at DESC\n        LIMIT 100\n    ");
    $stmt->execute([intval($student['student_id'])]);
    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {
        $itemsStmt = $pdo->prepare("\n            SELECT menu_item_id, name, price, quantity, subtotal\n            FROM order_items\n            WHERE order_id = ?\n            ORDER BY id ASC\n        ");
        $itemsStmt->execute([$order['id']]);
        $order['items'] = $itemsStmt->fetchAll();
    }

    sendResponse([
        'success' => true,
        'orders' => $orders
    ]);
}

function cancelMobileOrder() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();
    $data = mobileJsonInput();

    $orderId = intval($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        sendResponse(['error' => 'Valid order_id is required'], 400);
    }

    $pdo->beginTransaction();

    try {
        $orderStmt = $pdo->prepare('SELECT id, total, status, nfc_card_id FROM orders WHERE id = ? AND student_id = ? FOR UPDATE');
        $orderStmt->execute([$orderId, intval($student['student_id'])]);
        $order = $orderStmt->fetch();

        if (!$order) {
            throw new Exception('Order not found');
        }

        $currentStatus = strtolower(trim($order['status'] ?? ''));
        if ($currentStatus === 'cancelled' || $currentStatus === 'canceled') {
            throw new Exception('Order is already cancelled');
        }

        if ($currentStatus !== 'pending') {
            throw new Exception('Only pending orders can be cancelled');
        }

        $refundAmount = floatval($order['total']);
        if ($refundAmount <= 0) {
            throw new Exception('Invalid order total for refund');
        }

        $cardId = $order['nfc_card_id'] ?? $student['nfc_card_id'] ?? null;
        if (empty($cardId)) {
            throw new Exception('No RFID Card linked to this order');
        }

        $cardStmt = $pdo->prepare('SELECT balance FROM nfc_cards WHERE id = ? FOR UPDATE');
        $cardStmt->execute([$cardId]);
        $card = $cardStmt->fetch();

        if (!$card) {
            throw new Exception('RFID Card not found');
        }

        $balanceBefore = floatval($card['balance']);
        $balanceAfter = $balanceBefore + $refundAmount;

        $orderUpdateStmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND student_id = ?");
        $orderUpdateStmt->execute([$orderId, intval($student['student_id'])]);

        $cardUpdateStmt = $pdo->prepare('UPDATE nfc_cards SET balance = ?, updated_at = NOW() WHERE id = ?');
        $cardUpdateStmt->execute([$balanceAfter, $cardId]);

        $txnStmt = $pdo->prepare("\n            INSERT INTO transactions (card_id, type, amount, reason, order_id, balance_before, balance_after, created_at)\n            VALUES (?, 'credit', ?, ?, ?, ?, ?, NOW())\n        ");
        $txnStmt->execute([
            $cardId,
            $refundAmount,
            'Mobile order cancellation #' . $orderId,
            $orderId,
            $balanceBefore,
            $balanceAfter
        ]);

        $pdo->commit();

        sendResponse([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'order_id' => intval($orderId),
            'status' => 'cancelled',
            'new_balance' => $balanceAfter
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse(['error' => $e->getMessage()], 400);
    }
}
