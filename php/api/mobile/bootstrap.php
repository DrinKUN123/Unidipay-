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

function mobileGetAuthenticatedStudent() {
    global $pdo;

    $token = mobileGetBearerToken();
    if (!$token) {
        sendResponse(['error' => 'Missing bearer token'], 401);
    }

    mobileEnsureTokenTable();
    $tokenHash = mobileHashToken($token);

    $stmt = $pdo->prepare("\n        SELECT\n            mt.id as token_id,\n            mt.student_id,\n            s.name,\n            s.student_id as student_number,\n            s.program,\n            s.year_level,\n            s.nfc_card_id,\n            nc.balance\n        FROM mobile_student_tokens mt\n        INNER JOIN students s ON s.id = mt.student_id\n        LEFT JOIN nfc_cards nc ON nc.id = s.nfc_card_id\n        WHERE mt.token_hash = ?\n          AND mt.revoked_at IS NULL\n          AND mt.expires_at > NOW()\n        LIMIT 1\n    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        sendResponse(['error' => 'Invalid or expired token'], 401);
    }

    $touch = $pdo->prepare('UPDATE mobile_student_tokens SET last_used_at = NOW() WHERE id = ?');
    $touch->execute([$row['token_id']]);

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
