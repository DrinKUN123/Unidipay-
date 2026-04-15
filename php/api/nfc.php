<?php
/**
 * RFID Card API
 * Manage RFID Card operations: search, load, deduct, history
 */

require_once '../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

if (!isLoggedIn()) {
    sendResponse(['error' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'search':
            if (isset($_GET['card_id'])) {
                searchCard($_GET['card_id']);
            } else {
                sendResponse(['error' => 'Card ID required'], 400);
            }
            break;
            
        case 'load':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            loadBalance();
            break;
            
        case 'deduct':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            deductBalance();
            break;
            
        case 'history':
            if (isset($_GET['card_id'])) {
                getHistory($_GET['card_id']);
            } else {
                sendResponse(['error' => 'Card ID required'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

/**
 * Search RFID Card and get student info
 */
function searchCard($cardId) {
    global $pdo;
    
    $cardId = sanitize($cardId);
    
    // Get card info
    $stmt = $pdo->prepare("SELECT * FROM nfc_cards WHERE id = ?");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch();
    
    if (!$card) {
        sendResponse(['error' => 'Card not found'], 404);
    }
    
    // Get student info
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$card['student_id']]);
    $student = $stmt->fetch();
    
    sendResponse([
        'card' => $card,
        'student' => $student
    ]);
}

/**
 * Load balance to RFID Card
 */
function loadBalance() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = validateRequired($data, ['card_id', 'amount']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    $cardId = sanitize($data['card_id']);
    $amount = floatval($data['amount']);
    
    if ($amount <= 0) {
        sendResponse(['error' => 'Amount must be greater than 0'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        $actor = getActorIds();
        
        // Get current balance
        $stmt = $pdo->prepare("SELECT balance FROM nfc_cards WHERE id = ? FOR UPDATE");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception('Card not found');
        }
        
        $balanceBefore = $card['balance'];
        $balanceAfter = $balanceBefore + $amount;
        
        // Update balance
        $stmt = $pdo->prepare("UPDATE nfc_cards SET balance = ? WHERE id = ?");
        $stmt->execute([$balanceAfter, $cardId]);
        
        // Record reload with employee/admin actor; fallback if column missing
        $adminIdForInsert = $actor['admin_id'] ?: getEffectiveAdminId();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO reloads (card_id, amount, admin_id, employee_id, balance_before, balance_after)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cardId,
                $amount,
                $adminIdForInsert,
                $actor['employee_id'],
                $balanceBefore,
                $balanceAfter
            ]);
        } catch (PDOException $e) {
            // Compatibility: older schema without employee_id
            if ($e->getCode() === '42S22') {
                if ($adminIdForInsert === null) {
                    throw new Exception('No admin available to record reload; please create an admin user.');
                }
                $fallback = $pdo->prepare("
                    INSERT INTO reloads (card_id, amount, admin_id, balance_before, balance_after)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $fallback->execute([
                    $cardId,
                    $amount,
                    $adminIdForInsert,
                    $balanceBefore,
                    $balanceAfter
                ]);
            } else {
                throw $e;
            }
        }
        
        $pdo->commit();
        
        sendResponse([
            'success' => true,
            'new_balance' => $balanceAfter,
            'reload' => [
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => $e->getMessage()], 400);
    }
}

/**
 * Deduct balance from RFID Card
 */
function deductBalance() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = validateRequired($data, ['card_id', 'amount']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    $cardId = sanitize($data['card_id']);
    $amount = floatval($data['amount']);
    $reason = sanitize($data['reason'] ?? 'Manual deduction');
    
    if ($amount <= 0) {
        sendResponse(['error' => 'Amount must be greater than 0'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current balance
        $stmt = $pdo->prepare("SELECT balance FROM nfc_cards WHERE id = ? FOR UPDATE");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception('Card not found');
        }
        
        $balanceBefore = $card['balance'];
        
        if ($balanceBefore < $amount) {
            throw new Exception('Insufficient balance');
        }
        
        $balanceAfter = $balanceBefore - $amount;
        
        // Update balance
        $stmt = $pdo->prepare("UPDATE nfc_cards SET balance = ? WHERE id = ?");
        $stmt->execute([$balanceAfter, $cardId]);
        
        // Record transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (card_id, type, amount, reason, balance_before, balance_after)
            VALUES (?, 'debit', ?, ?, ?, ?)
        ");
        $stmt->execute([$cardId, $amount, $reason, $balanceBefore, $balanceAfter]);
        
        $pdo->commit();
        
        sendResponse([
            'success' => true,
            'new_balance' => $balanceAfter,
            'transaction' => [
                'amount' => $amount,
                'reason' => $reason,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => $e->getMessage()], 400);
    }
}

/**
 * Get transaction history for a card
 */
function getHistory($cardId) {
    global $pdo;
    
    $cardId = sanitize($cardId);
    
    // Get transactions
    $stmt = $pdo->prepare("
        SELECT *, 'transaction' as type
        FROM transactions
        WHERE card_id = ?
        UNION ALL
        SELECT 
            id, card_id, NULL as type, amount, 
            CONCAT('Balance reload') as reason,
            NULL as order_id, balance_before, balance_after, created_at,
            'reload' as type
        FROM reloads
        WHERE card_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$cardId, $cardId]);
    $history = $stmt->fetchAll();
    
    sendResponse(['history' => $history]);
}
?>
