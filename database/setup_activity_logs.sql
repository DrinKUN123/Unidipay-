-- =====================================================
-- ACTIVITY LOGS TABLE - Complete Setup
-- Run this in phpMyAdmin or MySQL command line
-- =====================================================

-- Drop existing table if needed (ONLY if you want to reset)
-- DROP TABLE IF EXISTS activity_logs;

-- Create Activity Logs Table
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    admin_username VARCHAR(100) NOT NULL,
    action VARCHAR(20) NOT NULL,
    target VARCHAR(50) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_created (target, created_at DESC),
    INDEX idx_admin_id (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify table was created
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'activity_logs' 
ORDER BY ORDINAL_POSITION;
