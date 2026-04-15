<?php
/**
 * Activity Logs API Endpoint
 * Fetches activity logs for a specific target
 */

require_once '../config/database.php';
require_once 'log_utils.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    sendResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$target = $_GET['target'] ?? '';
if (!$target) {
    sendResponse(['success' => false, 'error' => 'Target parameter required'], 400);
}

try {
    // Ensure table exists
    ensureActivityLogsTable($pdo);
    
    $stmt = $pdo->prepare("
        SELECT admin_username, action, details, created_at 
        FROM activity_logs 
        WHERE target = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$target]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendResponse([
        'success' => true,
        'logs' => $logs
    ]);
} catch (Exception $e) {
    // Log the error but return empty logs instead of error
    error_log('Activity logs fetch error: ' . $e->getMessage());
    sendResponse([
        'success' => true,
        'logs' => [],
        'message' => 'Activity logs not available yet'
    ]);
}
?>
