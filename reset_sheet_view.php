<?php
// reset_sheet_view.php - Reset Google Sheets to default view and repopulate all data
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;

// Configuration
$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/opt/auto-pixel/credentials.json';

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

function resetVisitorsSheet($mysqli, $clientName, $sheetId, $service) {
    echo "🔄 Resetting Visitors sheet for $clientName...\n";
    
    // STEP 1: Clear the entire Visitors sheet
    echo "   1️⃣ Clearing existing data...\n";
    try {
        $clearRange = 'Visitors!A:Z'; // Clear all columns
        $service->spreadsheets_values->clear($sheetId, $clearRange, new ClearValuesRequest());
        echo "   ✅ Cleared Visitors sheet\n";
    } catch (Exception $e) {
        echo "   ❌ Error clearing Visitors sheet: " . $e->getMessage() . "\n";
        return false;
    }
    
    // STEP 2: Check if superpixel_visitors table exists
    echo "   📋 Checking table structure...\n";
    $result = $mysqli->query("SHOW TABLES LIKE 'superpixel_visitors'");
    if ($result->num_rows == 0) {
        echo "   ❌ superpixel_visitors table does not exist!\n";
        return false;
    }
    echo "   ✅ superpixel_visitors table exists\n";
    
    // STEP 3: Check visitor count
    echo "   📊 Checking visitor count...\n";
    $result = $mysqli->query("SELECT COUNT(*) as count FROM superpixel_visitors");
    $row = $result->fetch_assoc();
    $visitorCount = $row['count'];
    echo "   📈 Found $visitorCount visitors in database\n";
    
    if ($visitorCount == 0) {
        echo "   ⚠️  No visitors found! Need to run backfill script first.\n";
        echo "   📝 Run: php backfill_missing_visitors.php\n";
        return false;
    }
    
    // STEP 4: Get actual column names that exist
    echo "   🔍 Checking actual column structure...\n";
    $result = $mysqli->query("DESCRIBE superpixel_visitors");
    $actualColumns = [];
    while ($row = $result->fetch_assoc()) {
        $actualColumns[] = $row['Field'];
    }
    
    // Build SQL with actual available columns (include both short and long names)
    $availableColumns = [
        'uuid', 'first_name', 'last_name', 'company_name', 'job_title', 
        'personal_emails', 'mobile_phone', 'personal_address', 'personal_city', 
        'personal_state', 'personal_zip', 'first_seen_at', 'last_seen_at', 
        'event_count', 'url', 'last_visited_url', 'element', 'last_element', 
        'percentage', 'last_percentage', 'referrer', 'last_referrer', 
        'event_timestamp', 'event_type', 'last_event', 'npn', 'crd'
    ];
    
    $selectColumns = [];
    foreach ($availableColumns as $col) {
        if (in_array($col, $actualColumns)) {
            $selectColumns[] = $col;
        }
    }
    
    echo "   📋 Using columns: " . implode(", ", $selectColumns) . "\n";
    
    // STEP 5: Get ALL visitor data from database (not limited)
    echo "   2️⃣ Fetching ALL visitor data from database...\n";
    $sql = "SELECT " . implode(", ", $selectColumns) . " FROM superpixel_visitors ORDER BY last_seen_at DESC, first_seen_at DESC";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "   ❌ Error querying visitors: " . $mysqli->error . "\n";
        return false;
    }
    
    $visitors = [];
    while ($row = $result->fetch_assoc()) {
        // Map database columns to Google Sheets columns in correct order
        // Headers: UUID, First Name, Last Name, Company, Job Title, Emails, Phone,
        //          Personal Address, City, State, Zip, First Seen, Last Seen, 
        //          Event Count, Last Visited URL, Last Element, Last Percentage, 
        //          Last Referrer, Last Event Timestamp, Last Event Type, NPN, CRD
        
        $visitors[] = [
            $row['uuid'] ?? '',                           // UUID
            $row['first_name'] ?? '',                     // First Name  
            $row['last_name'] ?? '',                      // Last Name
            $row['company_name'] ?? '',                   // Company
            $row['job_title'] ?? '',                      // Job Title
            $row['personal_emails'] ?? '',                // Emails
            $row['mobile_phone'] ?? '',                   // Phone
            $row['personal_address'] ?? '',               // Personal Address
            $row['personal_city'] ?? '',                  // City
            $row['personal_state'] ?? '',                 // State
            $row['personal_zip'] ?? '',                   // Zip
            $row['first_seen_at'] ?? '',                  // First Seen
            $row['last_seen_at'] ?? '',                   // Last Seen
            $row['event_count'] ?? 0,                     // Event Count
            $row['url'] ?? $row['last_visited_url'] ?? '', // Last Visited URL (try both columns)
            $row['element'] ?? $row['last_element'] ?? '', // Last Element (try both columns)
            $row['percentage'] ?? $row['last_percentage'] ?? '', // Last Percentage (try both columns)
            $row['referrer'] ?? $row['last_referrer'] ?? '', // Last Referrer (try both columns)
            $row['event_timestamp'] ?? '',               // Last Event Timestamp
            $row['event_type'] ?? $row['last_event'] ?? '', // Last Event Type (try both columns)
            $row['npn'] ?? '',                            // NPN
            $row['crd'] ?? ''                             // CRD
        ];
    }
    
    echo "   📊 Found " . count($visitors) . " visitor records\n";
    
    if (count($visitors) == 0) {
        echo "   ⚠️  No visitor data retrieved! Check database connection.\n";
        return false;
    }
    
    // STEP 6: Define default headers in exact order
    $headers = [
        'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 'Phone',
        'Personal Address', 'City', 'State', 'Zip', 'First Seen', 'Last Seen', 
        'Event Count', 'Last Visited URL', 'Last Element', 'Last Percentage', 
        'Last Referrer', 'Last Event Timestamp', 'Last Event Type', 'NPN', 'CRD'
    ];
    
    // STEP 7: Combine headers and data
    $allData = array_merge([$headers], $visitors);
    
    // STEP 8: Write data to sheet with exact range
    echo "   3️⃣ Writing data to Visitors sheet...\n";
    $range = 'Visitors!A1:V' . count($allData);
    $body = new ValueRange(['values' => $allData]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
        echo "   ✅ Successfully reset " . count($visitors) . " visitor records\n";
        echo "   📋 Range: $range\n";
        return true;
    } catch (Exception $e) {
        echo "   ❌ Error updating Visitors sheet: " . $e->getMessage() . "\n";
        return false;
    }
}

function resetEventsSheet($mysqli, $clientName, $sheetId, $service) {
    echo "🔄 Resetting Events sheet for $clientName...\n";
    
    // STEP 1: Clear the entire Events sheet
    echo "   1️⃣ Clearing existing data...\n";
    try {
        $clearRange = 'Events!A:Z'; // Clear all columns
        $service->spreadsheets_values->clear($sheetId, $clearRange, new ClearValuesRequest());
        echo "   ✅ Cleared Events sheet\n";
    } catch (Exception $e) {
        echo "   ❌ Error clearing Events sheet: " . $e->getMessage() . "\n";
        return false;
    }
    
    // STEP 2: Define default headers in exact order
    $headers = [
        'Timestamp', 'Event Type', 'URL', 'Element', 'Referrer', 'IP Address',
        'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 
        'Phone', 'City', 'State', 'HemSha256', 'NPN', 'CRD'
    ];
    
    // STEP 3: Get ALL event data from database (not limited)
    echo "   2️⃣ Fetching ALL event data from database...\n";
    $sql = "SELECT 
            event_timestamp, event_type, url, element, referrer, ip_address, 
            uuid, first_name, last_name, company_name, job_title, personal_emails, 
            mobile_phone, personal_city, personal_state, hem_sha256, npn, crd
        FROM superpixel_resolution_log 
        WHERE event_timestamp IS NOT NULL 
        ORDER BY event_timestamp DESC";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "   ❌ Error querying events: " . $mysqli->error . "\n";
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
    
    echo "   📊 Found " . count($events) . " event records\n";
    
    // STEP 4: Combine headers and data
    $allData = array_merge([$headers], $events);
    
    // STEP 5: Write data to sheet with exact range
    echo "   3️⃣ Writing data to Events sheet...\n";
    $range = 'Events!A1:R' . count($allData);
    $body = new ValueRange(['values' => $allData]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
        echo "   ✅ Successfully reset " . count($events) . " event records\n";
        return true;
    } catch (Exception $e) {
        echo "   ❌ Error updating Events sheet: " . $e->getMessage() . "\n";
        return false;
    }
}

function getSheetIdForClient($clientName) {
    global $dbHost, $dbUser, $dbPass;
    
    // Connect to pixel database to get sheet_id
    $pixelDb = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
    if ($pixelDb->connect_error) {
        echo "❌ Failed to connect to pixel database: " . $pixelDb->connect_error . "\n";
        return null;
    }
    
    $stmt = $pixelDb->prepare("SELECT sheet_id FROM pixel_sheets WHERE client_name = ?");
    $stmt->bind_param("s", $clientName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $sheetId = $row['sheet_id'];
        $pixelDb->close();
        return $sheetId;
    }
    
    $pixelDb->close();
    return null;
}

function resetClientSheetView($clientName) {
    global $dbHost, $dbUser, $dbPass;
    
    echo "🚀 Starting Reset View for client: $clientName\n";
    echo "=" . str_repeat("=", 50) . "\n";
    
    // Step 1: Get Google Sheet ID
    echo "📋 Looking up Google Sheet for $clientName...\n";
    $sheetId = getSheetIdForClient($clientName);
    if (!$sheetId) {
        echo "❌ No Google Sheet found for client: $clientName\n";
        return false;
    }
    echo "✅ Found Sheet ID: $sheetId\n";
    
    // Step 2: Connect to client database
    echo "🗄️  Connecting to $clientName database...\n";
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $clientName);
    if ($mysqli->connect_error) {
        echo "❌ Failed to connect to $clientName database: " . $mysqli->connect_error . "\n";
        return false;
    }
    echo "✅ Connected to $clientName database\n";
    
    // Step 3: Initialize Google Sheets service
    echo "🔐 Initializing Google Sheets service...\n";
    try {
        $client = getGoogleClient();
        $service = new Sheets($client);
        echo "✅ Google Sheets service ready\n";
    } catch (Exception $e) {
        echo "❌ Failed to initialize Google Sheets: " . $e->getMessage() . "\n";
        $mysqli->close();
        return false;
    }
    
    // Step 4: Reset both sheets
    echo "\n🔄 Resetting sheet views...\n";
    
    $visitorsSuccess = resetVisitorsSheet($mysqli, $clientName, $sheetId, $service);
    echo "\n";
    $eventsSuccess = resetEventsSheet($mysqli, $clientName, $sheetId, $service);
    
    // Step 5: Cleanup
    $mysqli->close();
    
    // Step 6: Report results
    echo "\n" . str_repeat("=", 50) . "\n";
    if ($visitorsSuccess && $eventsSuccess) {
        echo "🎉 SUCCESS: Reset View completed for $clientName\n";
        echo "✅ Visitors sheet: Reset with default view\n";
        echo "✅ Events sheet: Reset with default view\n";
        echo "📊 All data has been repopulated in correct order\n";
        return true;
    } else {
        echo "⚠️  PARTIAL SUCCESS: Some issues occurred\n";
        echo ($visitorsSuccess ? "✅" : "❌") . " Visitors sheet\n";
        echo ($eventsSuccess ? "✅" : "❌") . " Events sheet\n";
        return false;
    }
}

// Main execution
if ($argc < 2) {
    echo "📚 Usage: php reset_sheet_view.php <client_name>\n";
    echo "📚 Example: php reset_sheet_view.php AcquireUp\n";
    echo "\n🔄 This script will:\n";
    echo "   1. Clear both Events and Visitors sheets completely\n";
    echo "   2. Restore default column headers in correct order\n";
    echo "   3. Repopulate ALL data from database\n";
    echo "   4. Fix any sorting/filtering issues caused by view-only users\n";
    exit(1);
}

$clientName = $argv[1];

// Validate client name
if (!preg_match('/^[a-zA-Z0-9_]+$/', $clientName)) {
    echo "❌ Error: Client name can only contain letters, numbers, and underscores\n";
    exit(1);
}

// Confirm before proceeding
echo "⚠️  WARNING: This will completely reset the Google Sheet for '$clientName'\n";
echo "📊 All data will be cleared and repopulated from the database\n";
echo "🔄 This will fix any column sorting/filtering issues\n";
echo "\nAre you sure you want to continue? (y/N): ";

$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) !== 'y') {
    echo "❌ Operation cancelled\n";
    exit(0);
}

echo "\n";
resetClientSheetView($clientName);

?> 