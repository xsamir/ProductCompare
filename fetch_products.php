<?php
require_once 'config.php';

function fetchAmazonProducts($category) {
    global $conn;
    
    error_log("Starting fetchAmazonProducts for category: " . $category);
    
    try {
        $batchSize = 5; // Process 5 items at a time
        $totalSaved = 0;
        
        for ($page = 1; $page <= 2; $page++) { // 2 pages of 5 items each = 10 items
            $payload = [
                'PartnerTag' => PA_PARTNER_TAG,
                'PartnerType' => 'Associates',
                'Operation' => 'SearchItems',
                'Keywords' => getCategoryKeywords($category),
                'SearchIndex' => 'All',
                'ItemCount' => $batchSize,
                'ItemPage' => $page,
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
            
            error_log("Processing page $page");
            
            try {
                $response = makeSignedAmazonRequest($payload);
                $products = json_decode($response, true);
                
                if (isset($products['SearchResult']['Items'])) {
                    foreach ($products['SearchResult']['Items'] as $item) {
                        $productData = [
                            'id' => $item['ASIN'],
                            'price' => $item['Offers']['Listings'][0]['Price']['Amount'] ?? 0,
                            'title' => $item['ItemInfo']['Title']['DisplayValue'] ?? '',
                            'image' => $item['Images']['Primary']['Large']['URL'] ?? '',
                            'url' => $item['DetailPageURL'] ?? '',
                            'category_id' => $category
                        ];
                        
                        if (saveAmazonProduct($productData)) {
                            $totalSaved++;
                        }
                    }
                }
                
                // Wait between pages
                sleep(1);
                
            } catch (Exception $e) {
                error_log("Error on page $page: " . $e->getMessage());
                continue; // Try next page even if this one fails
            }
        }
        
        error_log("Total products saved: $totalSaved");
        return $totalSaved > 0;
        
    } catch (Exception $e) {
        error_log("Error in fetchAmazonProducts: " . $e->getMessage());
        return false;
    }
}

function makeSignedAmazonRequest($payload, $maxRetries = 3) {
    static $lastRequestTime = 0;
    
    $response = '';
    $httpCode = 0;
    
    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        try {
            // Rate limiting
            $currentTime = microtime(true);
            $timeSinceLastRequest = $currentTime - $lastRequestTime;
            if ($timeSinceLastRequest < PA_API_DELAY/1000000) {
                usleep(PA_API_DELAY);
            }
            
            $host = PA_HOST;
            $uri = '/paapi5/searchitems';
            $timestamp = gmdate('Ymd\THis\Z');
            $date = substr($timestamp, 0, 8);
            
            // Standard required headers
            $headers = [
                'content-encoding' => 'amz-1.0',
                'content-type' => 'application/json; charset=utf-8',
                'host' => $host,
                'x-amz-date' => $timestamp,
                'x-amz-target' => 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems'
            ];
            
            // Create canonical request
            $canonicalHeaders = '';
            ksort($headers);
            foreach ($headers as $key => $value) {
                $canonicalHeaders .= strtolower($key) . ':' . $value . "\n";
            }
            
            $signedHeaders = implode(';', array_keys($headers));
            $payloadJson = json_encode($payload);
            $hashedPayload = hash('sha256', $payloadJson);
            
            // Create canonical request string
            $canonicalRequest = "POST\n" .
                              "$uri\n" .
                              "\n" .
                              $canonicalHeaders . "\n" .
                              $signedHeaders . "\n" .
                              $hashedPayload;

            // Create credential scope
            $credentialScope = $date . '/' . PA_REGION . '/' . PA_SERVICE . '/aws4_request';
            
            // Create string to sign
            $stringToSign = "AWS4-HMAC-SHA256\n" .
                           $timestamp . "\n" .
                           $credentialScope . "\n" .
                           hash('sha256', $canonicalRequest);
            
            // Calculate signing key
            $kSecret = 'AWS4' . PA_API_SECRET;
            $kDate = hash_hmac('sha256', $date, $kSecret, true);
            $kRegion = hash_hmac('sha256', PA_REGION, $kDate, true);
            $kService = hash_hmac('sha256', PA_SERVICE, $kRegion, true);
            $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
            $signature = hash_hmac('sha256', $stringToSign, $kSigning);
            
            // Add authorization header
            $headers['Authorization'] = 'AWS4-HMAC-SHA256 ' .
                                      'Credential=' . PA_API_KEY . '/' . $credentialScope . ', ' .
                                      'SignedHeaders=' . $signedHeaders . ', ' .
                                      'Signature=' . $signature;
            
            error_log("Making PA-API request");
            error_log("Headers: " . print_r($headers, true));
            error_log("Payload: " . $payloadJson);
			
            // Initialize cURL
            $ch = curl_init('https://' . $host . $uri);
            if (!$ch) {
                throw new Exception("Failed to initialize CURL");
            }
            
            // Set cURL options
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array_map(
                    function($k, $v) { return "$k: $v"; },
                    array_keys($headers),
                    $headers
                ),
                CURLOPT_VERBOSE => true,
                CURLOPT_STDERR => fopen('php://stderr', 'w')
            ]);
            
            // Execute request
            $response = curl_exec($ch);
            if ($response === false) {
                throw new Exception("CURL Error: " . curl_error($ch));
            }
            
            // Get HTTP code
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            error_log("PA-API Response Code: " . $httpCode);
            error_log("PA-API Response: " . $response);
            
            // Handle rate limiting
            if ($httpCode === 429) {
                error_log("Rate limit hit, attempt " . ($attempt + 1) . " of " . ($maxRetries + 1));
                sleep(pow(2, $attempt)); // Exponential backoff
                continue;
            }
            
            // Handle other errors
            if ($httpCode !== 200) {
                throw new Exception("PA-API Error: HTTP " . $httpCode . " - " . $response);
            }
            
            // Update last request time
            $lastRequestTime = microtime(true);
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Request failed on attempt " . ($attempt + 1) . ": " . $e->getMessage());
            if ($attempt === $maxRetries) {
                throw $e;
            }
            sleep(pow(2, $attempt)); // Exponential backoff
        }
    }
    
    throw new Exception("Failed after " . $maxRetries . " attempts");
}

function backoff($attempt) {
    $baseDelay = PA_API_DELAY;
    $maxDelay = 32 * $baseDelay;
    $delay = min($maxDelay, $baseDelay * pow(2, $attempt));
    usleep($delay);
}

function debugSignature($canonicalRequest, $stringToSign, $signature) {
    error_log("\n=== Debug Signature ===");
    error_log("Canonical Request:\n" . $canonicalRequest);
    error_log("\nString to Sign:\n" . $stringToSign);
    error_log("\nSignature: " . $signature);
    error_log("=====================\n");
}

function getAuthorizationHeader($payload, $timestamp, $region, $service) {
    $algorithm = 'AWS4-HMAC-SHA256';
    $date = substr($timestamp, 0, 8);
    $credentialScope = "$date/$region/$service/aws4_request";
    
    // Create canonical request
    $canonicalHeaders = "content-encoding:amz-1.0\n" .
                       "content-type:application/json; charset=utf-8\n" .
                       "host:webservices.amazon.com\n" .
                       "x-amz-date:$timestamp\n" .
                       "x-amz-target:com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems\n";
    
    $canonicalRequest = "POST\n" .
                       "/paapi5/searchitems\n" .
                       "\n" .
                       $canonicalHeaders . "\n" .
                       "content-encoding;content-type;host;x-amz-date;x-amz-target\n" .
                       hash('sha256', $payload);
    
    // Create string to sign
    $stringToSign = "$algorithm\n$timestamp\n$credentialScope\n" . 
                   hash('sha256', $canonicalRequest);
    
    // Calculate signature
    $dateKey = hash_hmac('sha256', $date, 'AWS4' . PA_API_SECRET, true);
    $regionKey = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    
    return "$algorithm Credential=" . PA_API_KEY . "/$credentialScope, " .
           "SignedHeaders=content-encoding;content-type;host;x-amz-date;x-amz-target, " .
           "Signature=$signature";
}



function generateDummyProducts($category) {
    try {
        $savedCount = 0;
        
        for ($i = 1; $i <= 5; $i++) {
            $product = [
                'id' => 'B0' . str_pad(rand(1000, 9999), 5, '0', STR_PAD_LEFT),
                'price' => rand(999, 99999) / 100,
                'title' => "Sample Product #$i for Category $category",
                'image' => 'https://via.placeholder.com/200',
                'url' => 'https://amazon.com/sample-' . $i,
                'category_id' => $category
            ];
            
            if (saveAmazonProduct($product)) {
                $savedCount++;
            }
        }
        
        echo "Generated and saved $savedCount dummy products<br>";
        return true;
        
    } catch (Exception $e) {
        error_log("Error generating dummy products: " . $e->getMessage());
        return false;
    }
}

function saveAmazonProduct($product) {
    global $conn;
    
    try {
        error_log("Starting saveAmazonProduct for ASIN: " . $product['id']);
        
        if (empty($product['id'])) {
            error_log("Invalid product data: Missing ASIN");
            return false;
        }
        
        $stmt = $conn->prepare("INSERT INTO products 
                               (amazon_product_id, amazon_price, amazon_title, 
                                amazon_image, amazon_url, category_id) 
                               VALUES (?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE 
                               amazon_price = VALUES(amazon_price),
                               amazon_title = VALUES(amazon_title),
                               amazon_image = VALUES(amazon_image),
                               amazon_url = VALUES(amazon_url)");
        
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        error_log("Binding parameters for ASIN: " . $product['id']);
        $stmt->bind_param("sdsssi", 
            $product['id'],
            $product['price'],
            $product['title'],
            $product['image'],
            $product['url'],
            $product['category_id']
        );
        
        error_log("Executing statement for ASIN: " . $product['id']);
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        error_log("Successfully saved/updated ASIN: " . $product['id']);
        return true;
        
    } catch (Exception $e) {
        error_log("Error saving product: " . $e->getMessage());
        return false;
    }
}

function getCategoryKeywords($category) {
    $keywords = [
        1 => 'electronics computers',
        2 => 'home kitchen appliances',
        3 => 'fashion clothing accessories'
    ];
    return $keywords[$category] ?? 'general';
}

function findAliExpressMatch($amazonProduct) {
    if (!ALIEXPRESS_ENABLED) return false;
    
    try {
        $searchTerm = urlencode(cleanProductTitle($amazonProduct['title']));
        
        $url = "https://api.aliexpress.com/v2/products/search?" . 
               "keywords={$searchTerm}&" .
               "api_key=" . ALIEXPRESS_API_KEY;
        
        $response = file_get_contents($url);
        
        if ($response === FALSE) {
            throw new Exception("Failed to fetch AliExpress products");
        }
        
        $aliProduct = json_decode($response, true);
        
        if ($aliProduct && !empty($aliProduct['items'])) {
            $bestMatch = $aliProduct['items'][0];
            
            $matchedProduct = [
                'id' => $bestMatch['product_id'],
                'price' => $bestMatch['price'],
                'title' => $bestMatch['title'],
                'image' => $bestMatch['image_url'],
                'url' => $bestMatch['product_url']
            ];
            
            updateAliExpressMatch($amazonProduct['id'], $matchedProduct);
            return true;
        }
        
    } catch (Exception $e) {
        error_log("Error finding AliExpress match: " . $e->getMessage());
    }
    
    return false;
}

function cleanProductTitle($title) {
    $title = preg_replace('/\b(?:by|from|with)\b.*$/i', '', $title);
    $title = preg_replace('/[^\w\s]/', ' ', $title);
    $title = trim(preg_replace('/\s+/', ' ', $title));
    return $title;
}




function updateAliExpressMatch($amazonProductId, $aliProduct) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("UPDATE products 
                               SET aliexpress_product_id = ?,
                                   aliexpress_price = ?,
                                   aliexpress_title = ?,
                                   aliexpress_image = ?,
                                   aliexpress_url = ?
                               WHERE amazon_product_id = ?");
        
        if (!$stmt) {
            throw new Exception($conn->error);
        }
        
        $stmt->bind_param("sdssss",
            $aliProduct['id'],
            $aliProduct['price'],
            $aliProduct['title'],
            $aliProduct['image'],
            $aliProduct['url'],
            $amazonProductId
        );
        
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error updating AliExpress match: " . $e->getMessage());
        return false;
    }
}
