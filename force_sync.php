<?php
// force_sync.php - Force sync all sheets rapidly without delays
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;

// Configuration
$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/opt/auto-pixel/credentials.json';

// Force sync configuration
$VISITORS_LIMIT = 10000;
$EVENTS_LIMIT = 100000;
$FORCE_DELAY = 1; // Only 1 second between sheets for rapid sync

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

function syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service) {
    global $VISITORS_LIMIT;
    echo "  → Syncing visitors (limit: $VISITORS_LIMIT)...\n";
    
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
        echo "    ❌ Error querying visitors: " . $mysqli->error . "\n";
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
            $row['mobile_phone'] ?? '',
            $row['personal_address'] ?? '',
            $row['personal_city'] ?? '',
            $row['personal_state'] ?? '',
            $row['personal_zip'] ?? '',
            $row['first_seen_at'] ?? '',
            $row['last_seen_at'] ?? '',
            $row['event_count'] ?? '',
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
    
    // Prepare data with headers
    $headers = [
        'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 'Phone',
        'Personal Address', 'City', 'State', 'Zip', 'First Seen', 'Last Seen', 'Event Count',
        'Last Visited URL', 'Last Element', 'Last Percentage', 'Last Referrer',
        'Last Timestamp', 'Last Event Type', 'NPN', 'CRD'
    ];
    
    $allData = array_merge([$headers], $visitors);
    $range = 'Visitors!A1:V' . count($allData);
    
    $valueRange = new ValueRange([
        'values' => $allData
    ]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $valueRange, [
            'valueInputOption' => 'RAW'
        ]);
        echo "    ✅ Updated " . count($visitors) . " visitor records\n";
        return true;
    } catch (Exception $e) {
        echo "    ❌ Error updating visitors: " . $e->getMessage() . "\n";
        return false;
    }
}

function syncEventsToSheet($mysqli, $clientName, $sheetId, $service) {
    global $EVENTS_LIMIT;
    echo "  → Syncing events (limit: $EVENTS_LIMIT)...\n";
    
    // Get recent events with new columns
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
        echo "    ❌ Error querying events: " . $mysqli->error . "\n";
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
            $row['mobile_phone'] ?? '',
            $row['personal_city'] ?? '',
            $row['personal_state'] ?? '',
            $row['hem_sha256'] ?? '',
            $row['npn'] ?? '',
            $row['crd'] ?? ''
        ];
    }
    
    // Prepare data with headers
    $headers = [
        'Timestamp', 'Event Type', 'URL', 'Element', 'Referrer', 'IP Address',
        'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails',
        'Phone', 'City', 'State', 'HemSha256', 'NPN', 'CRD'
    ];
    
    $allData = array_merge([$headers], $events);
    $range = 'Events!A1:R' . count($allData);
    
    $valueRange = new ValueRange([
        'values' => $allData
    ]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $valueRange, [
            'valueInputOption' => 'RAW'
        ]);
        echo "    ✅ Updated " . count($events) . " event records\n";
        return true;
    } catch (Exception $e) {
        echo "    ❌ Error updating events: " . $e->getMessage() . "\n";
        return false;
    }
}

function forceSyncSingleSheet($clientName, $sheetId) {
    global $dbHost, $dbUser, $dbPass;
    
    echo "🔥 FORCE SYNCING: $clientName\n";
    $startTime = microtime(true);
    
    // Connect to client database
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $clientName);
    if ($mysqli->connect_error) {
        echo "  ❌ Error connecting to database $clientName: " . $mysqli->connect_error . "\n";
        return false;
    }
    
    try {
        $client = getGoogleClient();
        $service = new Sheets($client);
        
        // Sync both visitors and events
        $visitorsSuccess = syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service);
        $eventsSuccess = syncEventsToSheet($mysqli, $clientName, $sheetId, $service);
        
        $success = $visitorsSuccess && $eventsSuccess;
        
        if ($success) {
            // Update last sync time in pixel database
            $pixelMysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
            if (!$pixelMysqli->connect_error) {
                $updateSql = "UPDATE pixel_sheets SET last_sync_at = NOW() WHERE client_name = ?";
                $stmt = $pixelMysqli->prepare($updateSql);
                $stmt->bind_param('s', $clientName);
                $stmt->execute();
                $stmt->close();
                $pixelMysqli->close();
            }
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            echo "  ✅ FORCE SYNC COMPLETED in {$duration}s\n";
        } else {
            echo "  ❌ FORCE SYNC FAILED\n";
        }
        
        return $success;
    } catch (Exception $e) {
        echo "  ❌ Error during force sync: " . $e->getMessage() . "\n";
        return false;
    } finally {
        $mysqli->close();
    }
}

function getAllSheets() {
    global $dbHost, $dbUser, $dbPass;
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
    if ($mysqli->connect_error) {
        echo "❌ Error connecting to pixel database: " . $mysqli->connect_error . "\n";
        return [];
    }
    
    $sql = "SELECT client_name, sheet_id 
            FROM pixel_sheets 
            WHERE sheet_id IS NOT NULL 
            ORDER BY created_at ASC";
    
    $result = $mysqli->query($sql);
    $sheets = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $sheets[] = $row;
        }
    }
    
    $mysqli->close();
    return $sheets;
}

// Check for specific client argument
$targetClient = null;
if ($argc > 1) {
    $targetClient = $argv[1];
    echo "🎯 FORCE SYNC SINGLE CLIENT: $targetClient\n";
} else {
    echo "🚀 FORCE SYNC ALL SHEETS STARTED 🚀\n";
}

echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: RAPID SYNC (minimal delays)\n";
echo "=====================================\n\n";

$allSheets = getAllSheets();
if (empty($allSheets)) {
    echo "❌ No sheets found to sync\n";
    exit(1);
}

// Filter to specific client if specified
if ($targetClient) {
    $filteredSheets = array_filter($allSheets, function($sheet) use ($targetClient) {
        return $sheet['client_name'] === $targetClient;
    });
    
    if (empty($filteredSheets)) {
        echo "❌ No sheet found for client: $targetClient\n";
        echo "Available clients: " . implode(', ', array_column($allSheets, 'client_name')) . "\n";
        exit(1);
    }
    
    $allSheets = array_values($filteredSheets); // Re-index array
}

if ($targetClient) {
    echo "📊 Found " . count($allSheets) . " sheet(s) for client '$targetClient'\n\n";
} else {
    echo "📊 Found " . count($allSheets) . " sheets to force sync\n";
    echo "💡 Tip: Use 'php force_sync.php [CLIENT_NAME]' to sync only one client\n\n";
}

$successCount = 0;
$totalSheets = count($allSheets);
$overallStartTime = microtime(true);

foreach ($allSheets as $index => $sheet) {
    $sheetNumber = $index + 1;
    echo "[$sheetNumber/$totalSheets] ";
    
    $success = forceSyncSingleSheet($sheet['client_name'], $sheet['sheet_id']);
    if ($success) {
        $successCount++;
    }
    
    // Minimal delay between sheets (except for the last one)
    if ($index < $totalSheets - 1) {
        echo "  ⏱️  Waiting {$FORCE_DELAY}s before next sheet...\n\n";
        sleep($FORCE_DELAY);
    }
}

$overallEndTime = microtime(true);
$totalDuration = round($overallEndTime - $overallStartTime, 2);

echo "\n=====================================\n";
if ($targetClient) {
    echo "🎉 FORCE SYNC COMPLETED FOR '$targetClient'! 🎉\n";
} else {
    echo "🎉 FORCE SYNC COMPLETED! 🎉\n";
}
echo "📊 Results: $successCount/$totalSheets sheets synced successfully\n";
echo "⏱️  Total time: {$totalDuration}s\n";
echo "🚀 Average: " . round($totalDuration / $totalSheets, 2) . "s per sheet\n";

if ($successCount === $totalSheets) {
    if ($targetClient) {
        echo "✅ CLIENT '$targetClient' SYNCED SUCCESSFULLY!\n";
    } else {
        echo "✅ ALL SHEETS SYNCED SUCCESSFULLY!\n";
    }
    exit(0);
} else {
    $failedCount = $totalSheets - $successCount;
    echo "⚠️  $failedCount sheet(s) failed to sync\n";
    exit(1);
}
?> 