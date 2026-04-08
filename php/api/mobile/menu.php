<?php
require_once 'bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    sendResponse(['error' => 'Method not allowed'], 405);
}

try {
    $student = mobileGetAuthenticatedStudent();
    $action = $_GET['action'] ?? 'all';

    if ($action === 'category') {
        $category = sanitize($_GET['category'] ?? '');
        getMenuByCategory($category, $student);
    } else {
        getAllMenu($student);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

function getAllMenu($student) {
    global $pdo;

    $stmt = $pdo->query("SELECT id, name, category, price, description, image_url, available FROM menu_items ORDER BY category, name");
    $items = $stmt->fetchAll();

    $availableItems = array_values(array_filter($items, function($item) {
        return intval($item['available'] ?? 1) === 1;
    }));

    sendResponse([
        'success' => true,
        'items' => $availableItems,
        'student_id' => intval($student['student_id'])
    ]);
}

function getMenuByCategory($category, $student) {
    global $pdo;

    if ($category === '') {
        sendResponse(['error' => 'Category is required'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, name, category, price, description, image_url, available FROM menu_items WHERE category = ? ORDER BY name");
    $stmt->execute([$category]);
    $items = $stmt->fetchAll();

    $availableItems = array_values(array_filter($items, function($item) {
        return intval($item['available'] ?? 1) === 1;
    }));

    sendResponse([
        'success' => true,
        'items' => $availableItems,
        'student_id' => intval($student['student_id'])
    ]);
}
