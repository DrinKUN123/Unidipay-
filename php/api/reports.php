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
            
        case 'transactions':
            getTransactions();
            break;
            
        case 'reloads':
            getReloads();
            break;
            
        case 'dashboard_stats':
            getDashboardStats();
            break;
            
        case 'export_csv':
            exportCSV();
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
    
    $stmt = $pdo->query("
        SELECT 
            r.*, 
            s.name as student_name, 
            COALESCE(a.name, e.name) as admin_name
        FROM reloads r
        LEFT JOIN nfc_cards nc ON r.card_id = nc.id
        LEFT JOIN students s ON nc.student_id = s.id
        LEFT JOIN admins a ON r.admin_id = a.id
        LEFT JOIN employees e ON r.employee_id = e.id
        ORDER BY r.created_at DESC
        LIMIT 500
    ");
    
    $reloads = $stmt->fetchAll();
    
    sendResponse(['reloads' => $reloads]);
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
        
        // Total menu items (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM menu_items WHERE available = 1");
        $totalMenuItems = $stmt->fetch()['total'] ?? 0;
        
        // Total NFC cards (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM nfc_cards");
        $totalNfcCards = $stmt->fetch()['total'] ?? 0;
        
        // Pending orders (for dashboard)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE status = 'pending'");
        $pendingOrders = $stmt->fetch()['total'] ?? 0;
        
        // Completed orders (for both)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE status = 'completed'");
        $completedOrders = $stmt->fetch()['total'] ?? 0;
        
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
 * Export data to CSV
 */
function exportCSV() {
    global $pdo;
    
    $type = $_GET['type'] ?? 'transactions';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $type . '-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    switch ($type) {
        case 'transactions':
            fputcsv($output, [
                'ID', 'Card ID', 'Student Name', 'Type', 'Amount', 'Reason',
                'Balance Before', 'Balance After', 'Date & Time'
            ]);
            
            $stmt = $pdo->query("
                SELECT t.*, s.name as student_name
                FROM transactions t
                LEFT JOIN nfc_cards nc ON t.card_id = nc.id
                LEFT JOIN students s ON nc.student_id = s.id
                ORDER BY t.created_at DESC
            ");
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['id'],
                    $row['card_id'],
                    $row['student_name'] ?? 'Unknown',
                    $row['type'],
                    number_format($row['amount'], 2),
                    $row['reason'] ?? '',
                    number_format($row['balance_before'], 2),
                    number_format($row['balance_after'], 2),
                    $row['created_at']
                ]);
            }
            break;
            
        case 'orders':
            fputcsv($output, [
                'Order ID', 'Student ID', 'Student Name', 'Total', 'Status',
                'Created At', 'Completed At'
            ]);
            
            $stmt = $pdo->query("
                SELECT o.*, s.name as student_name, s.student_id as student_number
                FROM orders o
                JOIN students s ON o.student_id = s.id
                ORDER BY o.created_at DESC
            ");
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['id'],
                    $row['student_number'],
                    $row['student_name'],
                    number_format($row['total'], 2),
                    $row['status'],
                    $row['created_at'],
                    $row['completed_at'] ?? ''
                ]);
            }
            break;
            
        case 'reloads':
            fputcsv($output, [
                'ID', 'Card ID', 'Student Name', 'Amount', 'Admin Name',
                'Balance Before', 'Balance After', 'Date & Time'
            ]);
            
            $stmt = $pdo->query("
                SELECT 
                    r.*, 
                    s.name as student_name, 
                    COALESCE(a.name, e.name) as admin_name
                FROM reloads r
                LEFT JOIN nfc_cards nc ON r.card_id = nc.id
                LEFT JOIN students s ON nc.student_id = s.id
                LEFT JOIN admins a ON r.admin_id = a.id
                LEFT JOIN employees e ON r.employee_id = e.id
                ORDER BY r.created_at DESC
            ");
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['id'],
                    $row['card_id'],
                    $row['student_name'] ?? 'Unknown',
                    number_format($row['amount'], 2),
                    $row['admin_name'] ?? 'Unknown',
                    number_format($row['balance_before'], 2),
                    number_format($row['balance_after'], 2),
                    $row['created_at']
                ]);
            }
            break;
            
        default:
            fputcsv($output, ['Error', 'Invalid export type']);
    }
    
    fclose($output);
    exit;
}
?>
