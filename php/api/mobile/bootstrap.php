<?php
require_once '../../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function mobileJsonInput() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function mobileGenerateToken() {
    return bin2hex(random_bytes(32));
}

function mobileHashToken($token) {
    return hash('sha256', $token);
}

function mobileNormalizeEmail($email) {
    return strtolower(trim((string)$email));
}

function mobileValidatePasswordStrength($password) {
    if (!is_string($password) || strlen($password) < 10) {
        return 'Password must be at least 10 characters long';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one special character';
    }

    return null;
}

function mobileMaskEmail($email) {
    $email = (string)$email;
    if (strpos($email, '@') === false) {
        return '***';
    }

    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 2) {
        $localMasked = str_repeat('*', max(1, strlen($local)));
    } else {
        $localMasked = substr($local, 0, 1) . str_repeat('*', strlen($local) - 2) . substr($local, -1);
    }

    return $localMasked . '@' . $domain;
}

function mobileStudentStatusSelectExpression($tableAlias = '') {
    if (!mobileColumnExists('students', 'status')) {
        return "'active' AS status";
    }

    $alias = trim((string)$tableAlias);
    if ($alias !== '') {
        return "COALESCE({$alias}.status, 'active') AS status";
    }

    return "COALESCE(status, 'active') AS status";
}

function mobileGetBearerToken() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!$header) {
        return null;
    }

    if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function mobileEnsureTokenTable() {
    global $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_student_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL UNIQUE,
        device_name VARCHAR(120) NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_tokens_student (student_id),
        INDEX idx_mobile_tokens_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mobileEnsureStudentAuthSchema() {
    global $pdo;

    if (!mobileColumnExists('students', 'email')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN email VARCHAR(120) NULL");
    }

    if (!mobileColumnExists('students', 'password_hash')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN password_hash VARCHAR(255) NULL");
    }

    if (!mobileColumnExists('students', 'password_set_at')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN password_set_at DATETIME NULL");
    }

    if (!mobileColumnExists('students', 'legacy_rfid_login_enabled')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN legacy_rfid_login_enabled TINYINT(1) NOT NULL DEFAULT 1");
    }

    if (!mobileIndexExists('students', 'idx_students_auth_email')) {
        $pdo->exec("CREATE INDEX idx_students_auth_email ON students (email)");
    }
}

function mobileEnsurePasswordResetTable() {
    global $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_password_reset_tokens (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mobileCleanupPasswordResetTokens() {
    global $pdo;

    $stmt = $pdo->prepare("\n        DELETE FROM mobile_password_reset_tokens\n        WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 30 DAY))\n           OR (expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY))\n    ");
    $stmt->execute();
}

function mobileIsPasswordResetThrottled($studentId, $ip) {
    global $pdo;

    $studentStmt = $pdo->prepare("\n        SELECT COUNT(*) AS total\n        FROM mobile_password_reset_tokens\n        WHERE student_id = ?\n          AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)\n    ");
    $studentStmt->execute([intval($studentId)]);
    $studentCount = intval(($studentStmt->fetch()['total'] ?? 0));

    if ($studentCount >= 3) {
        return true;
    }

    $ipStmt = $pdo->prepare("\n        SELECT COUNT(*) AS total\n        FROM mobile_password_reset_tokens\n        WHERE requested_ip = ?\n          AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)\n    ");
    $ipStmt->execute([$ip]);
    $ipCount = intval(($ipStmt->fetch()['total'] ?? 0));

    return $ipCount >= 10;
}

function mobileColumnExists($table, $column) {
    global $pdo;

    $stmt = $pdo->prepare("\n        SELECT COUNT(*) AS total\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n          AND COLUMN_NAME = ?\n    ");
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch();

    return intval($row['total'] ?? 0) > 0;
}

function mobileIndexExists($table, $indexName) {
    global $pdo;

    $stmt = $pdo->prepare("\n        SELECT COUNT(*) AS total\n        FROM INFORMATION_SCHEMA.STATISTICS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n          AND INDEX_NAME = ?\n    ");
    $stmt->execute([$table, $indexName]);
    $row = $stmt->fetch();

    return intval($row['total'] ?? 0) > 0;
}

function mobileIssueStudentToken($studentId, $deviceName = null) {
    global $pdo;

    mobileEnsureTokenTable();

    $token = mobileGenerateToken();
    $tokenHash = mobileHashToken($token);

    $insert = $pdo->prepare("\n        INSERT INTO mobile_student_tokens (student_id, token_hash, device_name, expires_at)\n        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))\n    ");
    $insert->execute([intval($studentId), $tokenHash, $deviceName]);

    return $token;
}

function mobileBuildPasswordResetLink($rawToken) {
    $configuredBase = trim((string)getenv('UNIDIPAY_RESET_BASE_URL'));

    if ($configuredBase !== '') {
        $separator = strpos($configuredBase, '?') === false ? '?' : '&';
        return $configuredBase . $separator . 'token=' . urlencode($rawToken);
    }

    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = ($https && $https !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = $scheme . '://' . $host . '/unidipaypro/mobile-reset.html';

    return $base . '?token=' . urlencode($rawToken);
}

function mobileLoadMailerLocalConfig() {
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $path = __DIR__ . '/mailer.local.php';
    if (!file_exists($path)) {
        $cached = [];
        return $cached;
    }

    $loaded = require $path;
    $cached = is_array($loaded) ? $loaded : [];
    return $cached;
}

function mobileMailerConfigValue($envKey, $localKey, $default = '') {
    $envValue = trim((string)getenv($envKey));
    if ($envValue !== '') {
        return $envValue;
    }

    $local = mobileLoadMailerLocalConfig();
    if (array_key_exists($localKey, $local)) {
        return trim((string)$local[$localKey]);
    }

    return $default;
}

function mobileGetMailerConfig() {
    $host = mobileMailerConfigValue('UNIDIPAY_SMTP_HOST', 'host', '');
    $port = intval(mobileMailerConfigValue('UNIDIPAY_SMTP_PORT', 'port', '587'));
    $username = mobileMailerConfigValue('UNIDIPAY_SMTP_USERNAME', 'username', '');
    $password = mobileMailerConfigValue('UNIDIPAY_SMTP_PASSWORD', 'password', '');
    $encryption = strtolower(mobileMailerConfigValue('UNIDIPAY_SMTP_ENCRYPTION', 'encryption', 'tls'));
    $fromEmail = mobileMailerConfigValue('UNIDIPAY_SMTP_FROM_EMAIL', 'from_email', 'no-reply@unidipay.local');
    $fromName = mobileMailerConfigValue('UNIDIPAY_SMTP_FROM_NAME', 'from_name', 'UniDiPay');

    if ($encryption === '') {
        $encryption = 'tls';
    }

    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }

    if ($fromEmail === '') {
        $fromEmail = 'no-reply@unidipay.local';
    }

    if ($fromName === '') {
        $fromName = 'UniDiPay';
    }

    if ($username === '' || strpos($username, '@') === false) {
        if (strpos($fromEmail, '@') !== false) {
            $username = $fromEmail;
        }
    }

    $passwordLower = strtolower(trim((string)$password));
    $isPlaceholderPassword = in_array($passwordLower, [
        '',
        'your-app-password',
        'app-password',
        'changeme',
    ], true);

    $smtpEnabled = $host !== '' && !$isPlaceholderPassword;

    if (strpos(strtolower($host), 'gmail') !== false) {
        $password = str_replace(' ', '', (string)$password);
    }

    return [
        'enabled' => $smtpEnabled,
        'host' => $host,
        'port' => $port > 0 ? $port : 587,
        'username' => $username,
        'password' => $password,
        'encryption' => $encryption,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
    ];
}

function mobileSmtpRead($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $response;
}

function mobileSmtpCommand($socket, $command, $expectedCodes) {
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }

    $response = mobileSmtpRead($socket);
    $code = intval(substr($response, 0, 3));

    if (!in_array($code, $expectedCodes, true)) {
        throw new Exception('SMTP command failed: ' . trim($response));
    }

    return $response;
}

function mobileBuildEmailAddress($email, $name) {
    $safeEmail = trim((string)$email);
    $safeName = trim((string)$name);

    if ($safeName === '') {
        return '<' . $safeEmail . '>';
    }

    $quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $safeName);
    return '"' . $quoted . '" <' . $safeEmail . '>';
}

function mobileSendViaSmtp($recipientEmail, $subject, $message, $config) {
    $hostPrefix = $config['encryption'] === 'ssl' ? 'ssl://' : '';
    $timeout = 15;
    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $hostPrefix . $config['host'],
        intval($config['port']),
        $errno,
        $errstr,
        $timeout
    );

    if (!$socket) {
        throw new Exception('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
    }

    try {
        stream_set_timeout($socket, $timeout);

        mobileSmtpCommand($socket, null, [220]);
        mobileSmtpCommand($socket, 'EHLO unidipay.local', [250]);

        if ($config['encryption'] === 'tls') {
            mobileSmtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('SMTP STARTTLS negotiation failed');
            }
            mobileSmtpCommand($socket, 'EHLO unidipay.local', [250]);
        }

        if ($config['username'] !== '' && $config['password'] !== '') {
            mobileSmtpCommand($socket, 'AUTH LOGIN', [334]);
            mobileSmtpCommand($socket, base64_encode($config['username']), [334]);
            mobileSmtpCommand($socket, base64_encode($config['password']), [235]);
        }

        $fromEnvelope = '<' . $config['from_email'] . '>';
        $toEnvelope = '<' . $recipientEmail . '>';

        mobileSmtpCommand($socket, 'MAIL FROM:' . $fromEnvelope, [250]);
        mobileSmtpCommand($socket, 'RCPT TO:' . $toEnvelope, [250, 251]);
        mobileSmtpCommand($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mobileBuildEmailAddress($config['from_email'], $config['from_name']),
            'To: ' . mobileBuildEmailAddress($recipientEmail, ''),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $message;
        $data = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $data);

        fwrite($socket, $data . "\r\n.\r\n");
        mobileSmtpCommand($socket, null, [250]);
        mobileSmtpCommand($socket, 'QUIT', [221]);

        fclose($socket);
        return true;
    } catch (Exception $e) {
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        throw $e;
    }
}

function mobileSendPasswordResetEmail($recipientEmail, $studentName, $resetLink) {
    $subject = 'UniDiPay Password Reset';

    $safeName = $studentName ? $studentName : 'Student';
    $message = "Hello {$safeName},\n\n" .
        "We received a request to reset your UniDiPay password.\n" .
        "Use the secure link below within 15 minutes:\n\n" .
        $resetLink . "\n\n" .
        "If you did not request this, you can ignore this email.\n\n" .
        "- UniDiPay Security";

    $config = mobileGetMailerConfig();
    $sent = false;

    if ($config['enabled']) {
        try {
            $sent = mobileSendViaSmtp($recipientEmail, $subject, $message, $config);
        } catch (Exception $e) {
            error_log('UniDiPay SMTP send failed: ' . $e->getMessage());
        }
    }

    if (!$sent) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/plain; charset=UTF-8',
            'From: ' . mobileBuildEmailAddress($config['from_email'], $config['from_name'])
        ];

        $sent = @mail($recipientEmail, $subject, $message, implode("\r\n", $headers));
    }

    $appEnv = strtolower((string)getenv('APP_ENV'));
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if ($sent && ($appEnv === 'local' || $appEnv === 'development' || $isLocal)) {
        error_log('UniDiPay reset email sent to ' . $recipientEmail);
    }

    if (!$sent && ($appEnv === 'local' || $appEnv === 'development' || $isLocal)) {
        error_log('UniDiPay reset fallback link for ' . $recipientEmail . ': ' . $resetLink);
    }

    return $sent;
}

function mobileGetAuthenticatedStudent($requirePasswordSet = true) {
    global $pdo;

    $token = mobileGetBearerToken();
    if (!$token) {
        sendResponse(['error' => 'Missing bearer token'], 401);
    }

    mobileEnsureStudentAuthSchema();
    mobileEnsureTokenTable();
    $tokenHash = mobileHashToken($token);
    $statusSelect = mobileStudentStatusSelectExpression('s');

    $stmt = $pdo->prepare("\n        SELECT\n            mt.id as token_id,\n            mt.student_id,\n            s.name,\n            s.student_id as student_number,\n            s.program,\n            s.year_level,\n            s.nfc_card_id,\n            s.email,\n            s.password_hash,\n            {$statusSelect},\n            nc.balance\n        FROM mobile_student_tokens mt\n        INNER JOIN students s ON s.id = mt.student_id\n        LEFT JOIN nfc_cards nc ON nc.id = s.nfc_card_id\n        WHERE mt.token_hash = ?\n          AND mt.revoked_at IS NULL\n          AND mt.expires_at > NOW()\n        LIMIT 1\n    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        sendResponse(['error' => 'Invalid or expired token'], 401);
    }

    $touch = $pdo->prepare('UPDATE mobile_student_tokens SET last_used_at = NOW() WHERE id = ?');
    $touch->execute([$row['token_id']]);

    if (($row['status'] ?? 'active') !== 'active') {
        sendResponse(['error' => 'Student account is inactive'], 403);
    }

    if ($requirePasswordSet && empty($row['password_hash'])) {
        sendResponse([
            'error' => 'Account setup required. Set your email and password to continue.',
            'code' => 'ACCOUNT_SETUP_REQUIRED'
        ], 403);
    }

    return $row;
}

function mobileInsertReloadCompatible($cardId, $amount, $balanceBefore, $balanceAfter, $notes = null) {
    global $pdo;

    $adminId = getEffectiveAdminId();

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO reloads (card_id, amount, admin_id, employee_id, balance_before, balance_after)\n            VALUES (?, ?, ?, NULL, ?, ?)\n        ");
        $stmt->execute([$cardId, $amount, $adminId, $balanceBefore, $balanceAfter]);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S22') {
            if ($adminId === null) {
                throw new Exception('No admin available to record reload');
            }

            $fallback = $pdo->prepare("\n                INSERT INTO reloads (card_id, amount, admin_id, balance_before, balance_after)\n                VALUES (?, ?, ?, ?, ?)\n            ");
            $fallback->execute([$cardId, $amount, $adminId, $balanceBefore, $balanceAfter]);
        } else {
            throw $e;
        }
    }

    if ($notes) {
        try {
            $stmt = $pdo->prepare("\n                INSERT INTO transactions (card_id, type, amount, reason, balance_before, balance_after, created_at)\n                VALUES (?, 'credit', ?, ?, ?, ?, NOW())\n            ");
            $stmt->execute([$cardId, $amount, $notes, $balanceBefore, $balanceAfter]);
        } catch (Exception $e) {
            // Keep reload success even if transaction schema differs.
        }
    }
}
