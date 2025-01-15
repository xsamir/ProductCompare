<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';
require_once 'fetch_products.php';

echo "<pre>";
echo "Testing PA-API Integration\n";
echo "------------------------\n\n";

// Test 1: Database Connection
echo "1. Testing Database Connection...\n";
if ($conn->ping()) {
    echo "✓ Database connection successful\n";
    
    // Test table exists
    $result = $conn->query("SHOW TABLES LIKE 'products'");
    if ($result->num_rows > 0) {
        echo "✓ Products table exists\n";
    } else {
        echo "✗ Products table does not exist!\n";
    }
} else {
    echo "✗ Database connection failed!\n";
}

echo "\n2. Testing PA-API Credentials...\n";
echo "Access Key: " . (defined('PA_API_KEY') ? substr(PA_API_KEY, 0, 5) . '...' : '✗ NOT SET') . "\n";
echo "Secret Key: " . (defined('PA_API_SECRET') ? 'Present' : '✗ NOT SET') . "\n";
echo "Partner Tag: " . (defined('PA_PARTNER_TAG') ? PA_PARTNER_TAG : '✗ NOT SET') . "\n";

echo "\n3. Making Test API Request...\n";
try {
    $testPayload = [
        'PartnerTag' => PA_PARTNER_TAG,
        'PartnerType' => 'Associates',
        'Operation' => 'SearchItems',
        'Keywords' => 'laptop',
        'SearchIndex' => 'All',
        'ItemCount' => 1,
        'Resources' => [
            'Images.Primary.Large',
            'ItemInfo.Title',
            'Offers.Listings.Price',
            'DetailPageURL'
        ],
        'Availability' => 'Available',
        'CurrencyOfPreference' => 'USD',
        'LanguagesOfPreference' => ['en_US'],
        'Marketplace' => 'www.amazon.com'
    ];
    
    echo "Sending request...\n";
    $response = makeSignedAmazonRequest($testPayload);
    
    echo "Response received!\n";
    $decoded = json_decode($response, true);
    
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✓ Valid JSON response\n";
        if (isset($decoded['SearchResult']['Items'])) {
            echo "✓ Found " . count($decoded['SearchResult']['Items']) . " items\n";
            echo "\nFirst item details:\n";
            print_r($decoded['SearchResult']['Items'][0]);
        } else {
            echo "✗ No items in response\n";
            echo "Response structure:\n";
            print_r($decoded);
        }
    } else {
        echo "✗ Invalid JSON response:\n";
        echo $response;
    }
    
} catch (Exception $e) {
    echo "✗ API Request failed:\n";
    echo $e->getMessage() . "\n";
}

echo "\n4. Testing Database Insert...\n";
try {
    $testProduct = [
        'id' => 'B0TEST' . rand(1000, 9999),
        'price' => 99.99,
        'title' => 'Test Product ' . date('Y-m-d H:i:s'),
        'image' => 'https://example.com/test.jpg',
        'url' => 'https://amazon.com/test',
        'category_id' => 1
    ];
    
    if (saveAmazonProduct($testProduct)) {
        echo "✓ Test product saved successfully\n";
        
        // Verify it was saved
        $stmt = $conn->prepare("SELECT * FROM products WHERE amazon_product_id = ?");
        $stmt->bind_param('s', $testProduct['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "✓ Test product verified in database\n";
        } else {
            echo "✗ Could not verify test product in database\n";
        }
    } else {
        echo "✗ Failed to save test product\n";
    }
} catch (Exception $e) {
    echo "✗ Database test failed:\n";
    echo $e->getMessage() . "\n";
}

echo "</pre>";
