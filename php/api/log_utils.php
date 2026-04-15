<?php
/**
 * Activity Logging Utility
 * Safe logging function for User Management actions
 */

/**
 * Ensure activity_logs table exists with proper structure
 * @param PDO $pdo Database connection
 * @return bool True if table exists and is valid
 */
function ensureActivityLogsTable($pdo) {
    try {
        // Check if table exists
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
        ");
        $stmt->execute();
        
        if (!$stmt->fetch()) {
            // Table doesn't exist, create it
            error_log('Creating activity_logs table');
            $pdo->exec("
                CREATE TABLE activity_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    admin_username VARCHAR(100) NOT NULL,
                    action VARCHAR(20) NOT NULL,
                    target VARCHAR(50) NOT NULL,
                    details TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_target_created (target, created_at DESC),
                    INDEX idx_admin_id (admin_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            error_log('Activity logs table created successfully');
            return true;
        }
        
        // Verify required columns exist
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'activity_logs'
        ");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $requiredColumns = ['id', 'admin_id', 'admin_username', 'action', 'target', 'details', 'created_at'];
        $missingColumns = array_diff($requiredColumns, $columns);
        
        if (!empty($missingColumns)) {
            error_log('Activity logs table missing columns: ' . implode(', ', $missingColumns));
            // Attempt to add missing columns
            foreach ($missingColumns as $col) {
                switch ($col) {
                    case 'admin_username':
                        $pdo->exec("ALTER TABLE activity_logs ADD COLUMN admin_username VARCHAR(100) NOT NULL DEFAULT 'Unknown'");
                        error_log('Added missing column: admin_username');
                        break;
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Error ensuring activity logs table: ' . $e->getMessage());
        return false;
    }
}

/**
 * Log an action to activity_logs table
 * @param PDO $pdo Database connection
 * @param int $adminId Admin ID from session
 * @param string $adminUsername Admin username from session
 * @param string $action Action type (Add, Edit, Delete)
 * @param string $target What was modified (e.g., "User")
 * @param string $details Description of what changed
 * @return bool Success status
 */
function logActivity($pdo, $adminId, $adminUsername, $action, $target, $details) {
    try {
        // Validate inputs
        if (empty($adminId)) {
            error_log('logActivity: adminId is empty, skipping log');
            return false;
        }
        
        if (empty($adminUsername)) {
            error_log('logActivity: adminUsername is empty, using "Unknown"');
            $adminUsername = 'Unknown';
        }
        
        // Ensure table exists before logging
        if (!ensureActivityLogsTable($pdo)) {
            error_log('logActivity: Failed to ensure activity logs table exists');
            return false;
        }
        
        // Log the attempt
        error_log("logActivity: Attempting to log - Admin ID: $adminId, Admin: $adminUsername, Action: $action, Target: $target");
        
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (admin_id, admin_username, action, target, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        // Execute and check result
        $result = $stmt->execute([
            intval($adminId),
            sanitize($adminUsername),
            sanitize($action),
            sanitize($target),
            sanitize($details)
        ]);
        
        if ($result) {
            error_log("logActivity: Successfully logged - $action on $target by $adminUsername");
        } else {
            error_log('logActivity: Execute returned false - ' . print_r($stmt->errorInfo(), true));
        }
        
        return $result;
    } catch (Exception $e) {
        // Log the error for debugging
        error_log('Activity logging error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        return false;
    }
}
?>
