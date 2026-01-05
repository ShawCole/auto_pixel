<?php
// FILE: /opt/auto-pixel/batch_share.php
require_once __DIR__ . '/vendor/autoload.php';

// --- CONFIGURATION ---
$SERVICE_ACCOUNT_EMAIL = 'pixel-tracking-sync@thynk-intent-dev-463522.iam.gserviceaccount.com';

// Database Credentials
$DB_HOST = '34.26.61.148';
$DB_NAME = 'pixel';
$DB_USER = 'root';
$DB_PASS = 'AccuPoint01!';
// ---------------------

// 1. Setup Client to act as YOU (The Admin/Owner)
$client = new Google_Client();
$client->setApplicationName('Sheet Permission Grantor');
$client->setScopes([Google_Service_Drive::DRIVE]);
// Ensure this file exists in the same folder
$client->setAuthConfig(__DIR__ . '/client_secret_user.json'); 
$client->setAccessType('offline');

// Load token or prompt for login
$tokenPath = __DIR__ . '/token_user.json';
if (file_exists($tokenPath)) {
    $accessToken = json_decode(file_get_contents($tokenPath), true);
    $client->setAccessToken($accessToken);
}

// If token is expired/missing, force login
if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    } else {
        // CLI Login Flow
        $authUrl = $client->createAuthUrl();
        printf("Open this link in your browser:\n%s\n\n", $authUrl);
        print 'Enter verification code: ';
        $authCode = trim(fgets(STDIN));
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
        $client->setAccessToken($accessToken);
        
        // Save token for next time
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
}

$driveService = new Google_Service_Drive($client);

// 2. Connect to Database
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;port=3306", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ Database Connection Failed: " . $e->getMessage() . "\n");
}

// 3. Get all Sheet IDs from Database
echo "Fetching sheets from database...\n";
$stmt = $pdo->query("SELECT sheet_id, client_name FROM pixel_sheets");
$sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($sheets) . " sheets to process.\n";

// 4. Loop and Share
foreach ($sheets as $sheet) {
    $fileId = $sheet['sheet_id'];
    $name = $sheet['client_name'];
    
    echo "Processing [$name] ($fileId)... ";

    try {
        // Create the permission object (Editor role)
        $newPermission = new Google_Service_Drive_Permission([
            'type' => 'user',
            'role' => 'writer', 
            'emailAddress' => $SERVICE_ACCOUNT_EMAIL
        ]);

        // Apply permission
        $driveService->permissions->create($fileId, $newPermission, [
            'sendNotificationEmail' => false
        ]);
        echo "✅ OK\n";

    } catch (Google_Service_Exception $e) {
        $error = json_decode($e->getMessage(), true);
        if (isset($error['error']['code']) && $error['error']['code'] == 404) {
            echo "❌ NOT FOUND (Account owner must run this script)\n";
        } else {
            // Ignore "already exists" errors
            echo "⚠️ " . $e->getMessage() . "\n";
        }
    }
}
?>
