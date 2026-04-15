<?php
/**
 * Reports API
 * Analytics, daily sales, transaction logs, CSV export
 */

require_once '../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Allow requests without session check for now (development)
// In production, implement proper authentication

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'daily_sales':
            getDailySales();
            break;

        case 'completed_orders':
            getCompletedOrders();
            break;

        case 'user_management_logs':
            getActivityLogsByTarget('User');
            break;

        case 'employee_roles_logs':
            getActivityLogsByTarget('Employee Role');
            break;
            
        case 'transactions':
            getTransactions();
            break;
            
        case 'reloads':
            getReloads();
            break;
            
        case 'dashboard_stats':
            getDashboardStats();
            break;

        case 'export_excel':
            exportExcel();
            break;
            
        case 'export_csv':
            // Backward compatibility with older frontend calls.
            exportExcel();
            break;
            
        default:
            sendResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

/**
 * Get daily sales report
 */
function getDailySales() {
    global $pdo;
    
    $date = $_GET['date'] ?? date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(total), 0) as total_sales
        FROM orders
        WHERE DATE(created_at) = ? AND status = 'completed'
    ");
    $stmt->execute([$date]);
    $summary = $stmt->fetch();
    
    // Get orders for that date
    $stmt = $pdo->prepare("
        SELECT o.*, s.name as student_name, s.student_id
        FROM orders o
        JOIN students s ON o.student_id = s.id
        WHERE DATE(o.created_at) = ? AND o.status = 'completed'
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$date]);
    $orders = $stmt->fetchAll();
    
    sendResponse([
        'date' => $date,
        'total_orders' => $summary['total_orders'],
        'total_sales' => $summary['total_sales'],
        'orders' => $orders
    ]);
}

/**
 * Get completed orders report with date and student filters
 */
function getCompletedOrders() {
    global $pdo;

    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $student = trim($_GET['student'] ?? '');

    $where = ["o.status = 'completed'"];
    $params = [];

    if ($dateFrom !== '') {
        $where[] = "DATE(o.created_at) >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE(o.created_at) <= ?";
        $params[] = $dateTo;
    }

    if ($student !== '') {
        $where[] = "(s.name LIKE ? OR s.student_id LIKE ?)";
        $like = '%' . $student . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT
            o.id,
            o.total,
            o.status,
            o.created_at,
            s.name AS student_name,
            s.student_id AS student_number,
            COALESCE(
                GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.name) ORDER BY oi.id SEPARATOR ', '),
                ''
            ) AS items
        FROM orders o
        JOIN students s ON o.student_id = s.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE {$whereSql}
        GROUP BY o.id, o.total, o.status, o.created_at, s.name, s.student_id
        ORDER BY o.created_at DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    sendResponse([
        'orders' => $orders,
        'total_count' => count($orders)
    ]);
}

/**
 * Get activity logs by target with search and date-time filters
 */
function getActivityLogsByTarget($target) {
    global $pdo;

    $fromDateTime = trim($_GET['from_datetime'] ?? '');
    $toDateTime = trim($_GET['to_datetime'] ?? '');
    $search = trim($_GET['search'] ?? '');

    $where = ['target = ?'];
    $params = [$target];

    if ($fromDateTime !== '') {
        $from = normalizeDateTimeFilter($fromDateTime);
        if ($from !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $from;
        }
    }

    if ($toDateTime !== '') {
        $to = normalizeDateTimeFilter($toDateTime);
        if ($to !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $to;
        }
    }

    if ($search !== '') {
        $where[] = '(admin_username LIKE ? OR action LIKE ? OR details LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT id, admin_username, action, details, created_at
        FROM activity_logs
        WHERE {$whereSql}
        ORDER BY created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    sendResponse([
        'logs' => $logs,
        'total_count' => count($logs)
    ]);
}

/**
 * Normalize datetime-local strings to MySQL datetime format
 */
function normalizeDateTimeFilter($value) {
    $normalized = str_replace('T', ' ', $value);
    $timestamp = strtotime($normalized);

    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Get transaction logs
 */
function getTransactions() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT t.*, s.name as student_name
        FROM transactions t
        LEFT JOIN nfc_cards nc ON t.card_id = nc.id
        LEFT JOIN students s ON nc.student_id = s.id
        ORDER BY t.created_at DESC
        LIMIT 500
    ");
    
    $transactions = $stmt->fetchAll();
    
    sendResponse(['transactions' => $transactions]);
}

/**
 * Get reload logs
 */
function getReloads() {
    global $pdo;

    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $cardId = trim($_GET['card_id'] ?? '');

    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== '') {
        $where[] = "DATE(r.created_at) >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE(r.created_at) <= ?";
        $params[] = $dateTo;
    }

    if ($cardId !== '') {
        $where[] = "r.card_id LIKE ?";
        $params[] = '%' . $cardId . '%';
    }

    $whereSql = implode(' AND ', $where);

    // Primary query for newer schema with employee_id in reloads.
    $sqlWithEmployee = "
        SELECT
            r.*,
            s.name AS student_name,
            s.student_id,
            COALESCE(a.name, e.name) AS admin_name
        FROM reloads r
        LEFT JOIN nfc_cards nc ON r.card_id = nc.id
        LEFT JOIN students s ON nc.student_id = s.id
        LEFT JOIN admins a ON r.admin_id = a.id
        LEFT JOIN employees e ON r.employee_id = e.id
        WHERE {$whereSql}
        ORDER BY r.created_at DESC
        LIMIT 500
    ";

    // Compatibility query for older schema without reloads.employee_id.
    $sqlLegacy = "
        SELECT
            r.*,
            s.name AS student_name,
            s.student_id,
            a.name AS admin_name
        FROM reloads r
        LEFT JOIN nfc_cards nc ON r.card_id = nc.id
        LEFT JOIN students s ON nc.student_id = s.id
        LEFT JOIN admins a ON r.admin_id = a.id
        WHERE {$whereSql}
        ORDER BY r.created_at DESC
        LIMIT 500
    ";

    try {
        $stmt = $pdo->prepare($sqlWithEmployee);
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22') {
            throw $e;
        }

        $stmt = $pdo->prepare($sqlLegacy);
        $stmt->execute($params);
    }

    $reloads = $stmt->fetchAll();

    sendResponse([
        'reloads' => $reloads,
        'total_count' => count($reloads)
    ]);
}

/**
 * Get dashboard statistics for reports
 */
function getDashboardStats() {
    global $pdo;
    
    try {
        // Total users (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM students");
        $totalUsers = $stmt->fetch()['total'] ?? 0;

        // Total employees (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees");
        $totalEmployees = $stmt->fetch()['total'] ?? 0;
        
        // Total menu items (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM menu_items WHERE available = 1");
        $totalMenuItems = $stmt->fetch()['total'] ?? 0;
        
        // Total RFID Cards (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM nfc_cards");
        $totalNfcCards = $stmt->fetch()['total'] ?? 0;
        
        // Pending orders (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE status = 'pending'");
        $pendingOrders = $stmt->fetch()['total'] ?? 0;
        
        // Completed orders (for both)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE status = 'completed'");
        $completedOrders = $stmt->fetch()['total'] ?? 0;

        $userManagementLogs = 0;
        $employeeRolesLogs = 0;

        try {
            $stmt = $pdo->query("SELECT target, COUNT(*) as total FROM activity_logs WHERE target IN ('User', 'Employee Role') GROUP BY target");
            $logCounts = $stmt->fetchAll();
            foreach ($logCounts as $row) {
                if (($row['target'] ?? '') === 'User') {
                    $userManagementLogs = intval($row['total'] ?? 0);
                }
                if (($row['target'] ?? '') === 'Employee Role') {
                    $employeeRolesLogs = intval($row['total'] ?? 0);
                }
            }
        } catch (Exception $e) {
            // Keep stats available even when activity_logs is not ready.
            $userManagementLogs = 0;
            $employeeRolesLogs = 0;
        }
        
        // Daily sales (for reports)
        $stmt = $pdo->query("
            SELECT 
                COALESCE(SUM(total), 0) as daily_sales
            FROM orders
            WHERE DATE(created_at) = CURDATE() AND status = 'completed'
        ");
        $dailySalesResult = $stmt->fetch();
        $dailySales = $dailySalesResult['daily_sales'] ?? 0;
        
        // Total transactions (for reports)
        $stmt = $pdo->query("
            SELECT COUNT(*) as total_transactions
            FROM transactions
        ");
        $transResult = $stmt->fetch();
        $totalTransactions = $transResult['total_transactions'] ?? 0;
        
        // Card reloads (for reports)
        $stmt = $pdo->query("
            SELECT COUNT(*) as card_reloads
            FROM reloads
        ");
        $reloadsResult = $stmt->fetch();
        $cardReloads = $reloadsResult['card_reloads'] ?? 0;
        
        // Average order value (for reports)
        $stmt = $pdo->query("
            SELECT 
                COALESCE(AVG(total), 0) as avg_order_value
            FROM orders
            WHERE status = 'completed'
        ");
        $avgResult = $stmt->fetch();
        $avgOrderValue = $avgResult['avg_order_value'] ?? 0;
        
        // Recent transactions (for dashboard)
        $stmt = $pdo->query("
            SELECT t.id, t.type, t.amount, t.reason, t.created_at, t.card_id
            FROM transactions t
            ORDER BY t.created_at DESC
            LIMIT 5
        ");
        $recentTransactions = $stmt->fetchAll() ?? [];
        
        // Low balance cards (for dashboard)
        $stmt = $pdo->query("
            SELECT nc.id as card_id, s.student_id, s.name, nc.balance
            FROM nfc_cards nc
            JOIN students s ON nc.student_id = s.id
            WHERE nc.balance < 100
            ORDER BY nc.balance ASC
            LIMIT 5
        ");
        $lowBalanceCards = $stmt->fetchAll() ?? [];
        
        // Return combined data for both dashboard and reports
        sendResponse([
            // Dashboard data
            'total_users' => intval($totalUsers),
            'total_employees' => intval($totalEmployees),
            'total_menu_items' => intval($totalMenuItems),
            'total_nfc_cards' => intval($totalNfcCards),
            'pending_orders' => intval($pendingOrders),
            'completed_orders' => intval($completedOrders),
            'recent_transactions' => $recentTransactions,
            'low_balance_cards' => $lowBalanceCards,
            // Reports data (wrapped in stats for backward compatibility)
            'stats' => [
                'daily_sales' => floatval($dailySales),
                'completed_orders' => intval($completedOrders),
                'user_management_logs' => $userManagementLogs,
                'employee_roles_logs' => $employeeRolesLogs,
                'total_transactions' => intval($totalTransactions),
                'card_reloads' => intval($cardReloads),
                'avg_order_value' => floatval($avgOrderValue)
            ]
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch dashboard stats: ' . $e->getMessage()], 500);
    }
}

/**
 * Export reports in Excel-compatible TSV format
 */
function exportExcel() {
    global $pdo;

    $type = trim($_GET['type'] ?? 'transactions');
    if ($type === 'orders') {
        $type = 'completed';
    }

    $allowedTypes = ['daily', 'completed', 'transactions', 'reloads'];
    if (!in_array($type, $allowedTypes, true)) {
        sendResponse(['error' => 'Invalid export type'], 400);
    }

    $fileName = sprintf('%s-report-%s.xls', $type, date('Ymd_His'));

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    $delimiter = "\t";

    switch ($type) {
        case 'daily': {
            $date = trim($_GET['date'] ?? date('Y-m-d'));

            $stmt = $pdo->prepare("SELECT COUNT(*) AS total_orders, COALESCE(SUM(total), 0) AS total_sales FROM orders WHERE DATE(created_at) = ? AND status = 'completed'");
            $stmt->execute([$date]);
            $summary = $stmt->fetch() ?: ['total_orders' => 0, 'total_sales' => 0];

            $stmt = $pdo->prepare("
                SELECT o.id, s.student_id AS student_number, s.name AS student_name, o.total, o.status, o.created_at
                FROM orders o
                JOIN students s ON o.student_id = s.id
                WHERE DATE(o.created_at) = ? AND o.status = 'completed'
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$date]);
            $orders = $stmt->fetchAll();

            fputcsv($output, ['Report Type', 'Daily Sales'], $delimiter);
            fputcsv($output, ['Date', $date], $delimiter);
            fputcsv($output, ['Total Orders', $summary['total_orders'] ?? 0], $delimiter);
            fputcsv($output, ['Total Sales', number_format((float)($summary['total_sales'] ?? 0), 2)], $delimiter);
            fputcsv($output, [], $delimiter);

            fputcsv($output, ['Order ID', 'Student ID', 'Student Name', 'Amount', 'Status', 'Ordered Date'], $delimiter);
            foreach ($orders as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['student_number'] ?? '-',
                    $row['student_name'] ?? '-',
                    number_format((float)($row['total'] ?? 0), 2),
                    strtoupper($row['status'] ?? '-'),
                    $row['created_at'] ?? '-'
                ], $delimiter);
            }
            break;
        }

        case 'completed': {
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo = trim($_GET['date_to'] ?? '');
            $student = trim($_GET['student'] ?? '');

            $where = ["o.status = 'completed'"];
            $params = [];

            if ($dateFrom !== '') {
                $where[] = "DATE(o.created_at) >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = "DATE(o.created_at) <= ?";
                $params[] = $dateTo;
            }
            if ($student !== '') {
                $where[] = "(s.name LIKE ? OR s.student_id LIKE ?)";
                $like = '%' . $student . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $stmt = $pdo->prepare("
                SELECT
                    o.id,
                    s.student_id AS student_number,
                    s.name AS student_name,
                    COALESCE(GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.name) ORDER BY oi.id SEPARATOR ', '), '') AS items,
                    o.total,
                    o.status,
                    o.created_at
                FROM orders o
                JOIN students s ON o.student_id = s.id
                LEFT JOIN order_items oi ON oi.order_id = o.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY o.id, s.student_id, s.name, o.total, o.status, o.created_at
                ORDER BY o.created_at DESC
            ");
            $stmt->execute($params);
            $orders = $stmt->fetchAll();

            fputcsv($output, ['Report Type', 'Completed Orders'], $delimiter);
            fputcsv($output, ['From Date', $dateFrom !== '' ? $dateFrom : 'Any'], $delimiter);
            fputcsv($output, ['To Date', $dateTo !== '' ? $dateTo : 'Any'], $delimiter);
            fputcsv($output, ['Search Student', $student !== '' ? $student : 'Any'], $delimiter);
            fputcsv($output, ['Total Rows', count($orders)], $delimiter);
            fputcsv($output, [], $delimiter);

            fputcsv($output, ['Order ID', 'Student ID', 'Student Name', 'Items', 'Amount', 'Status', 'Ordered Date'], $delimiter);
            foreach ($orders as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['student_number'] ?? '-',
                    $row['student_name'] ?? '-',
                    $row['items'] ?? '-',
                    number_format((float)($row['total'] ?? 0), 2),
                    strtoupper($row['status'] ?? '-'),
                    $row['created_at'] ?? '-'
                ], $delimiter);
            }
            break;
        }

        case 'transactions': {
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo = trim($_GET['date_to'] ?? '');
            $cardId = trim($_GET['card_id'] ?? '');

            $where = ['1=1'];
            $params = [];

            if ($dateFrom !== '') {
                $where[] = "DATE(t.created_at) >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = "DATE(t.created_at) <= ?";
                $params[] = $dateTo;
            }
            if ($cardId !== '') {
                $where[] = "t.card_id LIKE ?";
                $params[] = '%' . $cardId . '%';
            }

            $stmt = $pdo->prepare("
                SELECT
                    t.id,
                    t.card_id,
                    s.student_id,
                    s.name AS student_name,
                    t.type,
                    t.amount,
                    t.reason,
                    t.balance_before,
                    t.balance_after,
                    t.created_at
                FROM transactions t
                LEFT JOIN nfc_cards nc ON t.card_id = nc.id
                LEFT JOIN students s ON nc.student_id = s.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.created_at DESC
            ");
            $stmt->execute($params);
            $transactions = $stmt->fetchAll();

            fputcsv($output, ['Report Type', 'Transactions'], $delimiter);
            fputcsv($output, ['From Date', $dateFrom !== '' ? $dateFrom : 'Any'], $delimiter);
            fputcsv($output, ['To Date', $dateTo !== '' ? $dateTo : 'Any'], $delimiter);
            fputcsv($output, ['Card ID Search', $cardId !== '' ? $cardId : 'Any'], $delimiter);
            fputcsv($output, ['Total Rows', count($transactions)], $delimiter);
            fputcsv($output, [], $delimiter);

            fputcsv($output, ['ID', 'Card ID', 'Student ID', 'Student Name', 'Type', 'Amount', 'Reason', 'Balance Before', 'Balance After', 'Date & Time'], $delimiter);
            foreach ($transactions as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['card_id'] ?? '-',
                    $row['student_id'] ?? '-',
                    $row['student_name'] ?? '-',
                    strtoupper($row['type'] ?? '-'),
                    number_format((float)($row['amount'] ?? 0), 2),
                    $row['reason'] ?? '',
                    number_format((float)($row['balance_before'] ?? 0), 2),
                    number_format((float)($row['balance_after'] ?? 0), 2),
                    $row['created_at'] ?? '-'
                ], $delimiter);
            }
            break;
        }

        case 'reloads': {
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo = trim($_GET['date_to'] ?? '');
            $cardId = trim($_GET['card_id'] ?? '');

            $where = ['1=1'];
            $params = [];

            if ($dateFrom !== '') {
                $where[] = "DATE(r.created_at) >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = "DATE(r.created_at) <= ?";
                $params[] = $dateTo;
            }
            if ($cardId !== '') {
                $where[] = "r.card_id LIKE ?";
                $params[] = '%' . $cardId . '%';
            }

            $whereSql = implode(' AND ', $where);

            $sqlWithEmployee = "
                SELECT
                    r.id,
                    r.card_id,
                    s.student_id,
                    s.name AS student_name,
                    r.amount,
                    r.balance_before,
                    r.balance_after,
                    COALESCE(a.name, e.name) AS admin_name,
                    r.created_at
                FROM reloads r
                LEFT JOIN nfc_cards nc ON r.card_id = nc.id
                LEFT JOIN students s ON nc.student_id = s.id
                LEFT JOIN admins a ON r.admin_id = a.id
                LEFT JOIN employees e ON r.employee_id = e.id
                WHERE {$whereSql}
                ORDER BY r.created_at DESC
            ";

            $sqlLegacy = "
                SELECT
                    r.id,
                    r.card_id,
                    s.student_id,
                    s.name AS student_name,
                    r.amount,
                    r.balance_before,
                    r.balance_after,
                    a.name AS admin_name,
                    r.created_at
                FROM reloads r
                LEFT JOIN nfc_cards nc ON r.card_id = nc.id
                LEFT JOIN students s ON nc.student_id = s.id
                LEFT JOIN admins a ON r.admin_id = a.id
                WHERE {$whereSql}
                ORDER BY r.created_at DESC
            ";

            try {
                $stmt = $pdo->prepare($sqlWithEmployee);
                $stmt->execute($params);
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S22') {
                    throw $e;
                }

                $stmt = $pdo->prepare($sqlLegacy);
                $stmt->execute($params);
            }

            $reloads = $stmt->fetchAll();

            fputcsv($output, ['Report Type', 'Card Reloads'], $delimiter);
            fputcsv($output, ['From Date', $dateFrom !== '' ? $dateFrom : 'Any'], $delimiter);
            fputcsv($output, ['To Date', $dateTo !== '' ? $dateTo : 'Any'], $delimiter);
            fputcsv($output, ['Card ID Search', $cardId !== '' ? $cardId : 'Any'], $delimiter);
            fputcsv($output, ['Total Rows', count($reloads)], $delimiter);
            fputcsv($output, [], $delimiter);

            fputcsv($output, ['ID', 'Card ID', 'Student ID', 'Student Name', 'Amount', 'Balance Before', 'Balance After', 'Admin/Employee', 'Date & Time'], $delimiter);
            foreach ($reloads as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['card_id'] ?? '-',
                    $row['student_id'] ?? '-',
                    $row['student_name'] ?? '-',
                    number_format((float)($row['amount'] ?? 0), 2),
                    number_format((float)($row['balance_before'] ?? 0), 2),
                    number_format((float)($row['balance_after'] ?? 0), 2),
                    $row['admin_name'] ?? 'Unknown',
                    $row['created_at'] ?? '-'
                ], $delimiter);
            }
            break;
        }
    }

    fclose($output);
    exit;
}
?>
