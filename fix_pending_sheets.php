<?php
// FILE: /opt/auto-pixel/fix_pending_sheets.php
require_once __DIR__ . '/vendor/autoload.php';

// --- CONFIGURATION ---
$SERVICE_ACCOUNT_EMAIL = 'pixel-tracking-sync@thynk-intent-dev-463522.iam.gserviceaccount.com';
$DB_HOST = '34.26.61.148';
$DB_NAME = 'pixel';
$DB_USER = 'root';
$DB_PASS = 'AccuPoint01!';
// ---------------------

// 1. Setup Auth (Acting as YOU, the Owner)
$client = new Google_Client();
$client->setApplicationName('Sheet Fixer');
$client->setScopes([
    Google_Service_Drive::DRIVE,
    Google_Service_Sheets::SPREADSHEETS
]);
$client->setAuthConfig(__DIR__ . '/client_secret_user.json');
$client->setAccessType('offline');

// Load existing token
$tokenPath = __DIR__ . '/token_user.json';
if (file_exists($tokenPath)) {
    $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));
}

// Check if token is valid
if ($client->isAccessTokenExpired()) {
    // If we have a refresh token, use it
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    } else {
        die("❌ Token expired. Please run 'batch_share.php' again to refresh your login.\n");
    }
}

$serviceSheets = new Google_Service_Sheets($client);
$serviceDrive  = new Google_Service_Drive($client);

// 2. Connect to Database
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;port=3306", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ DB Connection Failed: " . $e->getMessage() . "\n");
}

// 3. Find Broken Rows
echo "🔍 Searching for 'PENDING' sheets...\n";
$stmt = $pdo->query("SELECT id, pixel_name, sheet_id FROM pixel_sheets WHERE sheet_id LIKE 'PENDING%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) === 0) {
    die("✅ No pending sheets found. All clean!\n");
}

echo "Found " . count($rows) . " pending sheets. Fixing now...\n\n";

// 4. Fix Loop
foreach ($rows as $row) {
    $dbId = $row['id'];
    $pixelName = $row['pixel_name'];
    $oldId = $row['sheet_id'];

    echo "🔧 Fixing [$pixelName]... ";

    try {
        // A. Create New Spreadsheet
        $spreadsheet = new Google_Service_Sheets_Spreadsheet([
            'properties' => ['title' => $pixelName]
        ]);
        $spreadsheet = $serviceSheets->spreadsheets->create($spreadsheet, [
            'fields' => 'spreadsheetId'
        ]);
        $newSheetId = $spreadsheet->spreadsheetId;

        // B. Create Tabs (Visitors, Events)
        // Note: The default sheet is "Sheet1". We rename it to "Visitors" and add "Events".
        $batchUpdate = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => [
                // Rename Sheet1 -> Visitors
                [
                    'updateSheetProperties' => [
                        'properties' => ['sheetId' => 0, 'title' => 'Visitors'],
                        'fields' => 'title'
                    ]
                ],
                // Add Events Sheet
                [
                    'addSheet' => [
                        'properties' => ['title' => 'Events']
                    ]
                ]
            ]
        ]);
        $serviceSheets->spreadsheets->batchUpdate($newSheetId, $batchUpdate);

        // C. Share with Service Account
        $newPermission = new Google_Service_Drive_Permission([
            'type' => 'user',
            'role' => 'writer',
            'emailAddress' => $SERVICE_ACCOUNT_EMAIL
        ]);
        $serviceDrive->permissions->create($newSheetId, $newPermission, ['sendNotificationEmail' => false]);

        // D. Update Database
        $updateStmt = $pdo->prepare("UPDATE pixel_sheets SET sheet_id = :new_id, sheet_url = :url WHERE id = :db_id");
        $updateStmt->execute([
            ':new_id' => $newSheetId,
            ':url'    => "https://docs.google.com/spreadsheets/d/$newSheetId",
            ':db_id'  => $dbId
        ]);

        echo "✅ CREATED & LINKED\n";
        echo "   Old ID: $oldId\n";
        echo "   New ID: $newSheetId\n";

    } catch (Exception $e) {
        echo "❌ FAILED: " . $e->getMessage() . "\n";
    }
}
?>
