<?php
// بيانات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_USER', 'اسم السمتخدم');
define('DB_PASS', 'كلمة السر');
define('DB_NAME', 'اسم قاعدة البيانات');

// PA-API Configuration
define('PA_API_REQUESTS_PER_SECOND', 1);
define('PA_API_DELAY', 1000000); // 1 second in microseconds
define('PA_API_KEY', $credentials['pa_api_key']);
define('PA_API_SECRET', $credentials['pa_api_secret']);
define('PA_PARTNER_TAG', $credentials['partner_tag']);
define('PA_HOST', 'webservices.amazon.com');
define('PA_REGION', 'us-east-1');
define('PA_SERVICE', 'ProductAdvertisingAPI');

// AliExpress Configuration - Add this part
define('ALIEXPRESS_ENABLED', false);  // Set to true if you want to enable AliExpress
define('ALIEXPRESS_API_KEY', '');     // Your AliExpress API key if enabled

// Error logging
ini_set('log_errors', 1);
ini_set('error_log', 'pa-api-errors.log');
error_reporting(E_ALL);

// Database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}
