<?php
// Future-proof dynamic pixel import that adapts to any field structure
// This version will never break when AudienceLab changes their headers

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include email processing if available
if (file_exists(__DIR__ . '/process_visitor_emails.php')) {
    require_once __DIR__ . '/process_visitor_emails.php';
}

function debugLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    error_log($logMessage, 3, __DIR__ . '/pixel_import_debug.log');
}

function ensureColumnExists($mysqli, $table, $column, $dataType = 'TEXT') {
    // Check if column exists
    $result = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result && $result->num_rows == 0) {
        // Column doesn't exist, add it
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $dataType";
        if ($mysqli->query($sql)) {
            debugLog("✅ Added new column: $table.$column");
            return true;
        } else {
            debugLog("❌ Failed to add column $table.$column: " . $mysqli->error);
            return false;
        }
    }
    return true; // Column already exists
}

function processEventDynamic($mysqli, $event, $eventIndex) {
    // Core fields that we always expect
    $coreFields = [
        'uuid', 'created_at', 'ip_address'
    ];
    
    // Initialize insert data with defaults
    $insert_data = [
        'created_at' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    
    // Track new columns added
    $newColumns = [];
    
    // Process each field in the event
    foreach ($event as $key => $value) {
        // Skip if value is an array or object (handle nested data differently)
        if (is_array($value) || is_object($value)) {
            // For nested data, serialize it or extract specific fields
            if ($key === 'resolution' && is_array($value)) {
                // Handle nested resolution data
                foreach ($value as $resKey => $resValue) {
                    if (!is_array($resValue) && !is_object($resValue)) {
                        $columnName = 'resolution_' . $resKey;
                        ensureColumnExists($mysqli, 'superpixel_resolution_log', $columnName);
                        $insert_data[$columnName] = $resValue;
                    }
                }
            } elseif ($key === 'event_data' && is_array($value)) {
                // Handle nested event_data
                foreach ($value as $eventKey => $eventValue) {
                    if (!is_array($eventValue) && !is_object($eventValue)) {
                        ensureColumnExists($mysqli, 'superpixel_resolution_log', $eventKey);
                        $insert_data[$eventKey] = $eventValue;
                    }
                }
            } else {
                // For other nested data, JSON encode it
                $columnName = $key . '_json';
                ensureColumnExists($mysqli, 'superpixel_resolution_log', $columnName, 'JSON');
                $insert_data[$columnName] = json_encode($value);
            }
        } else {
            // Simple field - ensure column exists and add to insert
            ensureColumnExists($mysqli, 'superpixel_resolution_log', $key);
            $insert_data[$key] = $value;
        }
    }
    
    // Build dynamic SQL
    $columns = [];
    $values = [];
    
    foreach ($insert_data as $key => $value) {
        $columns[] = "`" . $mysqli->real_escape_string($key) . "`";
        $values[] = "'" . $mysqli->real_escape_string($value ?? '') . "'";
    }
    
    // Insert into database
    $sql = "INSERT INTO superpixel_resolution_log (" . implode(",", $columns) . ") VALUES (" . implode(",", $values) . ")";
    
    if (!$mysqli->query($sql)) {
        throw new Exception("Insert failed: " . $mysqli->error);
    }
    
    debugLog("✅ Successfully inserted event $eventIndex with " . count($insert_data) . " fields");
    
    return $insert_data;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        debugLog("Raw input received (first 500 chars): " . substr($rawInput, 0, 500));
        
        // Log full raw input for debugging
        file_put_contents(__DIR__ . '/last_webhook_raw.json', $rawInput);
        
        $requestData = json_decode($rawInput, true);
        if (!$requestData) {
            throw new Exception('Invalid JSON received');
        }
        
        // Handle different possible structures
        $events = [];
        
        // Check for different event structures
        if (isset($requestData['events'])) {
            $events = $requestData['events'];
        } elseif (isset($requestData['data']) && isset($requestData['data']['events'])) {
            $events = $requestData['data']['events'];
        } elseif (isset($requestData['event'])) {
            // Single event
            $events = [$requestData['event']];
        } else {
            // Assume the whole payload is an event
            $events = [$requestData];
        }
        
        $eventsCount = count($events);
        debugLog("Processing $eventsCount events");
        
        // Get client from URL parameter
        $client = $_GET['client'] ?? '';
        if (empty($client)) {
            throw new Exception('Client parameter is required');
        }
        
        // Database connection
        $host = '34.26.61.148';
        $user = 'root';
        $pass = 'AccuPoint01!';
        
        $mysqli = new mysqli($host, $user, $pass, $client);
        if ($mysqli->connect_error) {
            throw new Exception("Database connection failed: " . $mysqli->connect_error);
        }
        
        $processedEvents = 0;
        
        foreach ($events as $eventIndex => $event) {
            try {
                // Process event with dynamic field mapping
                $insertedData = processEventDynamic($mysqli, $event, $eventIndex);
                
                // If we have a UUID, process emails and NPN/CRD
                if (!empty($insertedData['uuid']) && function_exists('processVisitorEmails')) {
                    $uuid = $insertedData['uuid'];
                    debugLog("Processing emails for UUID: $uuid");
                    
                    try {
                        $emailResults = processVisitorEmails($client, $uuid, true, false);
                        if ($emailResults['npn_found'] || $emailResults['crd_found']) {
                            debugLog("✅ NPN/CRD found for $uuid");
                        }
                    } catch (Exception $e) {
                        debugLog("⚠️ Email processing failed: " . $e->getMessage());
                    }
                }
                
                $processedEvents++;
            } catch (Exception $e) {
                debugLog("❌ Failed to process event $eventIndex: " . $e->getMessage());
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'processed' => $processedEvents,
            'total' => $eventsCount
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    debugLog("❌ Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'message' => $e->getMessage()]);
}
?> 