<?php
// Configuration
$dbHost = '10.20.144.3';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$dbName = 'pixel';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) die("Connection failed: " . $mysqli->connect_error . "\n");

// 1. Verify Recency & Fetch Data
echo "Fetching last 5,000 events...\n";

// We include event_timestamp to verify recency
$query = "SELECT event_timestamp, payload FROM raw_events ORDER BY id DESC LIMIT 5000";
$result = $mysqli->query($query);

$stats = [
    'total' => 0,
    'min_time' => null,
    'max_time' => null,
    'keys_found' => ['DEEP' => 0, 'BUSINESS' => 0, 'PERSONAL' => 0],
    'values_found' => ['DEEP' => 0, 'BUSINESS' => 0, 'PERSONAL' => 0]
];

while ($row = $result->fetch_assoc()) {
    $stats['total']++;
    
    // Track Time Window
    $ts = $row['event_timestamp'];
    if ($stats['min_time'] === null || $ts < $stats['min_time']) $stats['min_time'] = $ts;
    if ($stats['max_time'] === null || $ts > $stats['max_time']) $stats['max_time'] = $ts;

    $data = json_decode($row['payload'], true);
    $res = $data['resolution'] ?? [];

    // Check Keys (Does the column exist in the JSON?)
    if (array_key_exists('DEEP_VERIFIED_EMAILS', $res)) $stats['keys_found']['DEEP']++;
    if (array_key_exists('BUSINESS_VERIFIED_EMAILS', $res)) $stats['keys_found']['BUSINESS']++;
    if (array_key_exists('PERSONAL_VERIFIED_EMAILS', $res)) $stats['keys_found']['PERSONAL']++;

    // Check Values (Is it not empty?)
    if (!empty($res['DEEP_VERIFIED_EMAILS'])) $stats['values_found']['DEEP']++;
    if (!empty($res['BUSINESS_VERIFIED_EMAILS'])) $stats['values_found']['BUSINESS']++;
    if (!empty($res['PERSONAL_VERIFIED_EMAILS'])) $stats['values_found']['PERSONAL']++;
}

echo "\n=== TIMEFRAME CHECK ===\n";
echo "Oldest Event in batch: " . $stats['min_time'] . "\n";
echo "Newest Event in batch: " . $stats['max_time'] . "\n";
echo "Total Rows Scanned:    " . $stats['total'] . "\n";

echo "\n=== COLUMN HEALTH ===\n";
echo "DEEP_VERIFIED     : Key Present: {$stats['keys_found']['DEEP']} | Value Populated: {$stats['values_found']['DEEP']}\n";
echo "BUSINESS_VERIFIED : Key Present: {$stats['keys_found']['BUSINESS']} | Value Populated: {$stats['values_found']['BUSINESS']}\n";
echo "PERSONAL_VERIFIED : Key Present: {$stats['keys_found']['PERSONAL']} | Value Populated: {$stats['values_found']['PERSONAL']}\n";

?>
