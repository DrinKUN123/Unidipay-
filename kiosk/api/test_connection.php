<?php
/**
 * Database Connection Test Script
 * Access this file to verify database connection
 * URL: http://localhost/unidipay-kiosk/api/test_connection.php
 */

require_once 'config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniDiPay - Database Connection Test</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 800px;
            width: 100%;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }
        
        h1 {
            color: #2563EB;
            margin-bottom: 10px;
            font-size: 2rem;
        }
        
        h2 {
            color: #1F2937;
            margin: 30px 0 15px 0;
            font-size: 1.5rem;
            border-bottom: 2px solid #E5E7EB;
            padding-bottom: 10px;
        }
        
        .subtitle {
            color: #6B7280;
            margin-bottom: 30px;
        }
        
        .status {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .status.success {
            background: #DEF7EC;
            color: #03543F;
            border-left: 4px solid #22C55E;
        }
        
        .status.error {
            background: #FDE8E8;
            color: #9B1C1C;
            border-left: 4px solid #EF4444;
        }
        
        .info-grid {
            display: grid;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .info-row {
            display: flex;
            padding: 10px;
            background: #F9FAFB;
            border-radius: 6px;
        }
        
        .info-label {
            font-weight: 500;
            color: #4B5563;
            min-width: 150px;
        }
        
        .info-value {
            color: #1F2937;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        th {
            background: #F3F4F6;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #E5E7EB;
        }
        
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #E5E7EB;
            color: #1F2937;
        }
        
        tr:hover {
            background: #F9FAFB;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .badge.success {
            background: #DEF7EC;
            color: #03543F;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2563EB;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            margin-top: 20px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #1D4ED8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏥 UniDiPay System Status</h1>
        <p class="subtitle">Database Connection & Configuration Test</p>
        
        <?php
        $conn = getDBConnection();
        
        if ($conn) {
            echo '<div class="status success">✓ Database connection successful!</div>';
            
            // Database info
            echo '<h2>📊 Database Information</h2>';
            echo '<div class="info-grid">';
            echo '<div class="info-row"><span class="info-label">Host:</span><span class="info-value">' . DB_HOST . '</span></div>';
            echo '<div class="info-row"><span class="info-label">Database:</span><span class="info-value">' . DB_NAME . '</span></div>';
            echo '<div class="info-row"><span class="info-label">User:</span><span class="info-value">' . DB_USER . '</span></div>';
            
            // Get MySQL version
            $version = $conn->query("SELECT VERSION()")->fetchColumn();
            echo '<div class="info-row"><span class="info-label">MySQL Version:</span><span class="info-value">' . $version . '</span></div>';
            echo '</div>';
            
            // Check tables
            echo '<h2>📋 Database Tables Status</h2>';
            $tables = ['students', 'nfc_cards', 'menu_items', 'orders', 'order_items', 'transactions', 'admins'];
            echo '<table>';
            echo '<tr><th>Table Name</th><th>Record Count</th><th>Status</th></tr>';
            
            foreach ($tables as $table) {
                try {
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM $table");
                    $count = $stmt->fetchColumn();
                    echo "<tr><td>$table</td><td>$count</td><td><span class='badge success'>✓ OK</span></td></tr>";
                } catch (Exception $e) {
                    echo "<tr><td>$table</td><td>-</td><td><span class='badge error'>✗ Error</span></td></tr>";
                }
            }
            echo '</table>';
            
            // Test RFID Cards
            echo '<h2>💳 Available RFID Cards</h2>';
            try {
                $stmt = $conn->query("
                    SELECT nc.id, s.name, s.student_id, nc.balance 
                    FROM nfc_cards nc 
                    INNER JOIN students s ON nc.student_id = s.id 
                    ORDER BY nc.id
                ");
                $cards = $stmt->fetchAll();
                
                if (count($cards) > 0) {
                    echo '<table>';
                    echo '<tr><th>Card ID</th><th>Student Name</th><th>Student ID</th><th>Balance</th></tr>';
                    foreach ($cards as $card) {
                        $balance = number_format($card['balance'], 2);
                        echo "<tr><td>{$card['id']}</td><td>{$card['name']}</td><td>{$card['student_id']}</td><td>₱{$balance}</td></tr>";
                    }
                    echo '</table>';
                } else {
                    echo '<p>No RFID Cards found in database.</p>';
                }
            } catch (Exception $e) {
                echo '<div class="status error">Error loading RFID Cards: ' . $e->getMessage() . '</div>';
            }
            
            // Menu Items Count by Category
            echo '<h2>🍽️ Menu Items by Category</h2>';
            try {
                $stmt = $conn->query("
                    SELECT category, COUNT(*) as count, SUM(available = 1) as available_count
                    FROM menu_items 
                    GROUP BY category
                    ORDER BY category
                ");
                $categories = $stmt->fetchAll();
                
                if (count($categories) > 0) {
                    echo '<table>';
                    echo '<tr><th>Category</th><th>Total Items</th><th>Available Items</th></tr>';
                    foreach ($categories as $cat) {
                        echo "<tr><td>{$cat['category']}</td><td>{$cat['count']}</td><td>{$cat['available_count']}</td></tr>";
                    }
                    echo '</table>';
                } else {
                    echo '<p>No menu items found in database.</p>';
                }
            } catch (Exception $e) {
                echo '<div class="status error">Error loading menu items: ' . $e->getMessage() . '</div>';
            }
            
        } else {
            echo '<div class="status error">✗ Database connection failed!</div>';
            echo '<p>Please check your database configuration in <code>api/config.php</code></p>';
            echo '<div class="info-grid">';
            echo '<div class="info-row"><span class="info-label">Expected Host:</span><span class="info-value">' . DB_HOST . '</span></div>';
            echo '<div class="info-row"><span class="info-label">Expected Database:</span><span class="info-value">' . DB_NAME . '</span></div>';
            echo '<div class="info-row"><span class="info-label">Expected User:</span><span class="info-value">' . DB_USER . '</span></div>';
            echo '</div>';
        }
        ?>
        
        <a href="../index.html" class="btn">← Back to Kiosk</a>
    </div>
</body>
</html>
