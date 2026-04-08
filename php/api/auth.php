<?php
/**
 * Authentication API
 * Handles login, logout, and session management
 */

require_once '../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            handleLogin();
            break;
            
        case 'logout':
            handleLogout();
            break;
            
        case 'check':
            checkSession();
            break;
            
        case 'register':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            handleRegister();
            break;
            
        default:
            sendResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

/**
 * Handle admin login
 */
function handleLogin() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = validateRequired($data, ['email', 'password']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    $email = sanitize($data['email']);
    $password = $data['password'];
    
    // First try to find in employees table (with roles)
    $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM employees WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $employee = $stmt->fetch();
    
    if ($employee && password_verify($password, $employee['password'])) {
        // Employee login
        $_SESSION['admin_id'] = $employee['id'];
        $_SESSION['admin_name'] = $employee['name'];
        $_SESSION['admin_email'] = $employee['email'];
        $_SESSION['admin_role'] = $employee['role'];
        $_SESSION['is_employee'] = true;
        
        sendResponse([
            'success' => true,
            'admin' => [
                'id' => $employee['id'],
                'name' => $employee['name'],
                'email' => $employee['email'],
                'role' => $employee['role']
            ]
        ]);
        return;
    }
    
    // Fall back to admins table (for backwards compatibility)
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();
    
    if (!$admin || !password_verify($password, $admin['password'])) {
        sendResponse(['error' => 'Invalid email or password'], 401);
    }
    
    // Set session as manager (default role for legacy admins)
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_role'] = 'manager';
    $_SESSION['is_employee'] = false;
    
    sendResponse([
        'success' => true,
        'admin' => [
            'id' => $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => 'manager'
        ]
    ]);
}

/**
 * Handle admin registration
 */
function handleRegister() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = validateRequired($data, ['name', 'email', 'password']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    $name = sanitize($data['name']);
    $email = sanitize($data['email']);
    $password = $data['password'];
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Email already exists'], 400);
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert admin
    $stmt = $pdo->prepare("INSERT INTO admins (name, email, password) VALUES (?, ?, ?)");
    $stmt->execute([$name, $email, $hashedPassword]);
    
    $adminId = $pdo->lastInsertId();
    
    // Auto login
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_name'] = $name;
    $_SESSION['admin_email'] = $email;
    $_SESSION['admin_role'] = 'manager';
    $_SESSION['is_employee'] = false;
    
    sendResponse([
        'success' => true,
        'admin' => [
            'id' => $adminId,
            'name' => $name,
            'email' => $email,
            'role' => 'manager'
        ]
    ]);
}

/**
 * Handle logout
 */
function handleLogout() {
    session_destroy();
    sendResponse(['success' => true]);
}

/**
 * Check if session is active
 */
function checkSession() {
    if (isLoggedIn()) {
        sendResponse([
            'loggedIn' => true,
            'admin' => [
                'id' => $_SESSION['admin_id'],
                'name' => $_SESSION['admin_name'],
                'email' => $_SESSION['admin_email'],
                'role' => $_SESSION['admin_role'] ?? 'manager'
            ]
        ]);
    } else {
        sendResponse(['loggedIn' => false]);
    }
}
?>
