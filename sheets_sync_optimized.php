<?php
// Google Sheets Data Sync Script - OPTIMIZED FOR LARGE VOLUMES
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/opt/auto-pixel/credentials.json';

// Configuration for data limits
$VISITORS_LIMIT = 10000;    // Max visitors to sync
$EVENTS_LIMIT = 100000;     // Max recent events to sync (Google Sheets max practical limit)

function getGoogleClient()
{
    global $credentialsPath;
    $client = new Client();
    $client->setAuthConfig($credentialsPath);
    $client->setScopes(['https://www.googleapis.com/auth/spreadsheets']);
    return $client;
}

function syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service)
{
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
    $count = 0;
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
        $count++;
    }

    if (empty($values)) {
        echo "No visitor data to sync\n";
        return true;
    }

    try {
        // Clear existing data (except header) - expanded range for more visitors
        $clearRange = 'Visitors!A2:L' . ($VISITORS_LIMIT + 10);
        $service->spreadsheets_values->clear($sheetId, $clearRange, new Google\Service\Sheets\ClearValuesRequest());

        // Update sheet with new data
        $range = 'Visitors!A2';
        $body = new ValueRange(['values' => $values]);
        $params = ['valueInputOption' => 'RAW'];
        $service->spreadsheets_values->update($sheetId, $range, $body, $params);

        echo "Updated $count visitor records (max: $VISITORS_LIMIT)\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating visitors: " . $e->getMessage() . "\n";
        return false;
    }
}

function syncEventsToSheet($mysqli, $clientName, $sheetId, $service, $lastSyncTime = null)
{
    global $EVENTS_LIMIT;
    echo "Syncing events for $clientName (limit: $EVENTS_LIMIT)...\n";

    // For events, we want recent activity with a rolling window approach
    $sql = "SELECT 
        event_timestamp, event_type, uuid, first_name, last_name, 
        company_name, url, referrer, ip_address, created_at
    FROM superpixel_resolution_log";

    if ($lastSyncTime) {
        // Incremental sync - get new events since last sync
        $sql .= " WHERE created_at > '$lastSyncTime'";
        $sql .= " ORDER BY created_at DESC LIMIT 1000"; // Smaller batch for incremental
    } else {
        // Full sync - get most recent events
        $sql .= " ORDER BY created_at DESC LIMIT $EVENTS_LIMIT";
    }

    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying events: " . $mysqli->error . "\n";
        return false;
    }

    $values = [];
    $count = 0;
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
        $count++;
    }

    if (empty($values)) {
        echo "No new event data to sync\n";
        return true;
    }

    try {
        if (!$lastSyncTime) {
            // Full refresh - clear and replace with most recent events
            $clearRange = 'Events Log!A2:H' . ($EVENTS_LIMIT + 10);
            $service->spreadsheets_values->clear($sheetId, $clearRange, new Google\Service\Sheets\ClearValuesRequest());
            $range = 'Events Log!A2';
            $params = ['valueInputOption' => 'RAW'];
            $body = new ValueRange(['values' => $values]);
            $service->spreadsheets_values->update($sheetId, $range, $body, $params);
            echo "Full refresh: Updated $count event records\n";
        } else {
            // Incremental update - append new events to top
            $range = 'Events Log!A2:H2';
            $params = ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'];
            $body = new ValueRange(['values' => $values]);
            $service->spreadsheets_values->append($sheetId, $range, $body, $params);
            echo "Incremental: Added $count new event records\n";

            // Trim old events if sheet gets too large
            trimOldEvents($sheetId, $service);
        }

        return true;
    } catch (Exception $e) {
        echo "Error updating events: " . $e->getMessage() . "\n";
        return false;
    }
}

function trimOldEvents($sheetId, $service)
{
    global $EVENTS_LIMIT;

    try {
        // Get current row count
        $response = $service->spreadsheets_values->get($sheetId, 'Events Log!A:A');
        $rowCount = count($response->getValues() ?? []);

        if ($rowCount > $EVENTS_LIMIT + 1) { // +1 for header
            $excessRows = $rowCount - $EVENTS_LIMIT - 1;
            $deleteRange = 'Events Log!' . ($EVENTS_LIMIT + 2) . ':' . $rowCount;

            echo "Trimming $excessRows old event rows (keeping most recent $EVENTS_LIMIT)\n";

            $service->spreadsheets_values->clear($sheetId, $deleteRange, new Google\Service\Sheets\ClearValuesRequest());
        }
    } catch (Exception $e) {
        echo "Warning: Could not trim old events: " . $e->getMessage() . "\n";
    }
}

function syncAllSheets()
{
    global $dbHost, $dbUser, $dbPass;

    $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
    if ($mysqli->connect_error) {
        die("MySQL connection failed: " . $mysqli->connect_error);
    }

    $client = getGoogleClient();
    $service = new Sheets($client);

    // Get all client sheets to sync (excluding PENDING ones)
    $sql = "SELECT * FROM pixel.pixel_sheets WHERE sheet_id IS NOT NULL AND sheet_id != 'PENDING'";
    $result = $mysqli->query($sql);

    if (!$result) {
        die("Error querying pixel_sheets: " . $mysqli->error);
    }

    $sheetCount = 0;
    while ($sheet = $result->fetch_assoc()) {
        $sheetCount++;
        echo "\n=== Syncing {$sheet['client_name']} (Sheet $sheetCount) ===\n";

        // Select client database
        $clientDb = $sheet['client_name'];
        if (!$mysqli->select_db($clientDb)) {
            echo "Error: Could not select database $clientDb\n";
            continue;
        }

        // Sync visitors (full refresh every time for data consistency)
        $visitorsSuccess = syncVisitorsToSheet($mysqli, $sheet['client_name'], $sheet['sheet_id'], $service);

        // Sync events (incremental when possible)
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

        // Add delay between sheets to avoid API rate limits
        if ($sheetCount > 1) {
            echo "Waiting 2 seconds before next sheet...\n";
            sleep(2);
        }
    }

    $mysqli->close();
    echo "\n🎉 All syncs completed - processed $sheetCount client sheets\n";
}

// Run sync with error handling
if (php_sapi_name() === 'cli') {
    try {
        syncAllSheets();
    } catch (Exception $e) {
        echo "💥 Fatal error during sync: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    die("This script must be run from command line\n");
}
?>