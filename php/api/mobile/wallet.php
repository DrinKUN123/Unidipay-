<?php
require_once 'bootstrap.php';

define('XENDIT_SECRET_KEY', 'xnd_development_7C1Z0SWgV1Zf7kHDti7DwdnrfIjhr2iUHbgg6fA4qtTgIur58W0cSA6RJA6xi');
define('XENDIT_API_BASE', 'https://api.xendit.co');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'balance') {
        getBalance();
        exit;
    }

    if ($method === 'POST' && $action === 'gcash_topup') {
        gcashTopup();
        exit;
    }

    if ($method === 'POST' && $action === 'xendit_create_invoice') {
        xenditCreateInvoice();
        exit;
    }

    if ($method === 'GET' && $action === 'xendit_check_invoice') {
        xenditCheckInvoice();
        exit;
    }

    if ($method === 'GET' && $action === 'xendit_sync_pending') {
        xenditSyncPending();
        exit;
    }

    if ($method === 'GET' && $action === 'topup_history') {
        topupHistory();
        exit;
    }

    sendResponse(['error' => 'Invalid action'], 400);
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

function getBalance() {
    $student = mobileGetAuthenticatedStudent();

    sendResponse([
        'success' => true,
        'card_id' => $student['nfc_card_id'],
        'balance' => floatval($student['balance'] ?? 0)
    ]);
}

function gcashTopup() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();
    $data = mobileJsonInput();

    $errors = validateRequired($data, ['amount', 'gcash_reference']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }

    $amount = floatval($data['amount']);
    $reference = sanitize($data['gcash_reference']);

    if ($amount <= 0) {
        sendResponse(['error' => 'Amount must be greater than 0'], 400);
    }

    if (strlen($reference) < 6) {
        sendResponse(['error' => 'Invalid GCash reference'], 400);
    }

    if (empty($student['nfc_card_id'])) {
        sendResponse(['error' => 'No NFC card linked to this student'], 400);
    }

    $pdo->beginTransaction();
    try {
        $cardStmt = $pdo->prepare('SELECT balance FROM nfc_cards WHERE id = ? FOR UPDATE');
        $cardStmt->execute([$student['nfc_card_id']]);
        $card = $cardStmt->fetch();

        if (!$card) {
            throw new Exception('NFC card not found');
        }

        $before = floatval($card['balance']);
        $after = $before + $amount;

        try {
            $ins = $pdo->prepare("\n                INSERT INTO gcash_topups (student_id, card_id, amount, gcash_reference, status, paid_at, notes)\n                VALUES (?, ?, ?, ?, 'paid', NOW(), 'Mobile self-service top-up')\n            ");
            $ins->execute([
                $student['student_id'],
                $student['nfc_card_id'],
                $amount,
                $reference
            ]);
            $topupId = $pdo->lastInsertId();
        } catch (Exception $e) {
            $topupId = 0;
        }

        $upd = $pdo->prepare('UPDATE nfc_cards SET balance = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([$after, $student['nfc_card_id']]);

        mobileInsertReloadCompatible(
            $student['nfc_card_id'],
            $amount,
            $before,
            $after,
            'GCash top-up #' . ($topupId ?: time())
        );

        $pdo->commit();

        sendResponse([
            'success' => true,
            'message' => 'Top-up successful',
            'topup_id' => intval($topupId),
            'new_balance' => $after
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse(['error' => $e->getMessage()], 400);
    }
}

function topupHistory() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();

    $rows = [];
    try {
        $stmt = $pdo->prepare("\n            SELECT id, amount, gcash_reference, status, paid_at, created_at\n            FROM gcash_topups\n            WHERE student_id = ?\n            ORDER BY id DESC\n            LIMIT 100\n        ");
        $stmt->execute([intval($student['student_id'])]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        $stmt = $pdo->prepare("\n            SELECT id, amount, created_at\n            FROM reloads\n            WHERE card_id = ?\n            ORDER BY id DESC\n            LIMIT 100\n        ");
        $stmt->execute([$student['nfc_card_id']]);
        $rows = $stmt->fetchAll();
    }

    sendResponse([
        'success' => true,
        'topups' => $rows
    ]);
}

function xenditCreateInvoice() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();
    $data = mobileJsonInput();

    $errors = validateRequired($data, ['amount']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }

    $amount = round(floatval($data['amount']), 2);
    if ($amount <= 0) {
        sendResponse(['error' => 'Amount must be greater than 0'], 400);
    }

    if (empty($student['nfc_card_id'])) {
        sendResponse(['error' => 'No NFC card linked to this student'], 400);
    }

    ensureXenditTopupsTable();

    $externalId = 'unidipay_topup_' . intval($student['student_id']) . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $description = 'UniDiPay GCash Top-up for ' . ($student['student_number'] ?? 'student');

    $payload = [
        'external_id' => $externalId,
        'amount' => $amount,
        'currency' => 'PHP',
        'description' => $description,
        'payment_methods' => ['GCASH']
    ];

    $response = xenditRequest('POST', '/v2/invoices', $payload);

    $invoiceId = $response['id'] ?? null;
    $invoiceUrl = $response['invoice_url'] ?? null;
    if (!$invoiceId || !$invoiceUrl) {
        sendResponse(['error' => 'Failed to create Xendit invoice'], 502);
    }

    $stmt = $pdo->prepare("\n        INSERT INTO xendit_topups (invoice_id, external_id, student_id, card_id, amount, status, checkout_url, created_at, updated_at)\n        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n        ON DUPLICATE KEY UPDATE\n            status = VALUES(status),\n            checkout_url = VALUES(checkout_url),\n            amount = VALUES(amount),\n            updated_at = NOW()\n    ");
    $stmt->execute([
        $invoiceId,
        $externalId,
        intval($student['student_id']),
        $student['nfc_card_id'],
        $amount,
        $response['status'] ?? 'PENDING',
        $invoiceUrl
    ]);

    sendResponse([
        'success' => true,
        'invoice_id' => $invoiceId,
        'external_id' => $externalId,
        'invoice_url' => $invoiceUrl,
        'status' => $response['status'] ?? 'PENDING',
        'amount' => $amount
    ]);
}

function xenditCheckInvoice() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();
    $invoiceId = sanitize($_GET['invoice_id'] ?? '');
    if ($invoiceId === '') {
        sendResponse(['error' => 'invoice_id is required'], 400);
    }

    ensureXenditTopupsTable();

    $topupStmt = $pdo->prepare('SELECT * FROM xendit_topups WHERE invoice_id = ? AND student_id = ? LIMIT 1');
    $topupStmt->execute([$invoiceId, intval($student['student_id'])]);
    $topup = $topupStmt->fetch();
    if (!$topup) {
        sendResponse(['error' => 'Invoice not found for this student'], 404);
    }

    $invoice = xenditRequest('GET', '/v2/invoices/' . rawurlencode($invoiceId), null);
    $status = strtoupper($invoice['status'] ?? 'PENDING');

    $updateStmt = $pdo->prepare('UPDATE xendit_topups SET status = ?, updated_at = NOW() WHERE invoice_id = ? AND student_id = ?');
    $updateStmt->execute([$status, $invoiceId, intval($student['student_id'])]);

    if ($status !== 'PAID') {
        sendResponse([
            'success' => true,
            'paid' => false,
            'status' => $status,
            'invoice_id' => $invoiceId
        ]);
    }

    $result = creditPaidXenditInvoice($topup, $student, $invoice);

    sendResponse([
        'success' => true,
        'paid' => true,
        'status' => $status,
        'invoice_id' => $invoiceId,
        'new_balance' => $result['new_balance'],
        'already_credited' => $result['already_credited']
    ]);
}

function xenditSyncPending() {
    global $pdo;

    $student = mobileGetAuthenticatedStudent();
    ensureXenditTopupsTable();

    $stmt = $pdo->prepare("\n        SELECT *\n        FROM xendit_topups\n        WHERE student_id = ?\n          AND credited_at IS NULL\n          AND status IN ('PENDING', 'PAID')\n        ORDER BY id ASC\n        LIMIT 30\n    ");
    $stmt->execute([intval($student['student_id'])]);
    $rows = $stmt->fetchAll();

    $checkedCount = 0;
    $creditedCount = 0;
    $errors = [];

    foreach ($rows as $row) {
        $checkedCount++;
        try {
            $invoice = xenditRequest('GET', '/v2/invoices/' . rawurlencode($row['invoice_id']), null);
            $status = strtoupper($invoice['status'] ?? ($row['status'] ?? 'PENDING'));

            $updateStmt = $pdo->prepare('UPDATE xendit_topups SET status = ?, updated_at = NOW() WHERE invoice_id = ?');
            $updateStmt->execute([$status, $row['invoice_id']]);

            if ($status === 'PAID') {
                $result = creditPaidXenditInvoice($row, $student, $invoice);
                if (($result['already_credited'] ?? false) !== true) {
                    $creditedCount++;
                }
            }
        } catch (Exception $e) {
            $errors[] = $row['invoice_id'] . ': ' . $e->getMessage();
        }
    }

    $newBalance = floatval($student['balance'] ?? 0);
    if (!empty($student['nfc_card_id'])) {
        $balanceStmt = $pdo->prepare('SELECT balance FROM nfc_cards WHERE id = ? LIMIT 1');
        $balanceStmt->execute([$student['nfc_card_id']]);
        $card = $balanceStmt->fetch();
        if ($card) {
            $newBalance = floatval($card['balance']);
        }
    }

    sendResponse([
        'success' => true,
        'checked_count' => $checkedCount,
        'credited_count' => $creditedCount,
        'new_balance' => $newBalance,
        'errors' => $errors
    ]);
}

function creditPaidXenditInvoice($topup, $student, $invoice) {
    global $pdo;

    $invoiceId = $topup['invoice_id'];
    $amount = round(floatval($topup['amount'] ?? 0), 2);
    if ($amount <= 0) {
        throw new Exception('Invalid top-up amount in xendit_topups');
    }

    $cardId = $topup['card_id'];
    if (!$cardId) {
        throw new Exception('Missing card_id in xendit_topups');
    }

    $reference = 'XENDIT-' . $invoiceId;

    $pdo->beginTransaction();
    try {
        $lockedTopupStmt = $pdo->prepare('SELECT * FROM xendit_topups WHERE invoice_id = ? FOR UPDATE');
        $lockedTopupStmt->execute([$invoiceId]);
        $lockedTopup = $lockedTopupStmt->fetch();
        if (!$lockedTopup) {
            throw new Exception('xendit_topups record not found');
        }

        $cardStmt = $pdo->prepare('SELECT balance FROM nfc_cards WHERE id = ? FOR UPDATE');
        $cardStmt->execute([$cardId]);
        $card = $cardStmt->fetch();

        if (!$card) {
            throw new Exception('NFC card not found');
        }

        if (!empty($lockedTopup['credited_at'])) {
            $currentBalance = floatval($card['balance']);
            $pdo->commit();
            return ['new_balance' => $currentBalance, 'already_credited' => true];
        }

        $before = floatval($card['balance']);
        $after = $before + $amount;

        try {
            $ins = $pdo->prepare("\n                INSERT INTO gcash_topups (student_id, card_id, amount, gcash_reference, status, paid_at, notes)\n                VALUES (?, ?, ?, ?, 'paid', NOW(), 'Xendit GCash top-up')\n            ");
            $ins->execute([
                intval($student['student_id']),
                $cardId,
                $amount,
                $reference
            ]);
        } catch (Exception $e) {
            // Optional table in some schemas.
        }

        $upd = $pdo->prepare('UPDATE nfc_cards SET balance = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([$after, $cardId]);

        $xu = $pdo->prepare('UPDATE xendit_topups SET status = ?, paid_at = COALESCE(paid_at, NOW()), credited_at = COALESCE(credited_at, NOW()), updated_at = NOW() WHERE invoice_id = ?');
        $xu->execute(['PAID', $invoiceId]);

        mobileInsertReloadCompatible(
            $cardId,
            $amount,
            $before,
            $after,
            'Xendit GCash top-up ' . $invoiceId
        );

        $pdo->commit();
        return ['new_balance' => $after, 'already_credited' => false];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ensureXenditTopupsTable() {
    global $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS xendit_topups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id VARCHAR(64) NOT NULL UNIQUE,
        external_id VARCHAR(120) NOT NULL UNIQUE,
        student_id INT NOT NULL,
        card_id VARCHAR(64) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status VARCHAR(32) NOT NULL,
        checkout_url TEXT NULL,
        paid_at DATETIME NULL,
        credited_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_xendit_topups_student (student_id),
        INDEX idx_xendit_topups_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function xenditRequest($method, $path, $payload = null) {
    $url = XENDIT_API_BASE . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception('Failed to initialize Xendit request');
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, XENDIT_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($payload !== null) {
        $json = json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Xendit request failed: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Invalid response from Xendit');
    }

    if ($httpCode >= 400) {
        $message = $data['message'] ?? ($data['error_code'] ?? 'Xendit API error');
        if (isset($data['errors']) && is_array($data['errors']) && !empty($data['errors'])) {
            $message .= ' (' . json_encode($data['errors']) . ')';
        }
        throw new Exception('Xendit: ' . $message);
    }

    return $data;
}
