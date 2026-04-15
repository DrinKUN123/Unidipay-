<?php
require_once 'config.php';

try {
    $conn = getDBConnection();
    
    if (!$conn) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed'
        ]);
        exit;
    }
    
    // Get category from query parameter
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    
    if (empty($category)) {
        echo json_encode([
            'success' => false,
            'message' => 'Category parameter is required'
        ]);
        exit;
    }
    
    // Validate category
    $validCategories = ['Meals', 'Drinks', 'Snacks', 'Desserts'];
    if (!in_array($category, $validCategories)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid category'
        ]);
        exit;
    }
    
    // Fetch menu items
    $stmt = $conn->prepare("
        SELECT id, name, category, price, description, image_url, available
        FROM menu_items
        WHERE category = :category AND available = 1
        ORDER BY name ASC
    ");
    
    $stmt->execute(['category' => $category]);
    $items = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
    
} catch(PDOException $e) {
    error_log("Error fetching menu items: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch menu items'
    ]);
}
?>
