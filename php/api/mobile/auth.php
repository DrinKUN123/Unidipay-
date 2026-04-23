<?php
require_once 'bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
actionRouter();

function actionRouter() {
    global $method;
    $action = $_GET['action'] ?? '';

    try {
        if ($method === 'POST' && $action === 'login') {
            mobileLogin();
            return;
        }

        if ($method === 'POST' && $action === 'set_initial_credentials') {
            mobileSetInitialCredentials();
            return;
        }

        if ($method === 'POST' && $action === 'request_password_reset') {
            mobileRequestPasswordReset();
            return;
        }

        if ($method === 'GET' && $action === 'validate_reset_token') {
            mobileValidateResetToken();
            return;
        }

        if ($method === 'POST' && $action === 'reset_password') {
            mobileResetPassword();
            return;
        }

        if ($method === 'POST' && $action === 'logout') {
            mobileLogout();
            return;
        }

        if ($method === 'GET' && $action === 'me') {
            mobileMe();
            return;
        }

        sendResponse(['error' => 'Invalid action'], 400);
    } catch (Exception $e) {
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

function mobileLogin() {
    mobileEnsureStudentAuthSchema();
    $data = mobileJsonInput();

    $deviceName = isset($data['device_name']) ? sanitize($data['device_name']) : null;
    $identifier = mobileNormalizeEmail($data['identifier'] ?? '');
    $password = $data['password'] ?? null;
    $studentNumber = sanitize($data['student_id'] ?? '');
    $cardId = sanitize($data['nfc_card_id'] ?? '');

    if ($identifier !== '' || $password !== null) {
        mobileLoginWithEmailPassword($identifier, $password, $deviceName);
        return;
    }

    if ($studentNumber !== '' && $cardId !== '') {
        mobileLoginWithRfid($studentNumber, $cardId, $deviceName);
        return;
    }

    sendResponse([
        'error' => 'Provide either email/password or student_id/nfc_card_id credentials'
    ], 400);
}

function mobileLoginWithEmailPassword($identifier, $password, $deviceName) {
    global $pdo;

    $statusSelect = mobileStudentStatusSelectExpression('s');

    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'Invalid email or password'], 401);
    }

    if (!is_string($password) || trim($password) === '') {
        sendResponse(['error' => 'Invalid email or password'], 401);
    }

    $stmt = $pdo->prepare("\n        SELECT\n            s.id,\n            s.name,\n            s.student_id,\n            s.program,\n            s.year_level,\n            s.nfc_card_id,\n            s.email,\n            s.password_hash,\n            {$statusSelect},\n            nc.balance\n        FROM students s\n        LEFT JOIN nfc_cards nc ON nc.id = s.nfc_card_id\n        WHERE LOWER(s.email) = ?\n        LIMIT 2\n    ");
    $stmt->execute([$identifier]);
    $students = $stmt->fetchAll();

    if (count($students) !== 1) {
        sendResponse(['error' => 'Invalid email or password'], 401);
    }

    $student = $students[0];

    if (!$student || ($student['status'] ?? 'active') !== 'active' || empty($student['password_hash'])) {
        sendResponse(['error' => 'Invalid email or password'], 401);
    }

    if (!password_verify($password, $student['password_hash'])) {
        sendResponse(['error' => 'Invalid email or password'], 401);
    }

    $token = mobileIssueStudentToken($student['id'], $deviceName);
    sendResponse(mobileBuildLoginResponse($student, $token, false));
}

function mobileLoginWithRfid($studentNumber, $cardId, $deviceName) {
    global $pdo;

    $statusSelect = mobileStudentStatusSelectExpression('s');

    $stmt = $pdo->prepare("\n        SELECT\n            s.id,\n            s.name,\n            s.student_id,\n            s.program,\n            s.year_level,\n            s.nfc_card_id,\n            s.email,\n            s.password_hash,\n            {$statusSelect},\n            s.legacy_rfid_login_enabled,\n            nc.balance\n        FROM students s\n        LEFT JOIN nfc_cards nc ON nc.id = s.nfc_card_id\n        WHERE s.student_id = ?\n          AND s.nfc_card_id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$studentNumber, $cardId]);
    $student = $stmt->fetch();

    if (!$student || ($student['status'] ?? 'active') !== 'active') {
        sendResponse(['error' => 'Invalid student credentials'], 401);
    }

    if (!empty($student['password_hash']) || intval($student['legacy_rfid_login_enabled'] ?? 1) === 0) {
        sendResponse([
            'error' => 'This account now requires email and password login',
            'code' => 'EMAIL_PASSWORD_REQUIRED'
        ], 403);
    }

    $token = mobileIssueStudentToken($student['id'], $deviceName);
    sendResponse(mobileBuildLoginResponse($student, $token, true));
}

function mobileBuildLoginResponse($student, $token, $requiresPasswordSetup) {
    return [
        'success' => true,
        'token' => $token,
        'requires_password_setup' => $requiresPasswordSetup,
        'student' => [
            'id' => intval($student['id']),
            'name' => $student['name'],
            'student_id' => $student['student_id'],
            'program' => $student['program'],
            'year_level' => $student['year_level'],
            'nfc_card_id' => $student['nfc_card_id'],
            'email' => $student['email'] ?? null,
            'balance' => floatval($student['balance'] ?? 0)
        ]
    ];
}

function mobileSetInitialCredentials() {
    global $pdo;

    mobileEnsureStudentAuthSchema();
    $student = mobileGetAuthenticatedStudent(false);
    $data = mobileJsonInput();

    $errors = validateRequired($data, ['email', 'password', 'confirm_password']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }

    $email = mobileNormalizeEmail($data['email']);
    $password = (string)$data['password'];
    $confirmPassword = (string)$data['confirm_password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'Invalid email format'], 400);
    }

    if ($password !== $confirmPassword) {
        sendResponse(['error' => 'Password confirmation does not match'], 400);
    }

    $passwordError = mobileValidatePasswordStrength($password);
    if ($passwordError !== null) {
        sendResponse(['error' => $passwordError], 400);
    }

    $pdo->beginTransaction();
    try {
        $lockStmt = $pdo->prepare('SELECT id, password_hash FROM students WHERE id = ? FOR UPDATE');
        $lockStmt->execute([intval($student['student_id'])]);
        $locked = $lockStmt->fetch();

        if (!$locked) {
            throw new Exception('Student account not found');
        }

        if (!empty($locked['password_hash'])) {
            throw new Exception('Credentials already set. Use forgot password if you need to reset.');
        }

        $emailStmt = $pdo->prepare('SELECT id FROM students WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
        $emailStmt->execute([$email, intval($student['student_id'])]);
        if ($emailStmt->fetch()) {
            throw new Exception('Email is already in use');
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $update = $pdo->prepare("\n            UPDATE students\n            SET email = ?,\n                password_hash = ?,\n                password_set_at = NOW(),\n                legacy_rfid_login_enabled = 0\n            WHERE id = ?\n        ");
        $update->execute([$email, $hashed, intval($student['student_id'])]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse(['error' => $e->getMessage()], 400);
    }

    sendResponse([
        'success' => true,
        'message' => 'Credentials set successfully. Use email and password for future logins.'
    ]);
}

function mobileRequestPasswordReset() {
    global $pdo;

    mobileEnsureStudentAuthSchema();
    mobileEnsurePasswordResetTable();
    mobileCleanupPasswordResetTokens();

    $data = mobileJsonInput();
    $errors = validateRequired($data, ['email']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }

    $email = mobileNormalizeEmail($data['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse([
            'success' => true,
            'message' => 'If the account exists, a password reset link has been sent.'
        ]);
    }

    $statusSelect = mobileStudentStatusSelectExpression('');
    $stmt = $pdo->prepare("\n        SELECT id, name, email, {$statusSelect}, password_hash\n        FROM students\n        WHERE LOWER(email) = ?\n        LIMIT 1\n    ");
    $stmt->execute([$email]);
    $student = $stmt->fetch();

    if ($student && ($student['status'] ?? 'active') === 'active' && !empty($student['password_hash'])) {
        $token = mobileGenerateToken();
        $tokenHash = mobileHashToken($token);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        if (mobileIsPasswordResetThrottled($student['id'], $ip)) {
            sendResponse([
                'success' => true,
                'message' => 'If the account exists, a password reset link has been sent.'
            ]);
        }

        $cleanup = $pdo->prepare("\n            DELETE FROM mobile_password_reset_tokens\n            WHERE student_id = ?\n              AND used_at IS NULL\n        ");
        $cleanup->execute([intval($student['id'])]);

        $insert = $pdo->prepare("\n            INSERT INTO mobile_password_reset_tokens (student_id, token_hash, expires_at, requested_ip, requested_user_agent)\n            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?, ?)\n        ");
        $insert->execute([intval($student['id']), $tokenHash, $ip, $userAgent]);

        $link = mobileBuildPasswordResetLink($token);
        mobileSendPasswordResetEmail($student['email'], $student['name'], $link);
    }

    sendResponse([
        'success' => true,
        'message' => 'If the account exists, a password reset link has been sent.'
    ]);
}

function mobileValidateResetToken() {
    global $pdo;

    mobileEnsurePasswordResetTable();
    mobileCleanupPasswordResetTokens();

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        sendResponse(['valid' => false, 'error' => 'Token is required'], 400);
    }

    $tokenHash = mobileHashToken($token);
    $stmt = $pdo->prepare("\n        SELECT prt.expires_at, prt.used_at, s.email\n        FROM mobile_password_reset_tokens prt\n        INNER JOIN students s ON s.id = prt.student_id\n        WHERE prt.token_hash = ?\n        LIMIT 1\n    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        sendResponse(['valid' => false, 'error' => 'Invalid or expired token'], 404);
    }

    if (!empty($row['used_at'])) {
        sendResponse(['valid' => false, 'error' => 'Token already used'], 400);
    }

    if (strtotime($row['expires_at']) <= time()) {
        sendResponse(['valid' => false, 'error' => 'Token expired'], 400);
    }

    sendResponse([
        'valid' => true,
        'expires_at' => $row['expires_at'],
        'email_hint' => mobileMaskEmail($row['email'] ?? '')
    ]);
}

function mobileResetPassword() {
    global $pdo;

    mobileEnsureStudentAuthSchema();
    mobileEnsurePasswordResetTable();
    mobileCleanupPasswordResetTokens();
    mobileEnsureTokenTable();

    $data = mobileJsonInput();
    $errors = validateRequired($data, ['token', 'new_password', 'confirm_password']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }

    $token = trim((string)$data['token']);
    $newPassword = (string)$data['new_password'];
    $confirmPassword = (string)$data['confirm_password'];

    if ($token === '') {
        sendResponse(['error' => 'Token is required'], 400);
    }

    if ($newPassword !== $confirmPassword) {
        sendResponse(['error' => 'Password confirmation does not match'], 400);
    }

    $passwordError = mobileValidatePasswordStrength($newPassword);
    if ($passwordError !== null) {
        sendResponse(['error' => $passwordError], 400);
    }

    $tokenHash = mobileHashToken($token);
    $usedIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $usedAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("\n            SELECT id, student_id, expires_at, used_at\n            FROM mobile_password_reset_tokens\n            WHERE token_hash = ?\n            FOR UPDATE\n        ");
        $stmt->execute([$tokenHash]);
        $resetRow = $stmt->fetch();

        if (!$resetRow) {
            throw new Exception('Invalid or expired token');
        }

        if (!empty($resetRow['used_at'])) {
            throw new Exception('Token already used');
        }

        if (strtotime($resetRow['expires_at']) <= time()) {
            throw new Exception('Token expired');
        }

        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);

        $updateStudent = $pdo->prepare("\n            UPDATE students\n            SET password_hash = ?,\n                password_set_at = NOW(),\n                legacy_rfid_login_enabled = 0\n            WHERE id = ?\n        ");
        $updateStudent->execute([$hashed, intval($resetRow['student_id'])]);

        $useToken = $pdo->prepare("\n            UPDATE mobile_password_reset_tokens\n            SET used_at = NOW(),\n                used_ip = ?,\n                used_user_agent = ?\n            WHERE id = ?\n        ");
        $useToken->execute([$usedIp, $usedAgent, intval($resetRow['id'])]);

        $revoke = $pdo->prepare("\n            UPDATE mobile_student_tokens\n            SET revoked_at = NOW()\n            WHERE student_id = ?\n              AND revoked_at IS NULL\n        ");
        $revoke->execute([intval($resetRow['student_id'])]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse(['error' => $e->getMessage()], 400);
    }

    sendResponse([
        'success' => true,
        'message' => 'Password reset successful. Please log in with your email and new password.'
    ]);
}

function mobileLogout() {
    global $pdo;

    $token = mobileGetBearerToken();
    if (!$token) {
        sendResponse(['error' => 'Missing bearer token'], 401);
    }

    $tokenHash = mobileHashToken($token);
    $stmt = $pdo->prepare("\n        UPDATE mobile_student_tokens\n        SET revoked_at = NOW()\n        WHERE token_hash = ? AND revoked_at IS NULL\n    ");
    $stmt->execute([$tokenHash]);

    sendResponse(['success' => true]);
}

function mobileMe() {
    $student = mobileGetAuthenticatedStudent(false);

    $requiresPasswordSetup = empty($student['password_hash']);

    sendResponse([
        'success' => true,
        'requires_password_setup' => $requiresPasswordSetup,
        'student' => [
            'id' => intval($student['student_id']),
            'name' => $student['name'],
            'student_id' => $student['student_number'],
            'program' => $student['program'],
            'year_level' => $student['year_level'],
            'nfc_card_id' => $student['nfc_card_id'],
            'email' => $student['email'] ?? null,
            'balance' => floatval($student['balance'] ?? 0)
        ]
    ]);
}
