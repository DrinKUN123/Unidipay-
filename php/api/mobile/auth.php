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
    global $pdo;

    $data = mobileJsonInput();
    $errors = validateRequired($data, ['student_id', 'nfc_card_id']);

    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }

    $studentNumber = sanitize($data['student_id']);
    $cardId = sanitize($data['nfc_card_id']);
    $deviceName = isset($data['device_name']) ? sanitize($data['device_name']) : null;

    $stmt = $pdo->prepare("\n        SELECT\n            s.id,\n            s.name,\n            s.student_id,\n            s.program,\n            s.year_level,\n            s.nfc_card_id,\n            nc.balance\n        FROM students s\n        LEFT JOIN nfc_cards nc ON nc.id = s.nfc_card_id\n        WHERE s.student_id = ?\n          AND s.nfc_card_id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$studentNumber, $cardId]);
    $student = $stmt->fetch();

    if (!$student) {
        sendResponse(['error' => 'Invalid student credentials'], 401);
    }

    mobileEnsureTokenTable();

    $token = mobileGenerateToken();
    $tokenHash = mobileHashToken($token);

    $insert = $pdo->prepare("\n        INSERT INTO mobile_student_tokens (student_id, token_hash, device_name, expires_at)\n        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))\n    ");
    $insert->execute([$student['id'], $tokenHash, $deviceName]);

    sendResponse([
        'success' => true,
        'token' => $token,
        'student' => [
            'id' => intval($student['id']),
            'name' => $student['name'],
            'student_id' => $student['student_id'],
            'program' => $student['program'],
            'year_level' => $student['year_level'],
            'nfc_card_id' => $student['nfc_card_id'],
            'balance' => floatval($student['balance'] ?? 0)
        ]
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
    $student = mobileGetAuthenticatedStudent();

    sendResponse([
        'success' => true,
        'student' => [
            'id' => intval($student['student_id']),
            'name' => $student['name'],
            'student_id' => $student['student_number'],
            'program' => $student['program'],
            'year_level' => $student['year_level'],
            'nfc_card_id' => $student['nfc_card_id'],
            'balance' => floatval($student['balance'] ?? 0)
        ]
    ]);
}
