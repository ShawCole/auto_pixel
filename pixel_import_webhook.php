<?php
// pixel_import_webhook.php - Improved webhook handler with test support

header('Content-Type: application/json');

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';
$client = isset($_GET['client']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['client']) : null;

// Log function for debugging
function debugLog($message) {
    $logFile = '/var/www/hook.thynkdata.com/pixel_webhook_debug.log';
    file_put_contents($logFile, "[" . date('c') . "] " . $message . "\n", FILE_APPEND);
}

debugLog("Webhook called - Method: {$_SERVER['REQUEST_METHOD']}, Client: " . ($client ?: 'none'));

// Handle webhook test from SimpleAudience (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    debugLog("Handling GET request - webhook test");
    
    if (!$client) {
        http_response_code(400);
        echo json_encode(['error' => 'Client parameter required']);
        exit;
    }
    
    // For GET requests (webhook tests), just verify we can connect to MySQL
    // Don't require the specific database to exist yet
    try {
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
        if ($mysqli->connect_error) {
            throw new Exception("MySQL connection failed: " . $mysqli->connect_error);
        }
        $mysqli->close();
        
        debugLog("Webhook test successful for client: $client");
        echo json_encode(['status' => 'success', 'message' => 'Webhook endpoint is ready', 'client' => $client]);
        exit;
    } catch (Exception $e) {
        debugLog("Webhook test failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database connection test failed']);
        exit;
    }
}

// Handle actual webhook data (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$client) {
        http_response_code(400);
        echo json_encode(['error' => 'Client parameter required']);
        exit;
    }
    
    $dbName = $client;
    
    // Try to connect to the specific client database
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    
    if ($mysqli->connect_error) {
        debugLog("Database connection failed for client $client: " . $mysqli->connect_error);
        
        // If database doesn't exist, try to create it
        if (strpos($mysqli->connect_error, 'Unknown database') !== false) {
            debugLog("Attempting to create database for client: $client");
            
            try {
                // Connect without specifying database
                $rootConn = new mysqli($dbHost, $dbUser, $dbPass);
                if ($rootConn->connect_error) {
                    throw new Exception("Root connection failed: " . $rootConn->connect_error);
                }
                
                // Create database
                $rootConn->query("CREATE DATABASE IF NOT EXISTS `$dbName`");
                
                // Create tables (copy structure from template)
                $templateDb = 'pixel'; // Your template database
                $tables = ['superpixel_resolution_log', 'superpixel_visitors'];
                
                foreach ($tables as $table) {
                    $createTableQuery = "CREATE TABLE IF NOT EXISTS `$dbName`.`$table` LIKE `$templateDb`.`$table`";
                    $rootConn->query($createTableQuery);
                }
                
                $rootConn->close();
                
                // Reconnect to the new database
                $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                if ($mysqli->connect_error) {
                    throw new Exception("Failed to connect to newly created database");
                }
                
                debugLog("Successfully created database and tables for client: $client");
                
            } catch (Exception $e) {
                debugLog("Failed to create database: " . $e->getMessage());
                send_email(['Database Creation Error' => $e->getMessage()], "Failed to create database for $client");
                http_response_code(500);
                echo json_encode(['error' => 'Database setup failed']);
                exit;
            }
        } else {
            // Other connection error
            send_email(['Database Connection Error' => $mysqli->connect_error], "Database Connection Error");
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }
    }
    
    // Process the webhook data
    try {
        $rawData = file_get_contents('php://input');
        $decoded = json_decode($rawData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON: " . json_last_error_msg());
        }
        
        debugLog("Processing webhook data for client: $client");
        
        // Insert data logic here (same as original script)
        if (isset($decoded['events']) && is_array($decoded['events'])) {
            $events = $decoded['events'];
            
            foreach ($events as $event) {
                // ... rest of the insert logic from original script ...
            }
        }
        
        echo json_encode(['status' => 'success']);
        
    } catch (Exception $e) {
        debugLog("Error processing webhook: " . $e->getMessage());
        send_email(['Webhook Processing Error' => $e->getMessage()], "Webhook Error for $client");
        http_response_code(500);
        echo json_encode(['error' => 'Processing failed']);
    }
}

// Send email function (from original)
function send_email($data, $subject_text) {
    // ... copy from original script ...
}
?> 