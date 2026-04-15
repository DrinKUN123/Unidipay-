<?php
/**
 * Activity Log Setup Verification
 * Run this once to ensure the activity_logs table exists
 * Access it at: http://localhost/UniDiPaypro/php/setup_activity_logs.php
 */

require_once 'config/database.php';
require_once 'api/log_utils.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs Setup</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 { color: #2563EB; margin-top: 0; }
        .status { padding: 15px; border-radius: 4px; margin: 15px 0; }
        .success { background: #D1FAE5; border-left: 4px solid #10B981; color: #065F46; }
        .error { background: #FEE2E2; border-left: 4px solid #EF4444; color: #7F1D1D; }
        .info { background: #DBEAFE; border-left: 4px solid #2563EB; color: #1E40AF; }
        code { background: #f3f4f6; padding: 3px 6px; border-radius: 3px; font-family: monospace; }
        .instruction { background: #f9fafb; padding: 15px; border-radius: 4px; margin: 15px 0; border-left: 4px solid #64748B; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <h1>✓ Activity Logs Setup Verification</h1>
        
        <?php
        try {
            // Attempt to ensure the table exists
            if (ensureActivityLogsTable($pdo)) {
                echo '<div class="status success">✓ Activity Logs table is ready!</div>';
                
                // Get table info
                $stmt = $pdo->prepare("
                    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
                    ORDER BY ORDINAL_POSITION
                ");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<p><strong>Table Columns:</strong></p>';
                echo '<table>';
                echo '<tr><th>Column Name</th><th>Type</th><th>Nullable</th></tr>';
                foreach ($columns as $col) {
                    echo '<tr>';
                    echo '<td><code>' . htmlspecialchars($col['COLUMN_NAME']) . '</code></td>';
                    echo '<td>' . htmlspecialchars($col['COLUMN_TYPE']) . '</td>';
                    echo '<td>' . ($col['IS_NULLABLE'] === 'YES' ? 'Yes' : 'No') . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
                
                // Count existing logs
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM activity_logs");
                $result = $stmt->fetch();
                echo '<div class="status info">📊 Current logs: ' . intval($result['count']) . '</div>';
                
                echo '<div class="instruction">
                    <strong>✓ Setup Complete!</strong><br>
                    Your activity logs system is ready to use. 
                    The system will automatically log all Add/Edit/Delete actions in User Management.
                    <br><br>
                    <small>This page creates the table automatically if it doesn\'t exist.</small>
                </div>';
            }
        } catch (Exception $e) {
            echo '<div class="status error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            
            echo '<div class="instruction">
                <strong>Manual Setup Required:</strong><br>
                If automatic setup failed, run this SQL in phpMyAdmin:<br><br>
                <code>CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    admin_username VARCHAR(100) NOT NULL,
    action VARCHAR(20) NOT NULL,
    target VARCHAR(50) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_created (target, created_at DESC),
    INDEX idx_admin_id (admin_id)
);</code>
            </div>';
        }
        ?>
        
        <div class="instruction">
            <strong>Next Steps:</strong>
            <ol>
                <li>This page has set up the activity_logs table</li>
                <li>Go to <a href="/UniDiPaypro/users.html">User Management</a></li>
                <li>Try adding, editing, or deleting a user</li>
                <li>Check the "Recent Activity" section at the bottom</li>
            </ol>
        </div>
        
        <p style="color: #888; font-size: 12px; margin-top: 30px;">
            Setup file location: <code>php/setup_activity_logs.php</code><br>
            You can delete this file after verification.
        </p>
    </div>
</body>
</html>
