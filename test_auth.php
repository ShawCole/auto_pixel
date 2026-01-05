<?php
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;

// We use the file we know exists
$keyFile = '/opt/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

echo "Testing Key File: $keyFile\n";

if (!file_exists($keyFile)) {
    die("❌ Error: File not found!\n");
}

$client = new Client();
$client->setAuthConfig($keyFile);
$client->addScope('https://www.googleapis.com/auth/spreadsheets');

try {
    echo "Attempting to fetch Access Token...\n";
    $token = $client->fetchAccessTokenWithAssertion();
    
    if (isset($token['error'])) {
        echo "❌ FAILURE. Google rejected the key.\n";
        echo "Error: " . $token['error'] . "\n";
        echo "Description: " . $token['error_description'] . "\n";
    } else {
        echo "✅ SUCCESS! The key is valid.\n";
        echo "Access Token received: " . substr($token['access_token'], 0, 15) . "...\n";
    }
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
}
?>
