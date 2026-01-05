<?php
require_once __DIR__ . '/vendor/autoload.php';

$client = new Google\Client();
$client->setAuthConfig('/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json');
$client->setScopes([Google\Service\Drive::DRIVE]);

// THE MOMENT OF TRUTH:
// If DWD is fixed, this line will work. If not, it will crash.
// $client->setSubject('scole@thynkdata.com');

$service = new Google\Service\Drive($client);

try {
    // Try to list files in YOUR drive
    $files = $service->files->listFiles(['pageSize' => 1]);
    echo "✅ SUCCESS! Service Account is successfully impersonating scole@thynkdata.com.\n";
    echo "   (Found file ID: " . $files->getFiles()[0]->getId() . ")\n";
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
}
?>
