-- UniDiPay Database Setup Script
-- Create employees table with roles (manager, staff, cashier)

CREATE TABLE IF NOT EXISTS employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('manager', 'staff', 'cashier') NOT NULL DEFAULT 'staff',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add indexes
ALTER TABLE employees ADD INDEX idx_email (email);
ALTER TABLE employees ADD INDEX idx_role (role);
ALTER TABLE employees ADD INDEX idx_status (status);

-- Sample data (optional)
-- INSERT INTO employees (name, email, password, role) VALUES 
-- ('Manager User', 'manager@unidipay.com', '$2y$10$...', 'manager'),
-- ('Staff User', 'staff@unidipay.com', '$2y$10$...', 'staff'),
-- ('Cashier User', 'cashier@unidipay.com', '$2y$10$...', 'cashier');
