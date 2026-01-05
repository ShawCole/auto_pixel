<?php
// smart_sync_2.php - Hybrid sync script with continuous monitoring and immediate new sheet detection
// UPDATED: Now tracks and updates 'last_event_at' in the pixel_sheets table.

require_once __DIR__ . '/vendor/autoload.php';

// Include standardized visitor functions
require_once __DIR__ . '/visitor_upsert_functions.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ClearValuesRequest;

// Configuration
$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
// Updated to use generic credentials.json
$credentialsPath = '/opt/auto-pixel/credentials.json';

// Sync configuration
$VISITORS_LIMIT = 10000;
$EVENTS_LIMIT = 100000;
$SYNC_INTERVAL = 300; // 5 minutes in seconds
$STAGGER_DELAY = 5; // 15 seconds between sheets (reduced from 30s)
$MONITOR_INTERVAL = 10; // Check for new sheets every 10 seconds
$MAX_SHEETS_PER_RUN = 50; // Process up to 50 sheets per run

// Visitor consistency configuration
$VISITOR_CONSISTENCY_CHECK = true; // Enable visitor consistency checks
$VISITOR_BACKFILL_LIMIT = 500; // Max visitors to backfill per client per run
$VISITOR_CHECK_FREQUENCY = 5; // Check every N runs (5 = every 10 minutes with 2-minute cron)

function getGoogleClient()
{
    global $credentialsPath;
    $client = new Client();
    $client->setAuthConfig($credentialsPath);
    $client->setScopes([
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive'
    ]);
    // Domain-Wide Delegation ENABLED
    $client->setSubject('scole@thynkdata.com');
    return $client;
}

function syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service)
{
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
            business_email,
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
            $row['business_email'] ?? '',
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
        // Update data first (overwrites existing rows in place - no visible gap)
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);

        // Clear only trailing rows to remove stale data (rows after current data)
        $nextRow = count($allData) + 1;
        $clearRange = "Visitors!A{$nextRow}:Z";
        $service->spreadsheets_values->clear($sheetId, $clearRange, new ClearValuesRequest());

        echo "Updated " . count($visitors) . " visitor records (max: $VISITORS_LIMIT)\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating visitors: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Returns an array: ['success' => bool, 'latest_event' => string|null]
 */
function syncEventsToSheet($mysqli, $clientName, $sheetId, $service)
{
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
        ORDER BY created_at DESC 
        LIMIT $EVENTS_LIMIT";

    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying events: " . $mysqli->error . "\n";
        return ['success' => false, 'latest_event' => null];
    }

    $events = [];
    $latestEventTimestamp = null;
    $maxTimestampEpoch = 0;

    while ($row = $result->fetch_assoc()) {
        // Track the maximum event_timestamp found in this entire batch
        if (!empty($row['event_timestamp'])) {
            $ts = strtotime($row['event_timestamp']);
            if ($ts > $maxTimestampEpoch) {
                $maxTimestampEpoch = $ts;
                $latestEventTimestamp = $row['event_timestamp'];
            }
        }

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
        return ['success' => true, 'latest_event' => null];
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
        // Update data first (overwrites existing rows in place - no visible gap)
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);

        // Clear only trailing rows to remove stale data (rows after current data)
        $nextRow = count($allData) + 1;
        $clearRange = "Events!A{$nextRow}:Z";
        $service->spreadsheets_values->clear($sheetId, $clearRange, new ClearValuesRequest());

        echo "Full refresh: Updated " . count($events) . " event records\n";
        return ['success' => true, 'latest_event' => $latestEventTimestamp];
    } catch (Exception $e) {
        echo "Error updating events: " . $e->getMessage() . "\n";
        return ['success' => false, 'latest_event' => null];
    }
}

function syncSingleSheet($clientName, $sheetId, $isNewSheet = false)
{
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
        $currentMinute = (int) date('i');
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
    $eventsResult = syncEventsToSheet($mysqli, $clientName, $sheetId, $service);

    // Normalize result (handle if it returns array or bool for backward compatibility if mixed)
    $eventsSuccess = is_array($eventsResult) ? $eventsResult['success'] : $eventsResult;
    $latestEvent = is_array($eventsResult) ? $eventsResult['latest_event'] : null;

    $mysqli->close();

    if ($visitorsSuccess && $eventsSuccess) {
        echo "✅ Sync completed successfully for $clientName\n";

        // Update last_sync_at timestamp AND last_event_at
        $pixelMysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
        if ($pixelMysqli->connect_error) {
            echo "Warning: Could not update sync timestamp - connection failed: " . $pixelMysqli->connect_error . "\n";
        } else {
            if ($latestEvent) {
                // Update both sync time and event time
                // Convert ISO8601 to MySQL format
                $formattedEventTime = date('Y-m-d H:i:s', strtotime($latestEvent));

                $updateSql = "UPDATE pixel_sheets SET last_sync_at = NOW(), last_event_at = ? WHERE client_name = ?";
                $stmt = $pixelMysqli->prepare($updateSql);
                $stmt->bind_param('ss', $formattedEventTime, $clientName);
                echo "   -> Attempting update last_event_at to $formattedEventTime for $clientName\n";
            } else {
                // Update only sync time if no events found
                $updateSql = "UPDATE pixel_sheets SET last_sync_at = NOW() WHERE client_name = ?";
                $stmt = $pixelMysqli->prepare($updateSql);
                $stmt->bind_param('s', $clientName);
                echo "   -> No latest event found. Updating only last_sync_at for $clientName\n";
            }

            if ($stmt->execute()) {
                echo "   -> Database updated. Rows affected: " . $stmt->affected_rows . "\n";
            } else {
                echo "   -> Database update FAILED: " . $stmt->error . "\n";
            }
            $stmt->close();
            $pixelMysqli->close();
        }

        /*
        |--------------------------------------------------------------------------
        | APPLY SHEET PROTECTION (Idempotent)
        |--------------------------------------------------------------------------
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

function getSheetsToSync()
{
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

function checkForNewSheets()
{
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

// Help/usage support
if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    echo "Usage: php smart_sync.php [--client=CLIENT_NAME | CLIENT_NAME]\n";
    echo "Examples:\n";
    echo "  php smart_sync.php --client=Retirement_Results_ACTIVE_2\n";
    echo "  php smart_sync.php Retirement_Results_ACTIVE_2\n";
    exit(0);
}

// Support --client=NAME and --only=NAME
foreach ($argv as $arg) {
    if (strpos($arg, '--client=') === 0) {
        $specificClient = substr($arg, 9); // Remove '--client='
        break;
    }
    if (strpos($arg, '--only=') === 0) {
        $specificClient = substr($arg, 7); // Remove '--only='
        break;
    }
}

// Support positional first argument (client name) when not an option
if ($specificClient === null && isset($argv[1]) && strpos($argv[1], '-') !== 0) {
    $specificClient = $argv[1];
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
        if ($success)
            $successCount++;

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