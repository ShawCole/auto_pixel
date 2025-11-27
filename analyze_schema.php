<?php
// Configuration
$dbHost = '10.20.144.3'; // Internal IP
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$dbName = 'pixel';

// 1. Connect
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "Connected. Scanning raw_events for unique data keys...\n";

// 2. Fetch Data
// We select payload from raw_events. 
// Since each row IS an event, we just decode it directly.
$query = "SELECT payload FROM raw_events ORDER BY id DESC LIMIT 500000";
$result = $mysqli->query($query);

$eventDataKeys = [];
$resolutionKeys = [];
$rootKeys = [];
$rowCount = 0;

// 3. Analyze
while ($row = $result->fetch_assoc()) {
    $rowCount++;
    $event = json_decode($row['payload'], true);

    if (!$event) continue;

    // A. Analyze Root Keys (e.g., event_type, pixel_id)
    foreach (array_keys($event) as $key) {
        $rootKeys[$key] = true;
    }

    // B. Analyze 'event_data' (The dynamic stuff: video, forms, clicks)
    if (isset($event['event_data']) && is_array($event['event_data'])) {
        foreach (array_keys($event['event_data']) as $key) {
            $eventDataKeys[$key] = true;
        }
    }

    // C. Analyze 'resolution' (The person data)
    if (isset($event['resolution']) && is_array($event['resolution'])) {
        foreach (array_keys($event['resolution']) as $key) {
            $resolutionKeys[$key] = true;
        }
    }
}

$mysqli->close();

// 4. Output
echo "Analyzed $rowCount rows.\n\n";

echo "--- [ROOT] Keys found directly in the event object ---\n";
$sortedRoot = array_keys($rootKeys);
sort($sortedRoot);
foreach ($sortedRoot as $key) echo "  " . $key . "\n";

echo "\n--- [EVENT_DATA] Keys found inside 'event_data' ---\n";
$sortedEvent = array_keys($eventDataKeys);
sort($sortedEvent);
foreach ($sortedEvent as $key) echo "  " . $key . "\n";

echo "\n--- [RESOLUTION] Keys found inside 'resolution' ---\n";
$sortedRes = array_keys($resolutionKeys);
sort($sortedRes);
foreach ($sortedRes as $key) echo "  " . $key . "\n";
?>
