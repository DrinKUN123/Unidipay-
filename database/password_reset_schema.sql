-- UniDiPay student auth + password reset migration
-- Apply this once in production to enforce schema at DB level.

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS password_set_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS legacy_rfid_login_enabled TINYINT(1) NOT NULL DEFAULT 1;

-- If this fails due to duplicate emails, clean duplicates first, then re-run.
CREATE UNIQUE INDEX uq_students_email ON students (email);

CREATE TABLE IF NOT EXISTS mobile_password_reset_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    requested_ip VARCHAR(45) NULL,
    requested_user_agent VARCHAR(255) NULL,
    used_ip VARCHAR(45) NULL,
    used_user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_student (student_id),
    INDEX idx_reset_expires (expires_at),
    CONSTRAINT fk_reset_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
