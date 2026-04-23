<?php
/**
 * Menu API
 * CRUD operations for menu items
 */

require_once '../config/database.php';

define('DELETED_MENU_PLACEHOLDER_NAME', '__DELETED_MENU_ITEM__');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'all') {
                getAllMenuItems();
            } elseif ($action === 'single' && isset($_GET['id'])) {
                getMenuItem($_GET['id']);
            } elseif ($action === 'category' && isset($_GET['category'])) {
                getMenuByCategory($_GET['category']);
            } else {
                getAllMenuItems();
            }
            break;
            
        case 'POST':
            if (!isLoggedIn()) {
                sendResponse(['error' => 'Unauthorized'], 401);
            }
            createMenuItem();
            break;
            
        case 'PUT':
            if (!isLoggedIn()) {
                sendResponse(['error' => 'Unauthorized'], 401);
            }
            updateMenuItem();
            break;
            
        case 'DELETE':
            if (!isLoggedIn()) {
                sendResponse(['error' => 'Unauthorized'], 401);
            }
            if (isset($_GET['id'])) {
                deleteMenuItem($_GET['id']);
            } else {
                sendResponse(['error' => 'Menu item ID required'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

/**
 * Get all menu items
 */
function getAllMenuItems() {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE name <> ? ORDER BY category, name");
    $stmt->execute([DELETED_MENU_PLACEHOLDER_NAME]);
    $menuItems = $stmt->fetchAll();
    
    sendResponse(['menu_items' => $menuItems]);
}

/**
 * Get single menu item
 */
function getMenuItem($id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        sendResponse(['error' => 'Menu item not found'], 404);
    }
    
    sendResponse(['menu_item' => $item]);
}

/**
 * Get menu items by category
 */
function getMenuByCategory($category) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE category = ? AND name <> ? ORDER BY name");
    $stmt->execute([sanitize($category), DELETED_MENU_PLACEHOLDER_NAME]);
    $menuItems = $stmt->fetchAll();
    
    sendResponse(['menu_items' => $menuItems]);
}

/**
 * Create new menu item
 */
function createMenuItem() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = validateRequired($data, ['name', 'category', 'price', 'description']);
    if (!empty($errors)) {
        sendResponse(['error' => implode(', ', $errors)], 400);
    }
    
    $name = sanitize($data['name']);
    $category = sanitize($data['category']);
    $price = floatval($data['price']);
    $description = sanitize($data['description']);
    $imageUrl = sanitize($data['image_url'] ?? '');
    $available = isset($data['available']) ? (bool)$data['available'] : true;
    
    if ($price < 0) {
        sendResponse(['error' => 'Price must be 0 or greater'], 400);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO menu_items (name, category, price, description, image_url, available)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $category, $price, $description, $imageUrl, $available]);
    
    $itemId = $pdo->lastInsertId();
    
    // Get created item
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    
    sendResponse([
        'success' => true,
        'menu_item' => $item
    ], 201);
}

/**
 * Update menu item
 */
function updateMenuItem() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        sendResponse(['error' => 'Menu item ID required'], 400);
    }
    
    $id = intval($data['id']);
    
    // Build update query dynamically
    $fields = [];
    $values = [];
    
    if (isset($data['name'])) {
        $fields[] = "name = ?";
        $values[] = sanitize($data['name']);
    }
    if (isset($data['category'])) {
        $fields[] = "category = ?";
        $values[] = sanitize($data['category']);
    }
    if (isset($data['price'])) {
        $fields[] = "price = ?";
        $values[] = floatval($data['price']);
    }
    if (isset($data['description'])) {
        $fields[] = "description = ?";
        $values[] = sanitize($data['description']);
    }
    if (isset($data['image_url'])) {
        $fields[] = "image_url = ?";
        $values[] = sanitize($data['image_url']);
    }
    if (isset($data['available'])) {
        $fields[] = "available = ?";
        $values[] = (bool)$data['available'];
    }
    
    if (empty($fields)) {
        sendResponse(['error' => 'No fields to update'], 400);
    }
    
    $values[] = $id;
    
    $sql = "UPDATE menu_items SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    // Get updated item
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    
    sendResponse([
        'success' => true,
        'menu_item' => $item
    ]);
}

/**
 * Delete menu item
 */
function deleteMenuItem($id) {
    global $pdo;

    $id = intval($id);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new Exception('Menu item not found');
        }

        if (($item['name'] ?? '') === DELETED_MENU_PLACEHOLDER_NAME) {
            throw new Exception('System placeholder item cannot be deleted');
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            // Item is referenced by order_items (legacy/historical orders).
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $placeholderId = ensureDeletedMenuPlaceholder($pdo);

            $stmt = $pdo->prepare("UPDATE order_items SET menu_item_id = ? WHERE menu_item_id = ?");
            $stmt->execute([$placeholderId, $id]);

            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);
        }

        $pdo->commit();
        sendResponse(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e->getMessage() === 'Menu item not found') {
            sendResponse(['error' => 'Menu item not found'], 404);
        }

        sendResponse(['error' => $e->getMessage()], 400);
    }
}

/**
 * Ensure hidden placeholder menu item exists for historical order references.
 */
function ensureDeletedMenuPlaceholder($pdo) {
    $stmt = $pdo->prepare("SELECT id FROM menu_items WHERE name = ? LIMIT 1");
    $stmt->execute([DELETED_MENU_PLACEHOLDER_NAME]);
    $existing = $stmt->fetch();

    if ($existing) {
        return intval($existing['id']);
    }

    $stmt = $pdo->prepare("INSERT INTO menu_items (name, category, price, description, image_url, available) VALUES (?, ?, 0, ?, '', 0)");
    $stmt->execute([
        DELETED_MENU_PLACEHOLDER_NAME,
        'archived',
        'System placeholder for deleted menu item references'
    ]);

    return intval($pdo->lastInsertId());
}
?>
