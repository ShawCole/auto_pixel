<?php
// Google Sheets Data Sync Script - OPTIMIZED FOR LARGE VOLUMES
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;

// Database configuration
$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

// Google Sheets configuration
<<<<<<< HEAD
$credentialsPath = '/opt/auto-pixel/credentials.json';
=======
$credentialsPath = __DIR__ . '/credentials.json';
>>>>>>> 11d8aeca212436261df6d65df181aeb95d17b8f4

// Configuration for data limits and staggering
$VISITORS_LIMIT = 10000;    // Max visitors to sync
$EVENTS_LIMIT = 100000;     // Max recent events to sync (Google Sheets max practical limit)
$STAGGER_DELAY = 30;        // Seconds between client syncs (30 seconds = 2 clients per minute)
$MAX_CLIENTS_PER_RUN = 10;  // Maximum clients to sync in one run (prevents rate limit issues)

// Initialize Google Client
function getGoogleClient()
{
    global $credentialsPath;

    $client = new Client();
    $client->setAuthConfig($credentialsPath);
    $client->setScopes([
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive'
    ]);
<<<<<<< HEAD
    // $client->setSubject('scole@thynkdata.com');
    
=======
    $client->setSubject('scole@thynkdata.com');

>>>>>>> 11d8aeca212436261df6d65df181aeb95d17b8f4
    return $client;
}

// Sync visitors data to sheet
function syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service)
{
    echo "Syncing visitors for $clientName...\n";

    // Get visitor data
    $sql = "SELECT 
        uuid,
        first_name,
        last_name,
        company_name,
        job_title,
        personal_emails,
        mobile_phone,
        personal_city,
        personal_state,
        first_seen_at,
        last_seen_at,
        event_count
    FROM superpixel_visitors
    ORDER BY last_seen_at DESC
    LIMIT 1000"; // Limit for performance

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
    $clearRange = 'Visitors!A2:L1001';
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
        echo "Updated " . count($values) . " visitor records\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating visitors: " . $e->getMessage() . "\n";
        return false;
    }
}

// Sync recent events to sheet
function syncEventsToSheet($mysqli, $clientName, $sheetId, $service, $lastSyncTime = null)
{
    echo "Syncing events for $clientName...\n";

    // Build query with optional time filter
    $sql = "SELECT 
        event_timestamp,
        event_type,
        uuid,
        first_name,
        last_name,
        company_name,
        url,
        referrer,
        ip_address
    FROM superpixel_resolution_log";

    if ($lastSyncTime) {
        $sql .= " WHERE created_at > '$lastSyncTime'";
    }

    $sql .= " ORDER BY created_at DESC LIMIT 500"; // Recent events only

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
        $clearRange = 'Events Log!A2:H501';
        try {
            $service->spreadsheets_values->clear($sheetId, $clearRange, new Google\Service\Sheets\ClearValuesRequest());
        } catch (Exception $e) {
            echo "Error clearing sheet: " . $e->getMessage() . "\n";
        }
        $range = 'Events Log!A2';
    } else {
        // Incremental sync - append
        $range = 'Events Log!A:H';
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

        echo "Updated " . count($values) . " event records\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating events: " . $e->getMessage() . "\n";
        return false;
    }
}

// Main sync function with staggering
function syncAllSheets()
{
    global $dbHost, $dbUser, $dbPass, $STAGGER_DELAY, $MAX_CLIENTS_PER_RUN;

    // Connect to MySQL
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
    if ($mysqli->connect_error) {
        die("MySQL connection failed: " . $mysqli->connect_error);
    }

    // Get Google Sheets service
    $client = getGoogleClient();
    $service = new Sheets($client);

    // Get all client sheets to sync, ordered by last sync time (oldest first)
    $sql = "SELECT * FROM pixel.pixel_sheets 
            WHERE sheet_id IS NOT NULL 
            ORDER BY last_sync_at IS NULL DESC, last_sync_at ASC 
            LIMIT $MAX_CLIENTS_PER_RUN";
    $result = $mysqli->query($sql);

    if (!$result) {
        die("Error querying pixel_sheets: " . $mysqli->error);
    }

    $clientCount = 0;
    $startTime = time();

    while ($sheet = $result->fetch_assoc()) {
        $clientCount++;
        echo "\n=== Syncing {$sheet['client_name']} (Sheet $clientCount) ===";
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
    $mysqli->close();

    echo "\n🎉 Sync run completed - processed $clientCount client sheets in ${totalTime}s\n";
    echo "Next sync run in 5 minutes\n";
}

// Run sync
if (php_sapi_name() === 'cli') {
    syncAllSheets();
} else {
    die("This script must be run from command line\n");
}
?>