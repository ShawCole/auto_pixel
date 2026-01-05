<?php
// Optimized Webhook Handler - Batches email processing to avoid delays
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the email processing function
require_once __DIR__ . '/process_visitor_emails.php';

// Field mapping from AudienceLab (uppercase) to database (lowercase)
$FIELD_MAPPING = [
    'UUID' => 'uuid',
    'HEM_SHA256' => 'hem_sha256',
    'FIRST_NAME' => 'first_name',
    'LAST_NAME' => 'last_name',
    'BUSINESS_EMAIL' => 'business_email',
    'PERSONAL_EMAILS' => 'personal_emails',
    'PERSONAL_ADDRESS' => 'personal_address',
    'PERSONAL_CITY' => 'personal_city',
    'PERSONAL_STATE' => 'personal_state',
    'PERSONAL_ZIP' => 'personal_zip',
    'PERSONAL_ZIP4' => 'personal_zip4',
    'MOBILE_PHONE' => 'mobile_phone',
    'AGE_RANGE' => 'age_range',
    'CHILDREN' => 'children',
    'GENDER' => 'gender',
    'HOMEOWNER' => 'homeowner',
    'MARRIED' => 'married',
    'NET_WORTH' => 'net_worth',
    'INCOME_RANGE' => 'income_range',
    'CREDIT_RANGE' => 'credit_range',
    'INVESTOR' => 'investor',
    'LINES_OF_CREDIT' => 'lines_of_credit',
    'MORTGAGE_LOAN_TYPE' => 'mortgage_loan_type',
    'MORTGAGE_AGE' => 'mortgage_age',
    'PHONE_NUMBERS' => 'phone_numbers',
    'PHONE_ACTIVITY' => 'phone_activity',
    'PHONE_FIRST_SEEN' => 'phone_first_seen',
    'PHONE_LAST_SEEN' => 'phone_last_seen',
    'PHONE_CARRIER' => 'phone_carrier',
    'PHONE_LINE_TYPE' => 'phone_line_type',
    'PHONE_PREPAID' => 'phone_prepaid',
    'EMAIL_ADDRESSES' => 'email_addresses',
    'EMAIL_HASH' => 'email_hash',
    'EMAIL_ACTIVITY' => 'email_activity',
    'EMAIL_FIRST_SEEN' => 'email_first_seen',
    'EMAIL_LAST_SEEN' => 'email_last_seen',
    'SKIPTRACE_PROPERTY_ADDRESS' => 'skiptrace_property_address',
    'SKIPTRACE_NAME' => 'skiptrace_name',
    'SKIPTRACE_MAILING' => 'skiptrace_mailing',
    'SKIPTRACE_CITY' => 'skiptrace_city',
    'SKIPTRACE_STATE' => 'skiptrace_state',
    'SKIPTRACE_ZIP' => 'skiptrace_zip',
    'SKIPTRACE_COUNTY' => 'skiptrace_county',
    'SKIPTRACE_HOME_VALUE' => 'skiptrace_home_value',
    'SKIPTRACE_ESTIMATED_EQUITY' => 'skiptrace_estimated_equity',
    'SKIPTRACE_OCCUPIED' => 'skiptrace_occupied',
    'SKIPTRACE_CORPORATE_OWNED' => 'skiptrace_corporate_owned',
    'SKIPTRACE_EXACT_AGE' => 'skiptrace_exact_age',
    'SKIPTRACE_CREDIT_RATING' => 'skiptrace_credit_rating',
    'SKIPTRACE_LANGUAGE_CODE' => 'skiptrace_language_code',
    'SKIPTRACE_IP' => 'skiptrace_ip',
    'SKIPTRACE_MATCH_SCORE' => 'skiptrace_match_score'
];

function debugLog($message) {
    $logFile = __DIR__ . '/pixel_import_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function connectToDatabase($client) {
    $host = '34.26.61.148';
    $username = 'root';
    $password = 'AccuPoint01!';
    
    $conn = new mysqli($host, $username, $password, $client);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

try {
    // Get client from query string
    $client = $_GET['client'] ?? '';
    if (empty($client)) {
        throw new Exception("Client parameter is required");
    }
    
    debugLog("=== New webhook request for client: $client ===");
    
    // Get raw input
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception("No input data received");
    }
    
    debugLog("Raw input received (first 500 chars): " . substr($input, 0, 500));
    
    // Parse JSON
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }
    
    // Get events array
    $events = $data['events'] ?? [];
    $eventCount = count($events);
    debugLog("Processing $eventCount events");
    
    if ($eventCount === 0) {
        echo json_encode(['status' => 'success', 'processed' => 0]);
        exit;
    }
    
    // Connect to database
    $conn = connectToDatabase($client);
    
    // Collect UUIDs for batch email processing
    $uuidsToProcess = [];
    $successCount = 0;
    $errors = [];
    
    // Process each event
    foreach ($events as $eventIndex => $event) {
        try {
            debugLog("Processing event $eventIndex");
            
            // Build insert data with mapped fields
            $insert_data = [
                'pixel_id' => $event['pixel_id'] ?? null,
                'hem_sha256' => $event['hem_sha256'] ?? null,
                'event_timestamp' => $event['event_timestamp'] ?? null,
                'event_type' => $event['event_type'] ?? null,
                'ip_address' => $event['ip_address'] ?? null,
                'activity_start_date' => $event['activity_start_date'] ?? null,
                'activity_end_date' => $event['activity_end_date'] ?? null
            ];
            
            // Map resolution fields
            if (isset($event['resolution']) && is_array($event['resolution'])) {
                foreach ($event['resolution'] as $key => $value) {
                    if (isset($FIELD_MAPPING[$key])) {
                        $mapped_key = $FIELD_MAPPING[$key];
                        $insert_data[$mapped_key] = $value;
                    } else {
                        debugLog("Unmapped resolution field: $key");
                    }
                }
            }
            
            // Add event_data as JSON
            if (isset($event['event_data'])) {
                // Extract specific fields from event_data
                $eventData = $event['event_data'];
                $insert_data['element'] = isset($eventData['element']) ? json_encode($eventData['element']) : null;
                $insert_data['percentage'] = $eventData['percentage'] ?? null;
                $insert_data['referrer'] = $eventData['referrer'] ?? null;
                $insert_data['timestamp'] = $eventData['timestamp'] ?? null;
                $insert_data['url'] = $eventData['url'] ?? null;
//                 $insert_data['domain'] = isset($eventData['url']) ? parse_url($eventData['url'], PHP_URL_HOST) : null;
            }
            
            // Add default NPN/CRD values (will be updated by email processing)
            $insert_data['npn'] = '101010';
            $insert_data['crd'] = '101010';
            
            // Build SQL query
            $columns = array_keys($insert_data);
            $values = array_values($insert_data);
            $placeholders = array_fill(0, count($columns), '?');
            
            $sql = "INSERT INTO superpixel_resolution_log (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            // Prepare and execute
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            // Build type string
            $types = str_repeat('s', count($values));
            $stmt->bind_param($types, ...$values);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $stmt->close();
            debugLog("✅ Successfully inserted event $eventIndex");
            
            // Collect UUID for batch processing (if exists and not already in list)
            if (!empty($insert_data['uuid']) && !in_array($insert_data['uuid'], $uuidsToProcess)) {
                $uuidsToProcess[] = $insert_data['uuid'];
            }
            
            $successCount++;
            
        } catch (Exception $e) {
            $error = "Failed to process event $eventIndex: " . $e->getMessage();
            debugLog("❌ $error");
            $errors[] = $error;
        }
    }
    
    // Close database connection before email processing
    $conn->close();
    
    // Process emails for all UUIDs in batch (after all events are inserted)
    if (!empty($uuidsToProcess) && function_exists('processVisitorEmails')) {
        debugLog("Starting batch email processing for " . count($uuidsToProcess) . " unique UUIDs");
        
        foreach ($uuidsToProcess as $uuid) {
            try {
                debugLog("Processing emails for UUID: $uuid");
                $emailResults = processVisitorEmails($client, $uuid, true, false);
                
                if ($emailResults['npn_found'] || $emailResults['crd_found']) {
                    debugLog("✅ NPN/CRD found for $uuid - NPN: " . 
                            ($emailResults['npn'] ?? 'null') . ", CRD: " . 
                            ($emailResults['crd'] ?? 'null'));
                }
            } catch (Exception $e) {
                debugLog("⚠️ Email processing failed for $uuid: " . $e->getMessage());
                // Don't fail the whole batch if one UUID fails
            }
        }
        
        debugLog("Completed batch email processing");
    }
    
    // Return response
    if ($successCount > 0) {
        echo json_encode(['status' => 'success', 'processed' => $successCount]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'All events failed', 'errors' => $errors]);
    }
    
} catch (Exception $e) {
    debugLog("❌ Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'message' => $e->getMessage()]);
}
?> 