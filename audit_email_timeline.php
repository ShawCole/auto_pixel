<?php
// Configuration
$dbHost = '10.20.144.3';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$dbName = 'pixel';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) die("Connection failed: " . $mysqli->connect_error . "\n");

// Increase limit to 200,000 to go back further if needed
$limit = 200000;
echo "Scanning last $limit events to find the LAST time these keys were seen...\n";

// We need unbuffered query for large datasets to avoid running out of RAM
$query = "SELECT event_timestamp, payload FROM raw_events ORDER BY id DESC LIMIT $limit";
$result = $mysqli->query($query, MYSQLI_USE_RESULT);

$stats = [
    'scanned' => 0,
    'latest_deep' => null,
    'latest_biz' => null,
    'latest_pers' => null,
    'count_deep' => 0,
    'count_biz' => 0,
    'count_pers' => 0,
];

while ($row = $result->fetch_assoc()) {
    $stats['scanned']++;
    $ts = $row['event_timestamp'];
    $data = json_decode($row['payload'], true);
    $res = $data['resolution'] ?? [];

    // Check Business Key
    if (array_key_exists('BUSINESS_VERIFIED_EMAILS', $res)) {
        if ($stats['latest_biz'] === null) $stats['latest_biz'] = $ts;
        $stats['count_biz']++;
    }

    // Check Personal Key
    if (array_key_exists('PERSONAL_VERIFIED_EMAILS', $res)) {
        if ($stats['latest_pers'] === null) $stats['latest_pers'] = $ts;
        $stats['count_pers']++;
    }

    // Check Deep Key (Control)
    if (array_key_exists('DEEP_VERIFIED_EMAILS', $res)) {
        if ($stats['latest_deep'] === null) $stats['latest_deep'] = $ts;
        $stats['count_deep']++;
    }
}

$mysqli->close();

echo "\n--- SCAN RESULTS ({$stats['scanned']} rows) ---\n";
echo "DEEP_VERIFIED     : Found {$stats['count_deep']} times. Last seen: " . ($stats['latest_deep'] ?? 'NEVER') . "\n";
echo "BUSINESS_VERIFIED : Found {$stats['count_biz']} times. Last seen: " . ($stats['latest_biz'] ?? 'NEVER') . "\n";
echo "PERSONAL_VERIFIED : Found {$stats['count_pers']} times. Last seen: " . ($stats['latest_pers'] ?? 'NEVER') . "\n";

// Interpretation
if ($stats['latest_biz'] === null) {
    echo "\n[CONCLUSION] The new keys are NOT in the active data stream.\n";
    echo "Recommendation: Do NOT add them to V2 Schema yet to keep it clean.\n";
} else {
    echo "\n[CONCLUSION] The new keys ARE present but rare.\n";
    echo "Recommendation: Add them to V2 Schema (they are live).\n";
}
?>
