<?php
/**
 * Employee Roles Management API
 * Handles employee creation, management, and role assignment
 */

require_once '../config/database.php';
require_once 'log_utils.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    // Verify user is logged in and is manager
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }
    
    // Check if user is manager (for create, update, delete operations)
    if ($method !== 'GET') {
        $adminRole = getAdminRole();
        if ($adminRole !== 'manager') {
            sendResponse(['error' => 'Only managers can manage employees'], 403);
        }
    }
    
    switch ($method) {
        case 'GET':
            handleGet($action);
            break;
        case 'POST':
            handlePost($action);
            break;
        case 'PUT':
            handlePut($action);
            break;
        case 'DELETE':
            handleDelete($action);
            break;
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

/**
 * Handle GET requests
 */
function handleGet($action) {
    global $pdo;
    
    switch ($action) {
        case 'all':
            $stmt = $pdo->prepare("SELECT id, name, email, role, status, created_at FROM employees ORDER BY created_at DESC");
            $stmt->execute();
            $employees = $stmt->fetchAll();
            sendResponse(['success' => true, 'employees' => $employees]);
            break;
            
        case 'single':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) sendResponse(['error' => 'Invalid employee ID'], 400);
            
            $stmt = $pdo->prepare("SELECT id, name, email, role, status FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            $employee = $stmt->fetch();
            
            if (!$employee) {
                sendResponse(['error' => 'Employee not found'], 404);
            }
            sendResponse(['success' => true, 'employee' => $employee]);
            break;
            
        default:
            $stmt = $pdo->prepare("SELECT id, name, email, role, status, created_at FROM employees ORDER BY created_at DESC");
            $stmt->execute();
            $employees = $stmt->fetchAll();
            sendResponse(['success' => true, 'employees' => $employees]);
    }
}

/**
 * Handle POST requests - Create new employee
 */
function handlePost($action) {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $errors = validateRequired($data, ['name', 'email', 'password', 'role']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    // Validate role
    $validRoles = ['manager', 'staff', 'cashier'];
    if (!in_array($data['role'], $validRoles)) {
        sendResponse(['error' => 'Invalid role. Must be manager, staff, or cashier'], 400);
    }
    
    $name = sanitize($data['name']);
    $email = sanitize($data['email']);
    $password = $data['password'];
    $role = sanitize($data['role']);
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'Invalid email format'], 400);
    }
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Email already exists'], 400);
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert employee
    $stmt = $pdo->prepare("INSERT INTO employees (name, email, password, role, status) VALUES (?, ?, ?, ?, 'active')");
    $stmt->execute([$name, $email, $hashedPassword, $role]);
    
    $employeeId = $pdo->lastInsertId();
    
    // Log activity
    $adminId = $_SESSION['admin_id'] ?? null;
    $adminName = $_SESSION['admin_name'] ?? 'Unknown';
    if ($adminId) {
        logActivity($pdo, $adminId, $adminName, 'Add', 'Employee Role', "Created employee: $name (Role: $role)");
    }
    
    sendResponse([
        'success' => true,
        'message' => 'Employee created successfully',
        'employee' => [
            'id' => $employeeId,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => 'active'
        ]
    ]);
}

/**
 * Handle PUT requests - Update employee
 */
function handlePut($action) {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        sendResponse(['error' => 'Employee ID is required'], 400);
    }
    
    $id = intval($data['id']);
    
    // Check if employee exists
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        sendResponse(['error' => 'Employee not found'], 404);
    }
    
    $updates = [];
    $params = [];
    
    // Update allowed fields
    if (isset($data['name'])) {
        $updates[] = "name = ?";
        $params[] = sanitize($data['name']);
    }
    
    if (isset($data['email'])) {
        $email = sanitize($data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(['error' => 'Invalid email format'], 400);
        }
        
        // Check if email is unique (excluding current employee)
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            sendResponse(['error' => 'Email already in use'], 400);
        }
        
        $updates[] = "email = ?";
        $params[] = $email;
    }
    
    if (isset($data['role'])) {
        $validRoles = ['manager', 'staff', 'cashier'];
        if (!in_array($data['role'], $validRoles)) {
            sendResponse(['error' => 'Invalid role'], 400);
        }
        $updates[] = "role = ?";
        $params[] = sanitize($data['role']);
    }
    
    if (isset($data['status'])) {
        $validStatus = ['active', 'inactive'];
        if (!in_array($data['status'], $validStatus)) {
            sendResponse(['error' => 'Invalid status'], 400);
        }
        $updates[] = "status = ?";
        $params[] = sanitize($data['status']);
    }
    
    if (isset($data['password'])) {
        $updates[] = "password = ?";
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    
    if (empty($updates)) {
        sendResponse(['error' => 'No fields to update'], 400);
    }
    
    $params[] = $id;
    
    $sql = "UPDATE employees SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Get employee name for logging
    $stmt = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $employee = $stmt->fetch();
    $empName = $employee['name'] ?? 'Employee';
    
    // Log activity
    $adminId = $_SESSION['admin_id'] ?? null;
    $adminName = $_SESSION['admin_name'] ?? 'Unknown';
    if ($adminId) {
        logActivity($pdo, $adminId, $adminName, 'Edit', 'Employee Role', "Updated employee: $empName");
    }
    
    sendResponse([
        'success' => true,
        'message' => 'Employee updated successfully'
    ]);
}

/**
 * Handle DELETE requests
 */
function handleDelete($action) {
    global $pdo;
    
    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        sendResponse(['error' => 'Employee ID is required'], 400);
    }
    
    // Check if employee exists
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        sendResponse(['error' => 'Employee not found'], 404);
    }
    
    // Don't allow deleting yourself
    if (getAdminId() == $id) {
        sendResponse(['error' => 'You cannot delete your own account'], 400);
    }
    
    // Get employee name for logging
    $stmt = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $employee = $stmt->fetch();
    $empName = $employee['name'] ?? 'Employee';
    
    $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    
    // Log activity
    $adminId = $_SESSION['admin_id'] ?? null;
    $adminName = $_SESSION['admin_name'] ?? 'Unknown';
    if ($adminId) {
        logActivity($pdo, $adminId, $adminName, 'Delete', 'Employee Role', "Deleted employee: $empName");
    }
    
    sendResponse([
        'success' => true,
        'message' => 'Employee deleted successfully'
    ]);
}

/**
 * Get admin role from session
 */
function getAdminRole() {
    return $_SESSION['admin_role'] ?? 'staff';
}
?>
