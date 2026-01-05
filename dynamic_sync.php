<?php
// Dynamic Google Sheets Sync Script
// Automatically calculates optimal timing based on number of sheets
// Ensures every sheet syncs every 5 minutes while staying within rate limits

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;

$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/opt/auto-pixel/credentials.json';

// Configuration
$VISITORS_LIMIT = 10000;
$EVENTS_LIMIT = 100000;
$TARGET_SYNC_INTERVAL = 300; // 5 minutes in seconds
$RATE_LIMIT_WINDOW = 100;    // Google API rate limit window (seconds)
$MAX_REQUESTS_PER_WINDOW = 100; // Google API requests per window

function getGoogleClient() {
    global $credentialsPath;
    $client = new Client();
    $client->setAuthConfig($credentialsPath);
    $client->setScopes([
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive'
    ]);
    // $client->setSubject('scole@thynkdata.com');
    return $client;
}

function calculateOptimalTiming($totalSheets) {
    global $TARGET_SYNC_INTERVAL, $RATE_LIMIT_WINDOW, $MAX_REQUESTS_PER_WINDOW;
    
    // Each sheet (with 2 tabs) needs ~2 API calls (visitors + events) but they're paired
    // So we treat each sheet as 1 unit that updates both tabs simultaneously
    $apiCallsPerSheet = 2; // Still 2 calls, but they happen together
    $totalApiCalls = $totalSheets * $apiCallsPerSheet;
    
    // Calculate minimum time needed to stay within rate limits
    $minTimeNeeded = ($totalApiCalls / $MAX_REQUESTS_PER_WINDOW) * $RATE_LIMIT_WINDOW;
    
    // Calculate delay between sheets to spread over 5 minutes
    // Each sheet gets its full 5-minute cycle, just staggered
    $availableTime = $TARGET_SYNC_INTERVAL - 10; // Leave 10 seconds buffer
    $delayBetweenSheets = max(1, floor($availableTime / $totalSheets));
    
    // If we need more time than available, adjust the delay
    if ($minTimeNeeded > $availableTime) {
        $delayBetweenSheets = max(1, floor($minTimeNeeded / $totalSheets));
        echo "⚠️  Rate limit constraint: Need ${minTimeNeeded}s, using ${delayBetweenSheets}s delay\n";
    }
    
    return [
        'delay_between_sheets' => $delayBetweenSheets,
        'estimated_total_time' => $totalSheets * $delayBetweenSheets,
        'api_calls_per_sheet' => $apiCallsPerSheet,
        'total_api_calls' => $totalApiCalls,
        'rate_limit_safe' => ($totalApiCalls * $delayBetweenSheets) <= ($MAX_REQUESTS_PER_WINDOW * $RATE_LIMIT_WINDOW),
        'sync_frequency_per_sheet' => $TARGET_SYNC_INTERVAL / $totalSheets // How often each sheet gets updated
    ];
}

function syncAllSheetsDynamically($mysqli, $service) {
    global $VISITORS_LIMIT, $EVENTS_LIMIT;
    
    // Get all client sheets - prioritize newer sheets (NULL last_sync_at) first
    $sql = "SELECT * FROM pixel.pixel_sheets 
            WHERE sheet_id IS NOT NULL 
            ORDER BY last_sync_at IS NULL DESC, COALESCE(last_sync_at, '1970-01-01') ASC";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying pixel_sheets: " . $mysqli->error . "\n";
        return false;
    }
    
    $sheets = [];
    while ($row = $result->fetch_assoc()) {
        $sheets[] = $row;
    }
    
    $totalSheets = count($sheets);
    if ($totalSheets === 0) {
        echo "No sheets to sync\n";
        return true;
    }
    
    // Calculate optimal timing
    // Strategy: Each sheet (with both tabs) gets updated every 5 minutes
    // Sheets are staggered so they don't all update at the same time
    $timing = calculateOptimalTiming($totalSheets);
    
    echo "=== Dynamic Sync Started ===\n";
    echo "Total sheets: $totalSheets\n";
    echo "Delay between sheets: {$timing['delay_between_sheets']}s\n";
    echo "Estimated total time: {$timing['estimated_total_time']}s\n";
    echo "API calls per sheet: {$timing['api_calls_per_sheet']} (both tabs updated together)\n";
    echo "Total API calls: {$timing['total_api_calls']}\n";
    echo "Rate limit safe: " . ($timing['rate_limit_safe'] ? '✅ Yes' : '❌ No') . "\n";
    echo "Each sheet syncs every: " . round($timing['sync_frequency_per_sheet'], 1) . "s\n\n";
    
    $startTime = time();
    $successCount = 0;
    
    foreach ($sheets as $index => $sheet) {
        $sheetNumber = $index + 1;
        echo "=== Syncing {$sheet['client_name']} (Sheet $sheetNumber/$totalSheets) ===";
        echo "\nStarted at: " . date('Y-m-d H:i:s') . "\n";
        
        // Select client database
        $clientDb = $sheet['client_name'];
        if (!$mysqli->select_db($clientDb)) {
            echo "Error: Could not select database $clientDb\n";
            continue;
        }
        
        // Sync both tabs together (Visitors + Events)
        echo "Updating both tabs (Visitors + Events) for {$sheet['client_name']}...\n";
        $visitorsSuccess = syncVisitorsToSheet($mysqli, $sheet['client_name'], $sheet['sheet_id'], $service);
        $eventsSuccess = syncEventsToSheet($mysqli, $sheet['client_name'], $sheet['sheet_id'], $service, $sheet['last_sync_at']);
        
        // Update last sync time if successful
        if ($visitorsSuccess && $eventsSuccess) {
            $mysqli->select_db('pixel');
            $updateSql = "UPDATE pixel_sheets SET last_sync_at = NOW() WHERE id = " . $sheet['id'];
            $mysqli->query($updateSql);
            echo "✅ Sync completed successfully for {$sheet['client_name']}\n";
            $successCount++;
        } else {
            echo "❌ Sync completed with errors for {$sheet['client_name']}\n";
        }
        
        // Delay before next sheet (except for the last one)
        if ($index < $totalSheets - 1) {
            $delay = $timing['delay_between_sheets'];
            echo "Waiting $delay seconds before next sheet...\n";
            sleep($delay);
        }
    }
    
    $totalTime = time() - $startTime;
    echo "\n🎉 Dynamic sync completed!\n";
    echo "Successfully synced: $successCount/$totalSheets sheets\n";
    echo "Total time: ${totalTime}s\n";
    echo "Next sync in 5 minutes\n";
    
    return $successCount === $totalSheets;
}

// Function to sync a specific client immediately
function syncSpecificClient($mysqli, $service, $clientName) {
    global $VISITORS_LIMIT, $EVENTS_LIMIT;
    
    // Get the specific client's sheet info
    $sql = "SELECT * FROM pixel.pixel_sheets 
            WHERE client_name = ? AND sheet_id IS NOT NULL";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $clientName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo "❌ No sheet found for client: $clientName\n";
        return false;
    }
    
    $sheet = $result->fetch_assoc();
    echo "Found sheet for $clientName: {$sheet['sheet_id']}\n";
    
    // Select client database
    $clientDb = $sheet['client_name'];
    if (!$mysqli->select_db($clientDb)) {
        echo "Error: Could not select database $clientDb\n";
        return false;
    }
    
    // Sync both tabs together (Visitors + Events)
    echo "Updating both tabs (Visitors + Events) for $clientName...\n";
    $visitorsSuccess = syncVisitorsToSheet($mysqli, $sheet['client_name'], $sheet['sheet_id'], $service);
    $eventsSuccess = syncEventsToSheet($mysqli, $sheet['client_name'], $sheet['sheet_id'], $service, $sheet['last_sync_at']);
    
    // Update last sync time if successful
    if ($visitorsSuccess && $eventsSuccess) {
        $mysqli->select_db('pixel');
        $updateSql = "UPDATE pixel_sheets SET last_sync_at = NOW() WHERE id = " . $sheet['id'];
        $mysqli->query($updateSql);
        echo "✅ Immediate sync completed successfully for $clientName\n";
        return true;
    } else {
        echo "❌ Immediate sync completed with errors for $clientName\n";
        return false;
    }
}

// Sync visitors data to sheet
function syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service) {
    global $VISITORS_LIMIT;
    echo "Syncing visitors for $clientName (limit: $VISITORS_LIMIT)...\n";
    
    // Get visitor data - most recent first, with activity priority
    $sql = "SELECT 
        uuid, first_name, last_name, company_name, job_title, 
        personal_emails, mobile_phone, personal_city, personal_state, 
        first_seen_at, last_seen_at, event_count
    FROM superpixel_visitors 
    ORDER BY last_seen_at DESC, event_count DESC 
    LIMIT $VISITORS_LIMIT";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying visitors: " . $mysqli->error . "\n";
        return false;
    }
    
    $values = [];
    while ($row = $result->fetch_assoc()) {
        $values[] = [
            $row['uuid'] ?? '',
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['company_name'] ?? '',
            $row['job_title'] ?? '',
            $row['personal_emails'] ?? '',
            $row['mobile_phone'] ?? '',
            $row['personal_city'] ?? '',
            $row['personal_state'] ?? '',
            $row['first_seen_at'] ?? '',
            $row['last_seen_at'] ?? '',
            $row['event_count'] ?? '0'
        ];
    }
    
    if (empty($values)) {
        echo "No visitor data to sync\n";
        return true;
    }
    
    // Clear existing data (except header)
    $clearRange = 'Visitors!A2:L' . ($VISITORS_LIMIT + 1);
    try {
        $service->spreadsheets_values->clear($sheetId, $clearRange, new Google\Service\Sheets\ClearValuesRequest());
    } catch (Exception $e) {
        echo "Error clearing sheet: " . $e->getMessage() . "\n";
    }
    
    // Update sheet with new data
    $range = 'Visitors!A2';
    $body = new ValueRange([
        'values' => $values
    ]);
    
    try {
        $params = [
            'valueInputOption' => 'RAW'
        ];
        $service->spreadsheets_values->update($sheetId, $range, $body, $params);
        echo "Updated " . count($values) . " visitor records (max: $VISITORS_LIMIT)\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating visitors: " . $e->getMessage() . "\n";
        return false;
    }
}

// Sync recent events to sheet
function syncEventsToSheet($mysqli, $clientName, $sheetId, $service, $lastSyncTime = null) {
    global $EVENTS_LIMIT;
    echo "Syncing events for $clientName (limit: $EVENTS_LIMIT)...\n";
    
    // Build query with optional time filter
    $sql = "SELECT 
        event_timestamp, event_type, uuid, first_name, last_name, 
        company_name, url, referrer, ip_address
    FROM superpixel_resolution_log";
    
    if ($lastSyncTime) {
        $sql .= " WHERE created_at > '$lastSyncTime'";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT $EVENTS_LIMIT";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying events: " . $mysqli->error . "\n";
        return false;
    }
    
    $values = [];
    while ($row = $result->fetch_assoc()) {
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $values[] = [
            $row['event_timestamp'] ?? '',
            $row['event_type'] ?? '',
            $row['uuid'] ?? '',
            $fullName,
            $row['company_name'] ?? '',
            $row['url'] ?? '',
            $row['referrer'] ?? '',
            $row['ip_address'] ?? ''
        ];
    }
    
    if (empty($values)) {
        echo "No new event data to sync\n";
        return true;
    }
    
    // For events, we append new data instead of replacing
    if (!$lastSyncTime) {
        // First sync - clear and replace
        $clearRange = 'Events!A2:H' . ($EVENTS_LIMIT + 1);
        try {
            $service->spreadsheets_values->clear($sheetId, $clearRange, new Google\Service\Sheets\ClearValuesRequest());
        } catch (Exception $e) {
            echo "Error clearing sheet: " . $e->getMessage() . "\n";
        }
        $range = 'Events!A2';
        echo "Full refresh: Updated " . count($values) . " event records\n";
    } else {
        // Incremental sync - append
        $range = 'Events!A:H';
        echo "Incremental update: Added " . count($values) . " new event records\n";
    }
    
    $body = new ValueRange([
        'values' => $values
    ]);
    
    try {
        if ($lastSyncTime) {
            // Append for incremental updates
            $params = [
                'valueInputOption' => 'RAW',
                'insertDataOption' => 'INSERT_ROWS'
            ];
            $service->spreadsheets_values->append($sheetId, $range, $body, $params);
        } else {
            // Update for full refresh
            $params = [
                'valueInputOption' => 'RAW'
            ];
            $service->spreadsheets_values->update($sheetId, $range, $body, $params);
        }
        
        return true;
    } catch (Exception $e) {
        echo "Error updating events: " . $e->getMessage() . "\n";
        return false;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    // Check for specific client parameter
    $specificClient = null;
    foreach ($argv as $arg) {
        if (strpos($arg, '--client=') === 0) {
            $specificClient = substr($arg, 9); // Remove '--client='
            break;
        }
    }
    
    // Connect to MySQL
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
    if ($mysqli->connect_error) {
        die("MySQL connection failed: " . $mysqli->connect_error);
    }
    
    // Get Google Sheets service
    $client = getGoogleClient();
    $service = new Sheets($client);
    
    if ($specificClient) {
        // Sync specific client immediately
        echo "=== Immediate Sync for $specificClient ===\n";
        $success = syncSpecificClient($mysqli, $service, $specificClient);
    } else {
        // Run full dynamic sync
        $success = syncAllSheetsDynamically($mysqli, $service);
    }
    
    $mysqli->close();
    
    if ($success) {
        echo "\n✅ Sync completed successfully!\n";
    } else {
        echo "\n⚠️  Some sheets had sync issues\n";
    }
} else {
    die("This script must be run from command line\n");
}
?> 