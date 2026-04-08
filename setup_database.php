<?php
/**
 * UniDiPay Database Setup Script
 * Creates all necessary tables
 */

// Database credentials
$host = 'localhost';
$dbname = 'unidipay_db';
$user = 'root';
$pass = '';

try {
    // Connect to MySQL server (not a specific database yet)
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Select the database
    $pdo->exec("USE $dbname");
    
    echo "✓ Database selected/created<br>";
    
    // Create admins table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE,
            password VARCHAR(255),
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Admins table created<br>";
    
    // Create employees table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE,
            password VARCHAR(255),
            role ENUM('manager', 'staff', 'cashier') DEFAULT 'staff',
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Employees table created<br>";
    
    // Create students table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS students (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            phone VARCHAR(20),
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Students table created<br>";
    
    // Create menu_items table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS menu_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            category VARCHAR(50),
            available TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Menu Items table created<br>";
    
    // Create nfc_cards table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nfc_cards (
            id VARCHAR(50) PRIMARY KEY,
            student_id INT NOT NULL,
            balance DECIMAL(10,2) DEFAULT 0,
            status ENUM('active', 'inactive', 'blocked') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ NFC Cards table created<br>";
    
    // Create reloads table (CRITICAL - was missing!)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reloads (
            id INT PRIMARY KEY AUTO_INCREMENT,
            card_id VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            balance_before DECIMAL(10,2),
            balance_after DECIMAL(10,2),
            admin_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (card_id) REFERENCES nfc_cards(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Reloads table created<br>";
    
    // Create transactions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            card_id VARCHAR(50) NOT NULL,
            type ENUM('debit', 'credit') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            balance_before DECIMAL(10,2),
            balance_after DECIMAL(10,2),
            reason VARCHAR(200),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (card_id) REFERENCES nfc_cards(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Transactions table created<br>";
    
    // Create orders table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            total DECIMAL(10,2) NOT NULL,
            status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Orders table created<br>";
    
    // Create order_items table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            order_id INT NOT NULL,
            menu_item_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Order Items table created<br>";
    
    // Add indexes for performance
    $pdo->exec("ALTER TABLE reloads ADD INDEX idx_card_id (card_id)");
    $pdo->exec("ALTER TABLE reloads ADD INDEX idx_created_at (created_at)");
    $pdo->exec("ALTER TABLE transactions ADD INDEX idx_card_id (card_id)");
    $pdo->exec("ALTER TABLE transactions ADD INDEX idx_type (type)");
    $pdo->exec("ALTER TABLE transactions ADD INDEX idx_created_at (created_at)");
    $pdo->exec("ALTER TABLE orders ADD INDEX idx_student_id (student_id)");
    $pdo->exec("ALTER TABLE orders ADD INDEX idx_status (status)");
    $pdo->exec("ALTER TABLE orders ADD INDEX idx_created_at (created_at)");
    echo "✓ Indexes added<br>";
    
    echo "<div style='color: #22c55e; padding: 20px; margin-top: 20px; border: 2px solid #22c55e; border-radius: 8px; background: #f0fdf4;'>";
    echo "<strong>✓ Database setup completed successfully!</strong><br>";
    echo "All tables created and ready to use.<br>";
    echo "You can now delete or rename this file: <code>setup_database.php</code>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='color: #ef4444; padding: 20px; margin-top: 20px; border: 2px solid #ef4444; border-radius: 8px; background: #fef2f2;'>";
    echo "<strong>✗ Error setting up database:</strong><br>";
    echo htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>
