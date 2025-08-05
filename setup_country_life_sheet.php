<?php
/**
 * Setup Google Sheet for Country_Life client
 * Creates sheet, sets headers, updates pixel_sheets table, and ensures sync compatibility
 */

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;

// Database configuration
$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';

$mysqli = new mysqli($host, $user, $pass, 'pixel');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== SETTING UP GOOGLE SHEET FOR COUNTRY_LIFE ===\n\n";

// Client details
$client_name = 'Country_Life';
$client_website = 'https://country-life.com';

// Check if Country_Life exists in pixel_sheets
$check_query = "SELECT * FROM pixel_sheets WHERE client_name = 'Country_Life'";
$result = $mysqli->query($check_query);

if ($result->num_rows === 0) {
    echo "❌ Country_Life not found in pixel_sheets. Please add it first:\n";
    echo "mysql -h 34.31.66.104 -u root -pAccuPoint01! -e \"INSERT INTO pixel.pixel_sheets (client_name, client_website, pixel_id, pixel_script, sheet_id, sheet_url) VALUES ('Country_Life', 'https://country-life.com', CONCAT('country-life-pixel-', UNIX_TIMESTAMP()), '', '', '');\"\n";
    exit(1);
}

$client_row = $result->fetch_assoc();
echo "✅ Found Country_Life in pixel_sheets\n";
echo "   Pixel ID: {$client_row['pixel_id']}\n";

// Check if sheet already exists
if (!empty($client_row['sheet_id']) && !empty($client_row['sheet_url'])) {
    echo "⚠️  Google Sheet already exists for Country_Life:\n";
    echo "   Sheet ID: {$client_row['sheet_id']}\n";
    echo "   Sheet URL: {$client_row['sheet_url']}\n";
    echo "\nDo you want to continue and create a new sheet? (This will replace the existing one)\n";
    echo "If yes, run this script with 'force' parameter: php setup_country_life_sheet.php force\n";
    
    if (!isset($argv[1]) || $argv[1] !== 'force') {
        exit(0);
    }
}

// Google Sheets API setup
$client = new Client();
$client->setApplicationName('Auto-Pixel Sheet Manager');
$client->setScopes([Sheets::SPREADSHEETS, 'https://www.googleapis.com/auth/drive']);
$client->setAuthConfig('/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json');

$service = new Sheets($client);

try {
    // Create new spreadsheet
    echo "\n📊 Creating Google Sheet for Country_Life...\n";
    
    $spreadsheet = new Google\Service\Sheets\Spreadsheet([
        'properties' => [
            'title' => 'Country_Life - Pixel Data'
        ]
    ]);
    
    $spreadsheet = $service->spreadsheets->create($spreadsheet);
    $spreadsheetId = $spreadsheet->spreadsheetId;
    
    echo "✅ Created Google Sheet: {$spreadsheet->properties->title}\n";
    echo "   Sheet ID: $spreadsheetId\n";
    
    // Set up headers (matching other client sheets)
    $headers = [
        'uuid', 'first_name', 'last_name', 'event_timestamp', 'event_type', 
        'hem_sha256', 'ip_address', 'pixel_id', 'personal_address', 'personal_city', 
        'personal_state', 'personal_zip', 'age_range', 'gender', 'homeowner', 
        'married', 'net_worth', 'income_range', 'direct_number', 'mobile_phone', 
        'personal_phone', 'business_email', 'personal_emails', 'deep_verified_emails', 
        'job_title', 'company_name', 'company_domain', 'company_phone', 
        'company_city', 'company_state', 'linkedin_url', 'url', 'element', 
        'percentage', 'referrer', 'title', 'npn', 'crd'
    ];
    
    $headerRange = 'Sheet1!A1:' . chr(65 + count($headers) - 1) . '1';
    $valueRange = new Google\Service\Sheets\ValueRange([
        'values' => [$headers]
    ]);
    
    $service->spreadsheets_values->update(
        $spreadsheetId,
        $headerRange,
        $valueRange,
        ['valueInputOption' => 'RAW']
    );
    
    echo "✅ Added headers to sheet\n";
    
    // Make sheet publicly readable (for sync access)
    try {
        $drive = new Google\Service\Drive($client);
        
        $permission = new Google\Service\Drive\Permission();
        $permission->setRole('reader');
        $permission->setType('anyone');
        
        $drive->permissions->create($spreadsheetId, $permission);
        echo "✅ Set sheet permissions to publicly readable\n";
    } catch (Exception $e) {
        echo "⚠️  Warning: Could not set sheet permissions: " . $e->getMessage() . "\n";
        echo "   You may need to manually share the sheet\n";
    }
    
    // Update pixel_sheets table with new sheet info
    $sheet_url = "https://docs.google.com/spreadsheets/d/$spreadsheetId/edit";
    
    $update_query = "UPDATE pixel_sheets SET sheet_id = ?, sheet_url = ? WHERE client_name = 'Country_Life'";
    $stmt = $mysqli->prepare($update_query);
    $stmt->bind_param("ss", $spreadsheetId, $sheet_url);
    
    if ($stmt->execute()) {
        echo "✅ Updated pixel_sheets table with new sheet info\n";
    } else {
        echo "❌ Failed to update pixel_sheets table: " . $mysqli->error . "\n";
    }
    
    $stmt->close();
    
    // Verify the update
    $verify_query = "SELECT sheet_id, sheet_url FROM pixel_sheets WHERE client_name = 'Country_Life'";
    $verify_result = $mysqli->query($verify_query);
    $verify_row = $verify_result->fetch_assoc();
    
    echo "\n=== SETUP COMPLETE ===\n";
    echo "Client: Country_Life\n";
    echo "Sheet ID: {$verify_row['sheet_id']}\n";
    echo "Sheet URL: {$verify_row['sheet_url']}\n";
    
    echo "\n=== NEXT STEPS ===\n";
    echo "1. The sheet will automatically sync with dynamic_sync.php (runs every 2 minutes)\n";
    echo "2. To force an immediate sync, run:\n";
    echo "   php reset_sheet_view.php Country_Life\n";
    echo "3. Check sync status in smart_sync.php logs\n";
    echo "4. Verify data appears in the sheet within a few minutes\n";
    
} catch (Exception $e) {
    echo "❌ Error creating Google Sheet: " . $e->getMessage() . "\n";
    exit(1);
}

$mysqli->close();
?> 