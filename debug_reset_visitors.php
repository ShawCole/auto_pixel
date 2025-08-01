<?php
// debug_reset_visitors.php - Debug and fix visitors sheet population
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

// Configuration
$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

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

function getSheetIdForClient($clientName) {
    global $dbHost, $dbUser, $dbPass;
    
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

function debugAndFixVisitors($clientName) {
    global $dbHost, $dbUser, $dbPass;
    
    echo "🔍 Debugging visitors data for $clientName...\n";
    echo "=" . str_repeat("=", 50) . "\n";
    
    // Step 1: Connect to database
    echo "🗄️  Connecting to $clientName database...\n";
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $clientName);
    if ($mysqli->connect_error) {
        echo "❌ Failed to connect: " . $mysqli->connect_error . "\n";
        return false;
    }
    echo "✅ Connected to database\n";
    
    // Step 2: Check if superpixel_visitors table exists
    echo "\n🔍 Checking table structure...\n";
    $result = $mysqli->query("SHOW TABLES LIKE 'superpixel_visitors'");
    if ($result->num_rows == 0) {
        echo "❌ superpixel_visitors table does not exist!\n";
        return false;
    }
    echo "✅ superpixel_visitors table exists\n";
    
    // Step 3: Check visitor count
    echo "\n📊 Checking visitor count...\n";
    $result = $mysqli->query("SELECT COUNT(*) as count FROM superpixel_visitors");
    $row = $result->fetch_assoc();
    $visitorCount = $row['count'];
    echo "📈 Found $visitorCount visitors in database\n";
    
    if ($visitorCount == 0) {
        echo "⚠️  No visitors found! Need to run backfill script first.\n";
        return false;
    }
    
    // Step 4: Check actual column names
    echo "\n🔍 Checking actual column structure...\n";
    $result = $mysqli->query("DESCRIBE superpixel_visitors");
    $actualColumns = [];
    while ($row = $result->fetch_assoc()) {
        $actualColumns[] = $row['Field'];
        echo "   - " . $row['Field'] . "\n";
    }
    
    // Step 5: Get sample visitor data with correct column names
    echo "\n📊 Getting visitor data (first 5 records)...\n";
    
    // Build SQL with actual available columns
    $availableColumns = [
        'uuid', 'first_name', 'last_name', 'company_name', 'job_title', 
        'personal_emails', 'mobile_phone', 'personal_address', 'personal_city', 
        'personal_state', 'personal_zip', 'created_at', 'last_seen_at', 
        'event_count', 'url', 'element', 'percentage', 'referrer', 
        'event_timestamp', 'event_type', 'npn', 'crd'
    ];
    
    $selectColumns = [];
    foreach ($availableColumns as $col) {
        if (in_array($col, $actualColumns)) {
            $selectColumns[] = $col;
        }
    }
    
    $sql = "SELECT " . implode(", ", $selectColumns) . " FROM superpixel_visitors ORDER BY last_seen_at DESC LIMIT 5";
    echo "🔍 SQL Query: $sql\n";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "❌ Query failed: " . $mysqli->error . "\n";
        return false;
    }
    
    echo "\n📋 Sample data:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   UUID: " . ($row['uuid'] ?? 'NULL') . ", ";
        echo "Name: " . ($row['first_name'] ?? '') . " " . ($row['last_name'] ?? '') . ", ";
        echo "Company: " . ($row['company_name'] ?? 'NULL') . "\n";
    }
    
    // Step 6: Get Google Sheet ID
    echo "\n📋 Getting Google Sheet ID...\n";
    $sheetId = getSheetIdForClient($clientName);
    if (!$sheetId) {
        echo "❌ No Google Sheet found for client: $clientName\n";
        return false;
    }
    echo "✅ Found Sheet ID: $sheetId\n";
    
    // Step 7: Repopulate visitors sheet
    echo "\n🔄 Repopulating visitors sheet...\n";
    
    // Get ALL visitor data
    $sql = "SELECT " . implode(", ", $selectColumns) . " FROM superpixel_visitors ORDER BY last_seen_at DESC";
    $result = $mysqli->query($sql);
    
    if (!$result) {
        echo "❌ Failed to get visitor data: " . $mysqli->error . "\n";
        return false;
    }
    
    // Build data array
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
            $row['created_at'] ?? '',
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
    
    echo "📊 Prepared " . count($visitors) . " visitor records\n";
    
    // Headers
    $headers = [
        'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 'Phone',
        'Personal Address', 'City', 'State', 'Zip', 'First Seen', 'Last Seen', 
        'Event Count', 'Last Visited URL', 'Last Element', 'Last Percentage', 
        'Last Referrer', 'Last Event Timestamp', 'Last Event Type', 'NPN', 'CRD'
    ];
    
    $allData = array_merge([$headers], $visitors);
    
    // Step 8: Update Google Sheet
    echo "\n📤 Updating Google Sheet...\n";
    try {
        $client = getGoogleClient();
        $service = new Sheets($client);
        
        $range = 'Visitors!A1:V' . count($allData);
        $body = new ValueRange(['values' => $allData]);
        
        $result = $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
        
        echo "✅ Successfully updated visitors sheet!\n";
        echo "📊 Updated " . count($visitors) . " visitor records\n";
        echo "📋 Range: $range\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ Google Sheets update failed: " . $e->getMessage() . "\n";
        return false;
    }
}

// Main execution
if ($argc < 2) {
    echo "📚 Usage: php debug_reset_visitors.php <client_name>\n";
    echo "📚 Example: php debug_reset_visitors.php AcquireUp\n";
    exit(1);
}

$clientName = $argv[1];
debugAndFixVisitors($clientName);

?> 