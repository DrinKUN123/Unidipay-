<?php
/**
 * Database Configuration
 * UniDiPay System
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'unidipay_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // Default XAMPP password is empty

// Create database connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die(json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . $e->getMessage()
    ]));
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Get current admin ID
 */
function getAdminId() {
    return $_SESSION['admin_id'] ?? null;
}

/**
 * Get current actor identifiers for auditing
 */
function getActorIds() {
    $adminId = getAdminId();
    $isEmployee = $_SESSION['is_employee'] ?? false;

    return [
        'admin_id' => $isEmployee ? null : $adminId,
        'employee_id' => $isEmployee ? $adminId : null
    ];
}

/**
 * Get a valid admin id that satisfies FK constraints; fallback to the first admin
 */
function getEffectiveAdminId() {
    global $pdo;

    $sessionAdminId = getAdminId();
    if ($sessionAdminId) {
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionAdminId]);
        if ($stmt->fetch()) {
            return $sessionAdminId;
        }
    }

    $stmt = $pdo->query("SELECT id FROM admins ORDER BY id LIMIT 1");
    $row = $stmt->fetch();
    return $row['id'] ?? null;
}

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Validate required fields
 */
function validateRequired($data, $fields) {
    $errors = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $data)) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            continue;
        }

        $value = $data[$field];
        if ($value === null) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            continue;
        }

        if (is_string($value) && trim($value) === '') {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            continue;
        }

        if (is_array($value) && empty($value)) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    return $errors;
}

/**
 * Sanitize input
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
?>
