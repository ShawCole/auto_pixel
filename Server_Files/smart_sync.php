<?php
// smart_sync.php - Hybrid sync script with continuous monitoring and immediate new sheet detection
require_once __DIR__ . '/vendor/autoload.php';

// Include standardized visitor functions
require_once __DIR__ . '/visitor_upsert_functions.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;

// Configuration
$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

// Sync configuration
$VISITORS_LIMIT = 10000;
$EVENTS_LIMIT = 100000;
$SYNC_INTERVAL = 300; // 5 minutes in seconds
$STAGGER_DELAY = 15; // 15 seconds between sheets (reduced from 30s)
$MONITOR_INTERVAL = 10; // Check for new sheets every 10 seconds
$MAX_SHEETS_PER_RUN = 4; // Process max 4 sheets per run to stay within 5 minutes

// Visitor consistency configuration
$VISITOR_CONSISTENCY_CHECK = true; // Enable visitor consistency checks
$VISITOR_BACKFILL_LIMIT = 500; // Max visitors to backfill per client per run
$VISITOR_CHECK_FREQUENCY = 5; // Check every N runs (5 = every 10 minutes with 2-minute cron)

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

function syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service) {
    global $VISITORS_LIMIT;
    echo "Syncing visitors for $clientName (limit: $VISITORS_LIMIT)...\n";
    
    // Get visitor data with all new columns
    $sql = "SELECT 
            uuid, 
            first_name, 
            last_name, 
            company_name, 
            job_title, 
            personal_emails, 
            mobile_phone,
            personal_address,
            personal_city, 
            personal_state,
            personal_zip,
            first_seen_at, 
            last_seen_at, 
            event_count,
            url,
            element,
            percentage,
            referrer,
            event_timestamp,
            event_type,
            npn,
            crd
        FROM superpixel_visitors 
        WHERE uuid IS NOT NULL 
        ORDER BY last_seen_at DESC, event_count DESC 
        LIMIT $VISITORS_LIMIT";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying visitors: " . $mysqli->error . "\n";
        return false;
    }
    
    $visitors = [];
    while ($row = $result->fetch_assoc()) {
        $visitors[] = [
            $row['uuid'] ?? '',
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['company_name'] ?? '',
            $row['job_title'] ?? '',
            $row['personal_emails'] ?? '',
            '',
            $row['mobile_phone'] ?? '',
            $row['personal_address'] ?? '',
            $row['personal_city'] ?? '',
            $row['personal_state'] ?? '',
            $row['personal_zip'] ?? '',
            $row['first_seen_at'] ?? '',
            $row['last_seen_at'] ?? '',
            $row['event_count'] ?? 0,
            $row['url'] ?? '',
            $row['element'] ?? '',
            $row['percentage'] ?? '',
            $row['referrer'] ?? '',
            $row['event_timestamp'] ?? '',
            $row['event_type'] ?? '',
            $row['npn'] ?? '',
            $row['crd'] ?? ''
        ];
    }
    
    if (empty($visitors)) {
        echo "No visitor data to sync\n";
        return true;
    }
    
    // Updated headers with all new columns (including Business Emails)
    $headers = [
        'UUID', 
        'First Name', 
        'Last Name', 
        'Company', 
        'Job Title', 
        'Emails', 
        'Business Emails',
        'Phone',
        'Personal Address',
        'City', 
        'State',
        'Zip',
        'First Seen', 
        'Last Seen', 
        'Event Count',
        'Last Visited URL',
        'Last Element',
        'Last Percentage',
        'Last Referrer',
        'Last Event Timestamp',
        'Last Event Type',
        'NPN',
        'CRD'
    ];
    
    $allData = array_merge([$headers], $visitors);
    
    // Update range to include all columns (A to W). Note: 'Business Emails' after 'Emails'.
    $range = 'Visitors!A1:W' . count($allData);
    $body = new ValueRange(['values' => $allData]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
        echo "Updated " . count($visitors) . " visitor records (max: $VISITORS_LIMIT)\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating visitors: " . $e->getMessage() . "\n";
        return false;
    }
}

function syncEventsToSheet($mysqli, $clientName, $sheetId, $service) {
    global $EVENTS_LIMIT;
    echo "Syncing events for $clientName (limit: $EVENTS_LIMIT)...\n";
    
    // Get recent events with new columns (including Business Emails)
    $sql = "SELECT 
            event_timestamp, 
            event_type,
            url,
            element,
            referrer,
            ip_address, 
            uuid, 
            first_name, 
            last_name, 
            company_name, 
            job_title, 
            personal_emails, 
            business_email,
            mobile_phone, 
            personal_city, 
            personal_state,
            hem_sha256,
            npn,
            crd
        FROM superpixel_resolution_log 
        WHERE event_timestamp IS NOT NULL 
        ORDER BY event_timestamp DESC 
        LIMIT $EVENTS_LIMIT";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying events: " . $mysqli->error . "\n";
        return false;
    }
    
        $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            $row['event_timestamp'] ?? '',
            $row['event_type'] ?? '',
            $row['url'] ?? '',
            $row['element'] ?? '',
            $row['referrer'] ?? '',
            $row['ip_address'] ?? '',
            $row['uuid'] ?? '',
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['company_name'] ?? '',
            $row['job_title'] ?? '',
                $row['personal_emails'] ?? '',
                $row['business_email'] ?? '',
            $row['mobile_phone'] ?? '',
            $row['personal_city'] ?? '',
            $row['personal_state'] ?? '',
            $row['hem_sha256'] ?? '',
            $row['npn'] ?? '',
            $row['crd'] ?? ''
        ];
    }
    
    if (empty($events)) {
        echo "No new event data to sync\n";
        return true;
    }
    
    // Updated headers with new columns (including Business Emails)
            $headers = [
        'Timestamp', 
        'Event Type', 
        'URL', 
        'Element', 
        'Referrer', 
        'IP Address',
        'UUID', 
        'First Name', 
        'Last Name', 
        'Company', 
        'Job Title', 
        'Emails', 
        'Business Emails',
        'Phone',
        'City', 
        'State',
        'HemSha256',
        'NPN',
        'CRD'
    ];
    
    $allData = array_merge([$headers], $events);
    
    // Update range to include all columns (A to S)
    $range = 'Events!A1:S' . count($allData);
    $body = new ValueRange(['values' => $allData]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
        echo "Full refresh: Updated " . count($events) . " event records\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating events: " . $e->getMessage() . "\n";
        return false;
    }
}

function syncSingleSheet($clientName, $sheetId, $isNewSheet = false) {
    global $dbHost, $dbUser, $dbPass;
    
    echo "=== Syncing $clientName" . ($isNewSheet ? " (NEW SHEET)" : "") . " ===\n";
    echo "Started at: " . date('Y-m-d H:i:s') . "\n";
    
    // Connect to client database
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $clientName);
    if ($mysqli->connect_error) {
        echo "Error: Could not select database $clientName\n";
        return false;
    }
    
    // Get Google client
    $client = getGoogleClient();
    $service = new Sheets($client);
    
    // Step 1: Ensure visitor consistency before syncing (optional, configurable frequency)
    global $VISITOR_CONSISTENCY_CHECK, $VISITOR_BACKFILL_LIMIT, $VISITOR_CHECK_FREQUENCY;
    
    if ($VISITOR_CONSISTENCY_CHECK) {
        // Check if we should run consistency check this time (based on frequency)
        $currentMinute = (int)date('i');
        $shouldCheck = ($currentMinute % ($VISITOR_CHECK_FREQUENCY * 2)) == 0; // Every N*2 minutes
        
        if ($shouldCheck || $isNewSheet) {
            echo "🔍 Checking visitor consistency for $clientName...\n";
            $backfillResult = backfillMissingVisitors($mysqli, $VISITOR_BACKFILL_LIMIT, "smart_sync_$clientName");
            if ($backfillResult['backfilled_count'] > 0) {
                echo "✅ Backfilled {$backfillResult['backfilled_count']} missing visitors\n";
            } else {
                echo "✅ Visitor consistency OK (no missing visitors)\n";
            }
        }
    }
    
    // Step 2: Sync both tabs
    echo "Updating both tabs (Visitors + Events) for $clientName...\n";
    
    $visitorsSuccess = syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service);
    $eventsSuccess = syncEventsToSheet($mysqli, $clientName, $sheetId, $service);
    
    $mysqli->close();
    
    if ($visitorsSuccess && $eventsSuccess) {
        echo "✅ Sync completed successfully for $clientName\n";
        
        // Update last_sync_at timestamp
        $pixelMysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
        if ($pixelMysqli->connect_error) {
            echo "Warning: Could not update sync timestamp\n";
        } else {
            $updateSql = "UPDATE pixel_sheets SET last_sync_at = NOW() WHERE client_name = ?";
            $stmt = $pixelMysqli->prepare($updateSql);
            $stmt->bind_param('s', $clientName);
            $stmt->execute();
            $stmt->close();
            $pixelMysqli->close();
        }
        
        /*
        |--------------------------------------------------------------------------
        | APPLY SHEET PROTECTION (Idempotent)
        |--------------------------------------------------------------------------
        | Locks down the 'Visitors' and 'Events' tabs on every successful sync.
        | Removes existing protections on those tabs and reapplies the correct one.
        */
        try {
            $tabNamesToProtect = ['Visitors', 'Events'];

            // 1. Get the spreadsheet to find tab GIDs and existing protections
            $spreadsheet = $service->spreadsheets->get($sheetId);
            $sheets = $spreadsheet->getSheets();

            $sheetIdMap = [];
            foreach ($sheets as $sheetObj) {
                $title = $sheetObj->getProperties()->getTitle();
                if (in_array($title, $tabNamesToProtect)) {
                    $sheetIdMap[$title] = $sheetObj->getProperties()->getSheetId();
                }
            }

            $requests = [];

            // 2. Find and add DELETE requests for old protections on these tabs
            foreach ($sheets as $sheetObj) {
                $protectedRanges = $sheetObj->getProtectedRanges();
                if (!$protectedRanges) {
                    continue;
                }
                foreach ($protectedRanges as $protection) {
                    $range = $protection->getRange();
                    if ($range && in_array($range->getSheetId(), $sheetIdMap)) {
                        $requests[] = new Google\Service\Sheets\Request([
                            'deleteProtectedRange' => [
                                'protectedRangeId' => $protection->getProtectedRangeId()
                            ]
                        ]);
                    }
                }
            }

            // 3. Define the protection settings (Only owners can edit)
            $editors = new Google\Service\Sheets\Editors([
                'users' => [],
                'groups' => [],
                'domainUsersCanEdit' => false
            ]);

            // 4. Create and add ADD requests for the new protections
            foreach ($tabNamesToProtect as $tabName) {
                if (isset($sheetIdMap[$tabName])) {
                    $protectionRequest = new Google\Service\Sheets\ProtectedRange([
                        'range' => [
                            'sheetId' => $sheetIdMap[$tabName]
                        ],
                        'description' => 'Live data. Please duplicate this sheet (File > Make a copy) to sort or filter.',
                        'warningOnly' => false,
                        'editors' => $editors
                    ]);

                    $requests[] = new Google\Service\Sheets\Request([
                        'addProtectedRange' => ['protectedRange' => $protectionRequest]
                    ]);
                }
            }

            // 5. Execute the batch update (deletes and adds all at once)
            if (!empty($requests)) {
                $batchUpdateRequest = new Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                    'requests' => $requests
                ]);

                $service->spreadsheets->batchUpdate($sheetId, $batchUpdateRequest);
            }
        } catch (Exception $e) {
            error_log('Google API error applying protection: ' . $e->getMessage());
        }

        return true;
    } else {
        echo "❌ Sync failed for $clientName\n";
        return false;
    }
}

function getSheetsToSync() {
    global $dbHost, $dbUser, $dbPass, $MAX_SHEETS_PER_RUN;
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
    if ($mysqli->connect_error) {
        echo "Error connecting to pixel database: " . $mysqli->connect_error . "\n";
        return [];
    }
    
    // Get sheets prioritized by: new sheets first, then oldest sync time
    $sql = "SELECT client_name, sheet_id, last_sync_at FROM pixel_sheets 
            WHERE sheet_id IS NOT NULL 
            ORDER BY last_sync_at IS NULL DESC, COALESCE(last_sync_at, '1970-01-01') ASC 
            LIMIT $MAX_SHEETS_PER_RUN";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying pixel_sheets: " . $mysqli->error . "\n";
        $mysqli->close();
        return [];
    }
    
    $sheets = [];
    while ($row = $result->fetch_assoc()) {
        $sheets[] = $row;
    }
    
    $mysqli->close();
    return $sheets;
}

function checkForNewSheets() {
    global $dbHost, $dbUser, $dbPass;
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
    if ($mysqli->connect_error) {
        return [];
    }
    
    // Find sheets that have never been synced (last_sync_at is NULL)
    $sql = "SELECT client_name, sheet_id FROM pixel_sheets 
            WHERE sheet_id IS NOT NULL AND last_sync_at IS NULL";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        $mysqli->close();
        return [];
    }
    
    $newSheets = [];
    while ($row = $result->fetch_assoc()) {
        $newSheets[] = $row;
    }
    
    $mysqli->close();
    return $newSheets;
}

// Check for command line arguments
$specificClient = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--client=') === 0) {
        $specificClient = substr($arg, 9); // Remove '--client='
        break;
    }
}

// Main execution
echo "=== Smart Sync Started ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "Monitor interval: ${MONITOR_INTERVAL}s\n";
echo "Stagger delay: ${STAGGER_DELAY}s\n";
echo "Max sheets per run: $MAX_SHEETS_PER_RUN\n";

if ($specificClient) {
    echo "🎯 IMMEDIATE SYNC for specific client: $specificClient\n\n";
    
    // Get the sheet ID for the specific client
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
    if ($mysqli->connect_error) {
        echo "Error connecting to pixel database: " . $mysqli->connect_error . "\n";
        exit(1);
    }
    
    $sql = "SELECT sheet_id FROM pixel_sheets WHERE client_name = ? AND sheet_id IS NOT NULL";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $specificClient);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $sheetId = $row['sheet_id'];
        $stmt->close();
        $mysqli->close();
        
        $success = syncSingleSheet($specificClient, $sheetId, true);
        if ($success) {
            echo "✅ Immediate sync completed successfully for $specificClient\n";
        } else {
            echo "❌ Immediate sync failed for $specificClient\n";
            exit(1);
        }
    } else {
        echo "❌ No sheet found for client: $specificClient\n";
        $stmt->close();
        $mysqli->close();
        exit(1);
    }
} else {
    echo "\n";
    
    // First, check for any new sheets that need immediate sync
    $newSheets = checkForNewSheets();
    if (!empty($newSheets)) {
        echo "🚨 Found " . count($newSheets) . " new sheet(s) requiring immediate sync!\n";
        foreach ($newSheets as $sheet) {
            syncSingleSheet($sheet['client_name'], $sheet['sheet_id'], true);
            echo "Waiting ${STAGGER_DELAY} seconds before next sheet...\n";
            sleep($STAGGER_DELAY);
        }
        echo "✅ Immediate sync completed for new sheets\n\n";
    }

    // Then sync the regular queue
    $sheetsToSync = getSheetsToSync();
    if (empty($sheetsToSync)) {
        echo "No sheets to sync\n";
        exit(0);
    }

    echo "📋 Regular sync queue: " . count($sheetsToSync) . " sheet(s)\n";

    $successCount = 0;
    foreach ($sheetsToSync as $index => $sheet) {
        $isNew = !isset($sheet['last_sync_at']) || $sheet['last_sync_at'] === null;
        $success = syncSingleSheet($sheet['client_name'], $sheet['sheet_id'], $isNew);
        if ($success) $successCount++;
        
        // Don't wait after the last sheet
        if ($index < count($sheetsToSync) - 1) {
            echo "Waiting ${STAGGER_DELAY} seconds before next sheet...\n";
            sleep($STAGGER_DELAY);
        }
    }

    echo "\n🎉 Smart sync completed!\n";
    echo "Successfully synced: $successCount/" . count($sheetsToSync) . " sheets\n";
    echo "Next sync in 5 minutes\n";
    echo "Monitoring for new sheets every ${MONITOR_INTERVAL} seconds\n";
}
?> 