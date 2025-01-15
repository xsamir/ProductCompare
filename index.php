<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Check for config file
if (!file_exists('config.php')) {
    die("config.php not found");
}
require_once 'config.php';

// Check for fetch_products file
if (!file_exists('fetch_products.php')) {
    die("fetch_products.php not found");
}
require_once 'fetch_products.php';

// Verify database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? "Connection variable not set"));
}

// Get category from URL, default to 1 if not set
$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 1;

// Refresh product data if it's been more than an hour
if (!isset($_SESSION['last_fetch']) || (time() - $_SESSION['last_fetch']) > 3600) {
    try {
        $fetch_result = fetchAmazonProducts($category_id);
        if ($fetch_result === false) {
            throw new Exception("Failed to fetch Amazon products");
        }
        $_SESSION['last_fetch'] = time();
    } catch (Exception $e) {
        echo "Error updating products: " . htmlspecialchars($e->getMessage());
        // Continue execution to show existing products
    }
}

// Get products for display with error checking
try {
    error_log("Fetching products for category ID: " . $category_id);
    
    $stmt = $conn->prepare("SELECT * FROM products WHERE category_id = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        throw new Exception("Error preparing statement: " . $conn->error);
    }
    
    if (!$stmt->bind_param("i", $category_id)) {
        error_log("Bind failed: " . $stmt->error);
        throw new Exception("Error binding parameter: " . $stmt->error);
    }
    
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        throw new Exception("Error executing statement: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    if (!$result) {
        error_log("Get result failed: " . $stmt->error);
        throw new Exception("Error getting result: " . $stmt->error);
    }
    
    $products = $result->fetch_all(MYSQLI_ASSOC);
    error_log("Found " . count($products) . " products");
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amazon Price Tracker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .product-comparison {
            display: flex;
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .product {
            flex: 1;
            text-align: center;
            padding: 15px;
        }
        .logo {
            max-width: 100px;
            height: auto;
            margin-bottom: 10px;
        }
        .price {
            font-size: 1.4em;
            font-weight: bold;
            color: #e47911;
            margin: 10px 0;
        }
        select {
            padding: 8px;
            font-size: 16px;
            border-radius: 4px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }
        .product-title {
            font-size: 1.1em;
            margin: 10px 0;
            color: #333;
        }
        .view-button {
            display: inline-block;
            padding: 8px 15px;
            background-color: #f0c14b;
            border: 1px solid #a88734;
            border-radius: 3px;
            color: #111;
            text-decoration: none;
            margin-top: 10px;
        }
        .view-button:hover {
            background-color: #f4d078;
        }
        .product-image {
            max-width: 200px;
            height: auto;
            margin: 10px 0;
        }
        .error-message {
            color: #d00;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #d00;
            border-radius: 4px;
            background-color: #fee;
        }
        .success-message {
            color: #0a0;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #0a0;
            border-radius: 4px;
            background-color: #efe;
        }
    </style>
</head>
<body>
    <h1>Amazon Price Tracker</h1>
    
    <select onchange="window.location.href='?category='+this.value">
        <option value="1" <?php echo $category_id == 1 ? 'selected' : ''; ?>>Electronics</option>
        <option value="2" <?php echo $category_id == 2 ? 'selected' : ''; ?>>Home & Kitchen</option>
        <option value="3" <?php echo $category_id == 3 ? 'selected' : ''; ?>>Fashion</option>
    </select>

    <?php if (empty($products)): ?>
        <div class="error-message">
            <p>No products found in this category.</p>
            <p>This might be because:</p>
            <ul>
                <li>The category is empty</li>
                <li>There was an error fetching products</li>
                <li>The database connection failed</li>
            </ul>
            <p>Try selecting a different category or refreshing the page.</p>
        </div>
    <?php else: ?>
        <div class="success-message">
            Found <?php echo count($products); ?> products in this category.
        </div>
        
        <?php foreach ($products as $product): ?>
        <div class="product-comparison">
            <div class="product">
                <?php if (file_exists('amazon-logo.png')): ?>
                    <img src="amazon-logo.png" class="logo" alt="Amazon">
                <?php else: ?>
                    <div class="error-message">Amazon logo not found</div>
                <?php endif; ?>

                <?php if (!empty($product['amazon_image'])): ?>
                    <img src="<?php echo htmlspecialchars($product['amazon_image']); ?>" 
                         class="product-image" 
                         alt="<?php echo htmlspecialchars($product['amazon_title']); ?>"
                         onerror="this.onerror=null; this.src='placeholder.png';">
                <?php endif; ?>

                <h3 class="product-title"><?php echo htmlspecialchars($product['amazon_title']); ?></h3>
                <p class="price">$<?php echo number_format($product['amazon_price'], 2); ?></p>
                <a href="<?php echo htmlspecialchars($product['amazon_url']); ?>" 
                   class="view-button" 
                   target="_blank" 
                   rel="noopener noreferrer">View on Amazon</a>
            </div>
            
            <?php if (ALIEXPRESS_ENABLED && !empty($product['aliexpress_product_id'])): ?>
            <div class="product">
                <?php if (file_exists('aliexpress-logo.png')): ?>
                    <img src="aliexpress-logo.png" class="logo" alt="AliExpress">
                <?php else: ?>
                    <div class="error-message">AliExpress logo not found</div>
                <?php endif; ?>

                <?php if (!empty($product['aliexpress_image'])): ?>
                    <img src="<?php echo htmlspecialchars($product['aliexpress_image']); ?>" 
                         class="product-image" 
                         alt="<?php echo htmlspecialchars($product['aliexpress_title']); ?>"
                         onerror="this.onerror=null; this.src='placeholder.png';">
                <?php endif; ?>

                <h3 class="product-title"><?php echo htmlspecialchars($product['aliexpress_title']); ?></h3>
                <p class="price">$<?php echo number_format($product['aliexpress_price'], 2); ?></p>
                <a href="<?php echo htmlspecialchars($product['aliexpress_url']); ?>" 
                   class="view-button" 
                   target="_blank" 
                   rel="noopener noreferrer">View on AliExpress</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['last_fetch'])): ?>
    <div class="success-message">
        Last updated: <?php echo date('Y-m-d H:i:s', $_SESSION['last_fetch']); ?>
    </div>
    <?php endif; ?>
</body>
</html>
