<?php
// Configuration
$dbHost = '10.20.144.3';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$dbName = 'pixel';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) die("Connection failed: " . $mysqli->connect_error . "\n");

echo "Scanning last 5,000 events to check email field population...\n";

$query = "SELECT payload FROM raw_events ORDER BY id DESC LIMIT 5000";
$result = $mysqli->query($query);

$stats = [
    'total_rows' => 0,
    'has_deep' => 0,
    'has_business' => 0,
    'has_personal' => 0,
    'deep_equals_combined' => 0 // checks if deep == business + personal
];

$samples = []; // To store a few examples for you to see

while ($row = $result->fetch_assoc()) {
    $data = json_decode($row['payload'], true);
    
    // Check if resolution block exists
    if (!isset($data['resolution']) || !is_array($data['resolution'])) continue;
    
    $res = $data['resolution'];
    $stats['total_rows']++;

    $deep = $res['DEEP_VERIFIED_EMAILS'] ?? '';
    $biz  = $res['BUSINESS_VERIFIED_EMAILS'] ?? '';
    $pers = $res['PERSONAL_VERIFIED_EMAILS'] ?? '';

    // Check for non-empty values
    if (!empty($deep)) $stats['has_deep']++;
    if (!empty($biz))  $stats['has_business']++;
    if (!empty($pers)) $stats['has_personal']++;

    // Check logic: Is Deep just the other two combined?
    // We normalize by sorting comma-separated emails to be sure
    $deepArr = array_filter(array_map('trim', explode(',', $deep)));
    $combinedArr = array_merge(
        array_filter(array_map('trim', explode(',', $biz))),
        array_filter(array_map('trim', explode(',', $pers)))
    );
    sort($deepArr);
    sort($combinedArr);

    if (!empty($deep) && $deepArr == $combinedArr) {
        $stats['deep_equals_combined']++;
    }

    // Save a sample if we find one with data
    if (empty($samples) && !empty($deep)) {
        $samples[] = [
            'DEEP' => $deep,
            'BUSINESS' => $biz,
            'PERSONAL' => $pers
        ];
    }
}

echo "\n--- Results (last {$stats['total_rows']} resolution events) ---\n";
echo "Rows with DEEP_VERIFIED_EMAILS:     " . $stats['has_deep'] . "\n";
echo "Rows with BUSINESS_VERIFIED_EMAILS: " . $stats['has_business'] . "\n";
echo "Rows with PERSONAL_VERIFIED_EMAILS: " . $stats['has_personal'] . "\n";
echo "Rows where DEEP = BUSINESS + PERSONAL: " . $stats['deep_equals_combined'] . "\n";

echo "\n--- Sample Data ---\n";
print_r($samples);
?>
