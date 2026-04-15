<?php
/**
 * Activity Log Diagnostic Tool
 * Debug why logs aren't being recorded
 * Access: http://localhost/UniDiPaypro/php/diagnostic.php
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
    <title>Activity Log Diagnostic</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 { color: #2563EB; margin-top: 0; }
        .section { margin: 30px 0; padding: 20px; border-left: 4px solid #2563EB; background: #f9fafb; }
        .check { margin: 10px 0; padding: 10px; border-radius: 4px; }
        .pass { background: #D1FAE5; border-left: 4px solid #10B981; color: #065F46; }
        .fail { background: #FEE2E2; border-left: 4px solid #EF4444; color: #7F1D1D; }
        .warn { background: #FEF3C7; border-left: 4px solid #F59E0B; color: #78350F; }
        code { background: #f3f4f6; padding: 3px 6px; border-radius: 3px; font-family: monospace; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 600; }
        .test-btn { 
            padding: 10px 20px; 
            background: #2563EB; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer;
            font-size: 14px;
            margin: 5px 0;
        }
        .test-btn:hover { background: #1D4ED8; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Activity Log Diagnostic</h1>
        
        <div class="section">
            <h2>1. Database Connection</h2>
            <?php
            try {
                $result = $pdo->query("SELECT 1");
                echo '<div class="check pass">✓ Database connection OK</div>';
            } catch (Exception $e) {
                echo '<div class="check fail">✗ Database connection failed: ' . $e->getMessage() . '</div>';
            }
            ?>
        </div>

        <div class="section">
            <h2>2. Activity Logs Table Structure</h2>
            <?php
            try {
                // Ensure table exists
                ensureActivityLogsTable($pdo);
                
                $stmt = $pdo->query("
                    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
                    ORDER BY ORDINAL_POSITION
                ");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($columns)) {
                    echo '<div class="check pass">✓ Table exists with ' . count($columns) . ' columns</div>';
                    echo '<table><tr><th>Column</th><th>Type</th><th>Nullable</th></tr>';
                    foreach ($columns as $col) {
                        echo '<tr><td><code>' . htmlspecialchars($col['COLUMN_NAME']) . '</code></td>';
                        echo '<td>' . htmlspecialchars($col['COLUMN_TYPE']) . '</td>';
                        echo '<td>' . ($col['IS_NULLABLE'] === 'YES' ? 'Yes' : 'No') . '</td></tr>';
                    }
                    echo '</table>';
                } else {
                    echo '<div class="check fail">✗ Table has no columns</div>';
                }
            } catch (Exception $e) {
                echo '<div class="check fail">✗ Error checking table: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <div class="section">
            <h2>3. Current Log Count</h2>
            <?php
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM activity_logs");
                $result = $stmt->fetch();
                $count = intval($result['count']);
                
                if ($count === 0) {
                    echo '<div class="check warn">⚠ No logs recorded yet (' . $count . ' total)</div>';
                } else {
                    echo '<div class="check pass">✓ Found ' . $count . ' total log entries</div>';
                }
                
                // Show recent logs
                $stmt = $pdo->query("
                    SELECT admin_username, action, target, details, created_at 
                    FROM activity_logs 
                    ORDER BY created_at DESC 
                    LIMIT 5
                ");
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($logs)) {
                    echo '<h3>Recent Logs:</h3>';
                    echo '<table><tr><th>Admin</th><th>Action</th><th>Target</th><th>Details</th><th>Time</th></tr>';
                    foreach ($logs as $log) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($log['admin_username']) . '</td>';
                        echo '<td>' . htmlspecialchars($log['action']) . '</td>';
                        echo '<td>' . htmlspecialchars($log['target']) . '</td>';
                        echo '<td>' . htmlspecialchars(substr($log['details'], 0, 50)) . '...</td>';
                        echo '<td>' . htmlspecialchars($log['created_at']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                }
            } catch (Exception $e) {
                echo '<div class="check fail">✗ Error querying logs: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <div class="section">
            <h2>4. Session Information</h2>
            <?php
            if (isLoggedIn()) {
                $adminId = $_SESSION['admin_id'] ?? 'NOT SET';
                $adminName = $_SESSION['admin_name'] ?? 'NOT SET';
                
                echo '<div class="check pass">✓ User is logged in</div>';
                echo '<table>';
                echo '<tr><th>Session Variable</th><th>Value</th></tr>';
                echo '<tr><td><code>admin_id</code></td><td>' . htmlspecialchars($adminId) . '</td></tr>';
                echo '<tr><td><code>admin_name</code></td><td>' . htmlspecialchars($adminName) . '</td></tr>';
                echo '<tr><td><code>admin_email</code></td><td>' . htmlspecialchars($_SESSION['admin_email'] ?? 'NOT SET') . '</td></tr>';
                echo '<tr><td><code>admin_role</code></td><td>' . htmlspecialchars($_SESSION['admin_role'] ?? 'NOT SET') . '</td></tr>';
                echo '</table>';
            } else {
                echo '<div class="check fail">✗ User NOT logged in - this is why logs aren\'t being recorded!</div>';
                echo '<p><strong>Fix:</strong> Log in first at <a href="/UniDiPaypro/index.html">Login Page</a></p>';
            }
            ?>
        </div>

        <div class="section">
            <h2>5. Test Logging Function</h2>
            <?php
            if (isLoggedIn()) {
                $adminId = $_SESSION['admin_id'];
                $adminName = $_SESSION['admin_name'] ?? 'Unknown';
                
                // Try to log a test entry
                $result = logActivity($pdo, $adminId, $adminName, 'Test', 'Diagnostic', 'Test log entry from diagnostic script');
                
                if ($result) {
                    echo '<div class="check pass">✓ Test log entry created successfully</div>';
                    
                    // Check if it was recorded
                    $stmt = $pdo->prepare("
                        SELECT * FROM activity_logs 
                        WHERE action = 'Test' AND target = 'Diagnostic'
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $stmt->execute();
                    $log = $stmt->fetch();
                    
                    if ($log) {
                        echo '<div class="check pass">✓ Test log verified in database</div>';
                        echo '<p><strong>Log Details:</strong></p>';
                        echo '<pre>' . print_r($log, true) . '</pre>';
                    }
                } else {
                    echo '<div class="check fail">✗ Failed to create test log</div>';
                }
            } else {
                echo '<div class="check warn">⚠ Cannot test - user not logged in</div>';
            }
            ?>
        </div>

        <div class="section">
            <h2>6. Recommended Actions</h2>
            <ul>
                <li>Make sure you're <strong>logged in</strong> to the admin panel</li>
                <li>Verify <code>admin_id</code> and <code>admin_name</code> are set in session</li>
                <li>Activity logs will only be recorded when <strong>admin_id is set</strong></li>
                <li>Check browser <strong>Developer Console (F12)</strong> for JavaScript errors</li>
                <li>Check <code>error_log</code> in xampp for PHP errors</li>
                <li>After logging in, try adding/editing/deleting a user again</li>
            </ul>
        </div>

        <div class="section">
            <p style="color: #888; font-size: 12px;">
                Diagnostic file: <code>php/diagnostic.php</code><br>
                You can delete this file after diagnosis is complete.
            </p>
        </div>
    </div>
</body>
</html>
