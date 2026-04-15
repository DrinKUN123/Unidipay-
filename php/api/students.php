<?php
/**
 * Students API
 * CRUD operations for student management
 */

require_once '../config/database.php';
require_once 'log_utils.php';

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
 * Get all students with their RFID Cards
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
 * Create new student with RFID Card
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
            throw new Exception('Student ID or RFID Card ID already exists');
        }
        
        // Insert student
        $stmt = $pdo->prepare("
            INSERT INTO students (name, student_id, program, year_level, nfc_card_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $studentId, $program, $yearLevel, $nfcCardId]);
        $newStudentId = $pdo->lastInsertId();
        
        // Insert RFID Card
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
        
        // Get created student snapshot and write activity log
        $student = fetchStudentForLog($newStudentId);
        logStudentChange('Add', null, $student);
        
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

    $beforeStudent = fetchStudentForLog($id);
    if (!$beforeStudent) {
        sendResponse(['error' => 'Student not found'], 404);
    }
    
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
    
    // Get updated student snapshot and write activity log
    $student = fetchStudentForLog($id);
    logStudentChange('Edit', $beforeStudent, $student);
    
    sendResponse([
        'success' => true,
        'student' => $student
    ]);
}

/**
 * Delete student and associated RFID Card
 */
function deleteStudent($id) {
    global $pdo;

    $beforeStudent = fetchStudentForLog($id);
    if (!$beforeStudent) {
        sendResponse(['error' => 'Student not found'], 404);
    }
    
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() === 0) {
        sendResponse(['error' => 'Student not found'], 404);
    }

    logStudentChange('Delete', $beforeStudent, null);
    
    sendResponse(['success' => true]);
}

/**
 * Fetch student data with balance for logging and API response
 */
function fetchStudentForLog($id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.name,
            s.student_id,
            s.program,
            s.year_level,
            s.nfc_card_id,
            s.created_at,
            nc.balance AS nfc_balance
        FROM students s
        LEFT JOIN nfc_cards nc ON s.nfc_card_id = nc.id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/**
 * Write activity logs for student create/update/delete actions
 */
function logStudentChange($action, $beforeStudent, $afterStudent) {
    global $pdo;

    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) {
        return;
    }

    $adminName = $_SESSION['admin_name'] ?? 'Unknown';

    $beforeJson = $beforeStudent
        ? json_encode(normalizeStudentLogData($beforeStudent), JSON_UNESCAPED_SLASHES)
        : 'null';
    $afterJson = $afterStudent
        ? json_encode(normalizeStudentLogData($afterStudent), JSON_UNESCAPED_SLASHES)
        : 'null';

    $details = "Before: {$beforeJson} | After: {$afterJson}";

    try {
        logActivity($pdo, $adminId, $adminName, $action, 'User', $details);
    } catch (Exception $e) {
        // Logging must never block core CRUD operations.
        error_log('Student activity log error: ' . $e->getMessage());
    }
}

/**
 * Keep log payload stable and compact
 */
function normalizeStudentLogData($student) {
    return [
        'id' => isset($student['id']) ? (int)$student['id'] : null,
        'name' => $student['name'] ?? null,
        'student_id' => $student['student_id'] ?? null,
        'program' => $student['program'] ?? null,
        'year_level' => $student['year_level'] ?? null,
        'nfc_card_id' => $student['nfc_card_id'] ?? null,
        'nfc_balance' => isset($student['nfc_balance']) ? (float)$student['nfc_balance'] : null
    ];
}
?>
