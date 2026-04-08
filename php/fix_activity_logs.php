<?php
/**
 * Activity Logs Table Fixer
 * Recreates the table with correct columns
 * Access: http://localhost/UniDiPaypro/php/fix_activity_logs.php
 */

require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Activity Logs Table</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 { color: #2563EB; margin-top: 0; }
        .section { margin: 20px 0; padding: 20px; border-left: 4px solid #2563EB; background: #f9fafb; }
        .status { padding: 15px; border-radius: 4px; margin: 15px 0; }
        .success { background: #D1FAE5; border-left: 4px solid #10B981; color: #065F46; }
        .error { background: #FEE2E2; border-left: 4px solid #EF4444; color: #7F1D1D; }
        .warning { background: #FEF3C7; border-left: 4px solid #F59E0B; color: #78350F; }
        code { background: #f3f4f6; padding: 3px 6px; border-radius: 3px; font-family: monospace; }
        .btn {
            padding: 12px 24px;
            background: #2563EB;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px 10px 0;
        }
        .btn:hover { background: #1D4ED8; }
        .btn-danger {
            background: #EF4444;
        }
        .btn-danger:hover {
            background: #DC2626;
        }
        pre {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f3f4f6;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Fix Activity Logs Table</h1>
        
        <div class="section">
            <h2>Current Table Status</h2>
            <?php
            try {
                // Check if table exists
                $stmt = $pdo->query("
                    SELECT COLUMN_NAME, COLUMN_TYPE 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
                    ORDER BY ORDINAL_POSITION
                ");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($columns)) {
                    echo '<div class="status error">✗ Table does not exist or has no columns</div>';
                } else {
                    echo '<div class="status warning">⚠ Table exists but might be missing columns</div>';
                    echo '<p><strong>Current Columns:</strong></p>';
                    echo '<table><tr><th>Column Name</th><th>Type</th></tr>';
                    foreach ($columns as $col) {
                        echo '<tr><td><code>' . htmlspecialchars($col['COLUMN_NAME']) . '</code></td><td>' . htmlspecialchars($col['COLUMN_TYPE']) . '</td></tr>';
                    }
                    echo '</table>';
                }
            } catch (Exception $e) {
                echo '<div class="status error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <div class="section">
            <h2>Required Columns</h2>
            <table>
                <tr>
                    <th>Column Name</th>
                    <th>Type</th>
                    <th>Purpose</th>
                </tr>
                <tr>
                    <td><code>id</code></td>
                    <td>INT AUTO_INCREMENT PRIMARY KEY</td>
                    <td>Unique log ID</td>
                </tr>
                <tr>
                    <td><code>admin_id</code></td>
                    <td>INT NOT NULL</td>
                    <td>Admin user ID</td>
                </tr>
                <tr>
                    <td><code>admin_username</code></td>
                    <td>VARCHAR(100) NOT NULL</td>
                    <td>Admin name for display</td>
                </tr>
                <tr>
                    <td><code>action</code></td>
                    <td>VARCHAR(20) NOT NULL</td>
                    <td>Add, Edit, Delete</td>
                </tr>
                <tr>
                    <td><code>target</code></td>
                    <td>VARCHAR(50) NOT NULL</td>
                    <td>What was changed (User, Employee Role, etc)</td>
                </tr>
                <tr>
                    <td><code>details</code></td>
                    <td>TEXT</td>
                    <td>Description of change</td>
                </tr>
                <tr>
                    <td><code>created_at</code></td>
                    <td>TIMESTAMP DEFAULT CURRENT_TIMESTAMP</td>
                    <td>When it happened</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Fix Options</h2>
            
            <h3>Option 1: Auto-Fix (Recommended) ✓</h3>
            <p>This button will automatically recreate the table with the correct structure (existing logs will be deleted):</p>
            <form method="POST">
                <button type="submit" name="action" value="fix" class="btn">
                    🔧 Auto-Fix Table
                </button>
            </form>

            <h3 style="margin-top: 30px;">Option 2: Manual SQL</h3>
            <p>Run this SQL in phpMyAdmin if auto-fix doesn't work:</p>
            <pre>-- Drop old table
DROP TABLE IF EXISTS activity_logs;

-- Create new table with correct structure
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
        </div>

        <?php
        // Handle the fix action
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix') {
            try {
                echo '<div class="section">';
                echo '<h2>Fixing Table...</h2>';
                
                // Drop old table
                echo '<p>1. Dropping old table...</p>';
                try {
                    $pdo->exec("DROP TABLE IF EXISTS activity_logs");
                    echo '<div class="status success">✓ Old table dropped (or didn\'t exist)</div>';
                } catch (Exception $e) {
                    echo '<div class="status error">✗ Error dropping table: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    throw $e;
                }
                
                // Create new table
                echo '<p>2. Creating new table with correct columns...</p>';
                try {
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
                    echo '<div class="status success">✓ Table created successfully!</div>';
                } catch (Exception $e) {
                    echo '<div class="status error">✗ Error creating table: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    throw $e;
                }
                
                // Verify
                echo '<p>3. Verifying table structure...</p>';
                try {
                    $stmt = $pdo->query("
                        SELECT COLUMN_NAME 
                        FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
                    ");
                    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $required = ['id', 'admin_id', 'admin_username', 'action', 'target', 'details', 'created_at'];
                    $missing = array_diff($required, $columns);
                    
                    if (empty($missing)) {
                        echo '<div class="status success">✓ All required columns present!</div>';
                        echo '<p><strong>Next Steps:</strong></p>';
                        echo '<ol>';
                        echo '<li>Go back to <a href="/UniDiPaypro/php/diagnostic.php">Diagnostic Page</a></li>';
                        echo '<li>Verify "No logs recorded yet (0 total)" should now show without error</li>';
                        echo '<li>Go to User Management and test adding a user</li>';
                        echo '<li>Check Recent Activity section at bottom</li>';
                        echo '</ol>';
                    } else {
                        echo '<div class="status error">✗ Still missing columns: ' . implode(', ', $missing) . '</div>';
                    }
                } catch (Exception $e) {
                    echo '<div class="status error">✗ Error verifying table: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
                
                echo '</div>';
            } catch (Exception $e) {
                echo '<div class="status error">✗ Fix failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
        ?>

        <div class="section" style="border-left-color: #10B981;">
            <h2>After Fixing</h2>
            <ol>
                <li>Click the button above to auto-fix the table</li>
                <li>Wait for the success message</li>
                <li>Go to <a href="/UniDiPaypro/php/diagnostic.php">Diagnostic Page</a> to verify</li>
                <li>Go to User Management and test adding a user</li>
                <li>Scroll down to "Recent Activity" to see logs</li>
            </ol>
        </div>

        <p style="color: #888; font-size: 12px; margin-top: 30px;">
            Fix file: <code>php/fix_activity_logs.php</code><br>
            You can delete this file after the table is fixed.
        </p>
    </div>
</body>
</html>
