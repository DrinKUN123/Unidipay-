<?php
require_once 'config.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['card_id']) || empty($input['card_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Card ID is required'
        ]);
        exit;
    }
    
    $cardId = $input['card_id'];
    
    $conn = getDBConnection();
    
    if (!$conn) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed'
        ]);
        exit;
    }
    
    // Verify card exists and get student info
    $stmt = $conn->prepare("
        SELECT 
            nc.id,
            nc.balance,
            s.id as student_id,
            s.name as student_name,
            s.student_id,
            s.program
        FROM nfc_cards nc
        INNER JOIN students s ON nc.student_id = s.id
        WHERE nc.id = :card_id
    ");
    
    $stmt->execute(['card_id' => $cardId]);
    $card = $stmt->fetch();
    
    if (!$card) {
        echo json_encode([
            'success' => false,
            'message' => 'Card not found or not registered'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'card_id' => $card['id'],
        'balance' => $card['balance'],
        'student_id' => $card['student_id'],
        'student_name' => $card['student_name'],
        'student_number' => $card['student_id'],
        'program' => $card['program']
    ]);
    
} catch(PDOException $e) {
    error_log("Error verifying card: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to verify card'
    ]);
}
?>
