<?php
// Staggered Google Sheets Sync Script
// This script syncs clients in batches to avoid rate limits
// Run via cron every 5 minutes

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;

$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

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
    $client->setSubject('scole@thynkdata.com');
    return $client;
}

function syncClientBatch($mysqli, $service, $offset = 0, $limit = 3) {
    global $STAGGER_DELAY, $VISITORS_LIMIT, $EVENTS_LIMIT;
    
    // Get batch of clients ordered by last sync time
    $sql = "SELECT * FROM pixel.pixel_sheets 
            WHERE sheet_id IS NOT NULL 
            ORDER BY last_sync_at ASC NULLS FIRST 
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

// Include the sync functions from the main script
require_once __DIR__ . '/sheets_sync.php';

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