<?php
require_once 'config.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!isset($input['nfc_card_id']) || empty($input['nfc_card_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'NFC Card ID is required'
        ]);
        exit;
    }
    
    if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Order items are required'
        ]);
        exit;
    }
    
    if (!isset($input['total']) || $input['total'] <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid order total'
        ]);
        exit;
    }
    
    if (!isset($input['order_type']) || !in_array($input['order_type'], ['dine-in', 'take-out'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid order type'
        ]);
        exit;
    }
    
    // Validate payment method (used for logic, not persisted when column is absent)
    $paymentMethod = isset($input['payment_method']) ? $input['payment_method'] : 'nfc';
    if (!in_array($paymentMethod, ['nfc', 'cash'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid payment method'
        ]);
        exit;
    }
    
    $cardId = $input['nfc_card_id'];
    $items = $input['items'];
    $total = floatval($input['total']);
    $orderType = $input['order_type'];
    
    $conn = getDBConnection();
    
    if (!$conn) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed'
        ]);
        exit;
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Get card info and lock row
        $stmt = $conn->prepare("
            SELECT nc.balance, nc.student_id, s.name as student_name
            FROM nfc_cards nc
            INNER JOIN students s ON nc.student_id = s.id
            WHERE nc.id = :card_id
            FOR UPDATE
        ");
        $stmt->execute(['card_id' => $cardId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception('Card not found');
        }
        
        $currentBalance = floatval($card['balance']);
        $studentId = $card['student_id'];
        
        // Check balance
        if ($currentBalance < $total) {
            throw new Exception('Insufficient balance. Current balance: ₱' . number_format($currentBalance, 2));
        }
        
        // Create order
        // Insert order (payment_method column is optional in some schemas; omitting avoids SQL errors)
        $stmt = $conn->prepare("
            INSERT INTO orders (student_id, nfc_card_id, total, status, created_at)
            VALUES (:student_id, :nfc_card_id, :total, 'pending', NOW())
        ");
        
        $stmt->execute([
            'student_id' => $studentId,
            'nfc_card_id' => $cardId,
            'total' => $total
        ]);
        
        $orderId = $conn->lastInsertId();
        
        // Insert order items
        $stmt = $conn->prepare("
            INSERT INTO order_items (order_id, menu_item_id, name, price, quantity, subtotal)
            VALUES (:order_id, :menu_item_id, :name, :price, :quantity, :subtotal)
        ");
        
        foreach ($items as $item) {
            $stmt->execute([
                'order_id' => $orderId,
                'menu_item_id' => $item['menu_item_id'],
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'subtotal' => $item['subtotal']
            ]);
        }
        
        // Calculate new balance
        $newBalance = $currentBalance - $total;
        
        // Update card balance
        $stmt = $conn->prepare("
            UPDATE nfc_cards 
            SET balance = :new_balance, updated_at = NOW()
            WHERE id = :card_id
        ");
        $stmt->execute([
            'new_balance' => $newBalance,
            'card_id' => $cardId
        ]);
        
        // Record transaction
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                card_id, type, amount, reason, order_id, 
                balance_before, balance_after, created_at
            )
            VALUES (
                :card_id, 'debit', :amount, :reason, :order_id,
                :balance_before, :balance_after, NOW()
            )
        ");
        
        $stmt->execute([
            'card_id' => $cardId,
            'amount' => $total,
            'reason' => 'Order #' . $orderId,
            'order_id' => $orderId,
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance
        ]);
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order created successfully',
            'order_id' => $orderId,
            'order_number' => 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT),
            'new_balance' => $newBalance,
            'student_name' => $card['student_name']
        ]);
        
    } catch(Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} catch(Exception $e) {
    error_log("Error creating order: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
