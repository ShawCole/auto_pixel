<?php
// Staggered Google Sheets Sync Script
// This script syncs clients in batches to avoid rate limits
// Run via cron every 5 minutes

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
$BATCH_SIZE = 3;           // Clients per batch (3 clients = 30 seconds each)
$STAGGER_DELAY = 30;       // Seconds between clients
$VISITORS_LIMIT = 10000;
$EVENTS_LIMIT = 100000;

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

function syncClientBatch($mysqli, $service, $offset = 0, $limit = 3) {
    global $STAGGER_DELAY, $VISITORS_LIMIT, $EVENTS_LIMIT;
    
    // Get batch of clients ordered by last sync time
    $sql = "SELECT * FROM pixel.pixel_sheets 
            WHERE sheet_id IS NOT NULL 
            ORDER BY COALESCE(last_sync_at, '1970-01-01') ASC 
            LIMIT $limit OFFSET $offset";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying pixel_sheets: " . $mysqli->error . "\n";
        return 0;
    }
    
    $clientCount = 0;
    $startTime = time();
    
    while ($sheet = $result->fetch_assoc()) {
        $clientCount++;
        echo "\n=== Syncing {$sheet['client_name']} (Batch $offset + $clientCount) ===";
        echo "\nStarted at: " . date('Y-m-d H:i:s') . "\n";
        
        // Select client database
        $clientDb = $sheet['client_name'];
        if (!$mysqli->select_db($clientDb)) {
            echo "Error: Could not select database $clientDb\n";
            continue;
        }
        
        // Sync visitors
        $visitorsSuccess = syncVisitorsToSheet($mysqli, $sheet['client_name'], $sheet['sheet_id'], $service);
        
        // Sync events
        $eventsSuccess = syncEventsToSheet($mysqli, $sheet['client_name'], $sheet['sheet_id'], $service, $sheet['last_sync_at']);
        
        // Update last sync time if successful
        if ($visitorsSuccess && $eventsSuccess) {
            $mysqli->select_db('pixel');
            $updateSql = "UPDATE pixel_sheets SET last_sync_at = NOW() WHERE id = " . $sheet['id'];
            $mysqli->query($updateSql);
            echo "✅ Sync completed successfully for {$sheet['client_name']}\n";
        } else {
            echo "❌ Sync completed with errors for {$sheet['client_name']}\n";
        }
        
        // Stagger delay between clients (except for the last one)
        if ($clientCount < $result->num_rows) {
            echo "Waiting $STAGGER_DELAY seconds before next client...\n";
            sleep($STAGGER_DELAY);
        }
    }
    
    $totalTime = time() - $startTime;
    echo "\n🎉 Batch completed - processed $clientCount clients in ${totalTime}s\n";
    
    return $clientCount;
}

// Sync visitors data to sheet (copied from sheets_sync.php)
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

// Sync recent events to sheet (copied from sheets_sync.php)
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
    // Connect to MySQL
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
    if ($mysqli->connect_error) {
        die("MySQL connection failed: " . $mysqli->connect_error);
    }
    
    // Get Google Sheets service
    $client = getGoogleClient();
    $service = new Sheets($client);
    
    // Get total number of clients
    $totalSql = "SELECT COUNT(*) as total FROM pixel.pixel_sheets WHERE sheet_id IS NOT NULL";
    $totalResult = $mysqli->query($totalSql);
    $totalClients = $totalResult->fetch_assoc()['total'];
    
    echo "=== Staggered Sync Started ===";
    echo "\nTotal clients: $totalClients";
    echo "\nBatch size: $BATCH_SIZE";
    echo "\nStagger delay: ${STAGGER_DELAY}s\n";
    
    // Calculate which batch to run based on current minute
    $currentMinute = (int)date('i');
    $batchNumber = floor($currentMinute / 5); // Every 5 minutes
    $offset = ($batchNumber * $BATCH_SIZE) % $totalClients;
    
    echo "Current minute: $currentMinute, Batch number: $batchNumber, Offset: $offset\n";
    
    // Sync this batch
    $syncedCount = syncClientBatch($mysqli, $service, $offset, $BATCH_SIZE);
    
    $mysqli->close();
    
    echo "\n=== Staggered Sync Completed ===";
    echo "\nSynced $syncedCount clients in this batch";
    echo "\nNext batch in 5 minutes\n";
} else {
    die("This script must be run from command line\n");
}
?> 