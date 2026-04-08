<?php
/**
 * Students API
 * CRUD operations for student management
 */

require_once '../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

if (!isLoggedIn()) {
    sendResponse(['error' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'all') {
                getAllStudents();
            } elseif ($action === 'single' && isset($_GET['id'])) {
                getStudent($_GET['id']);
            } else {
                sendResponse(['error' => 'Invalid action'], 400);
            }
            break;
            
        case 'POST':
            createStudent();
            break;
            
        case 'PUT':
            updateStudent();
            break;
            
        case 'DELETE':
            if (isset($_GET['id'])) {
                deleteStudent($_GET['id']);
            } else {
                sendResponse(['error' => 'Student ID required'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

/**
 * Get all students with their NFC cards
 */
function getAllStudents() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT 
            s.*,
            nc.balance as nfc_balance
        FROM students s
        LEFT JOIN nfc_cards nc ON s.nfc_card_id = nc.id
        ORDER BY s.created_at DESC
    ");
    
    $students = $stmt->fetchAll();
    
    sendResponse(['students' => $students]);
}

/**
 * Get single student
 */
function getStudent($id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            nc.balance as nfc_balance
        FROM students s
        LEFT JOIN nfc_cards nc ON s.nfc_card_id = nc.id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        sendResponse(['error' => 'Student not found'], 404);
    }
    
    sendResponse(['student' => $student]);
}

/**
 * Create new student with NFC card
 */
function createStudent() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = validateRequired($data, [
        'name', 'student_id', 'program', 'year_level', 'nfc_card_id'
    ]);
    
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    $name = sanitize($data['name']);
    $studentId = sanitize($data['student_id']);
    $program = sanitize($data['program']);
    $yearLevel = sanitize($data['year_level']);
    $nfcCardId = sanitize($data['nfc_card_id']);
    $nfcBalance = floatval($data['nfc_balance'] ?? 0);
    
    try {
        $pdo->beginTransaction();
        
        // Check if student_id or nfc_card_id already exists
        $stmt = $pdo->prepare("SELECT id FROM students WHERE student_id = ? OR nfc_card_id = ?");
        $stmt->execute([$studentId, $nfcCardId]);
        if ($stmt->fetch()) {
            throw new Exception('Student ID or NFC Card ID already exists');
        }
        
        // Insert student
        $stmt = $pdo->prepare("
            INSERT INTO students (name, student_id, program, year_level, nfc_card_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $studentId, $program, $yearLevel, $nfcCardId]);
        $newStudentId = $pdo->lastInsertId();
        
        // Insert NFC card
        $stmt = $pdo->prepare("
            INSERT INTO nfc_cards (id, student_id, balance)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$nfcCardId, $newStudentId, $nfcBalance]);
        
        // If initial balance > 0, record reload
        if ($nfcBalance > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO reloads (card_id, amount, admin_id, balance_before, balance_after)
                VALUES (?, ?, ?, 0, ?)
            ");
            $stmt->execute([$nfcCardId, $nfcBalance, getAdminId(), $nfcBalance]);
        }
        
        $pdo->commit();
        
        // Get created student
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$newStudentId]);
        $student = $stmt->fetch();
        
        sendResponse([
            'success' => true,
            'student' => $student
        ], 201);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => $e->getMessage()], 400);
    }
}

/**
 * Update student information
 */
function updateStudent() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        sendResponse(['error' => 'Student ID required'], 400);
    }
    
    $id = intval($data['id']);
    
    // Build update query dynamically
    $fields = [];
    $values = [];
    
    if (isset($data['name'])) {
        $fields[] = "name = ?";
        $values[] = sanitize($data['name']);
    }
    if (isset($data['program'])) {
        $fields[] = "program = ?";
        $values[] = sanitize($data['program']);
    }
    if (isset($data['year_level'])) {
        $fields[] = "year_level = ?";
        $values[] = sanitize($data['year_level']);
    }
    
    if (empty($fields)) {
        sendResponse(['error' => 'No fields to update'], 400);
    }
    
    $values[] = $id;
    
    $sql = "UPDATE students SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    // Get updated student
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    
    sendResponse([
        'success' => true,
        'student' => $student
    ]);
}

/**
 * Delete student and associated NFC card
 */
function deleteStudent($id) {
    global $pdo;
    
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() === 0) {
        sendResponse(['error' => 'Student not found'], 404);
    }
    
    sendResponse(['success' => true]);
}
?>
