<?php
/**
 * update_pixel_metrics.php
 * 
 * Updates pixel_sheets with fresh metrics from each client database.
 * Run via cron every 2-5 minutes.
 * 
 * Usage: php update_pixel_metrics.php [--client=ClientName]
 */

$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

// Test persona UUIDs to exclude from counts (used by SimpleAudience for webhook tests)
$TEST_UUIDS = [
    'dc0016d3803db4912441edb1b0',  // Margaret Faz test persona
];
$TEST_UUID_SQL = "'" . implode("','", $TEST_UUIDS) . "'";

$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Starting pixel metrics update...\n";

// Parse command line arguments
$specificClient = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--client=') === 0) {
        $specificClient = substr($arg, 9);
    }
}

// Connect to pixel database
$pixelDb = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
if ($pixelDb->connect_error) {
    die("Failed to connect to pixel database: " . $pixelDb->connect_error . "\n");
}

// Configuration for slow clients (update less frequently)
$SLOW_CLIENTS = ['VettaFi'];  // Clients with large datasets
$SLOW_CLIENT_INTERVAL = 3600; // Update slow clients every 1 hour (3600 seconds)

// Check if we should skip slow clients this run
$skipSlowClients = false;
foreach ($argv as $arg) {
    if ($arg === '--skip-slow') {
        $skipSlowClients = true;
    }
    if ($arg === '--only-slow') {
        $specificClient = null; // Will be handled below
    }
}

// Get all clients or specific client
$sql = "SELECT id, client_name, pixel_id, last_sync_at FROM pixel_sheets";
if ($specificClient) {
    $sql .= " WHERE client_name = '" . $pixelDb->real_escape_string($specificClient) . "'";
}
$sql .= " ORDER BY client_name";

$result = $pixelDb->query($sql);
if (!$result) {
    die("Failed to fetch clients: " . $pixelDb->error . "\n");
}

$clients = [];
while ($row = $result->fetch_assoc()) {
    $clients[] = $row;
}

echo "Processing " . count($clients) . " clients...\n";

foreach ($clients as $client) {
    $clientName = $client['client_name'];
    $pixelId = $client['pixel_id'];
    $clientId = $client['id'];
    $lastSyncAt = $client['last_sync_at'] ?? null;
    
    // Check if this is a slow client that should be skipped
    $isSlowClient = in_array($clientName, $SLOW_CLIENTS);
    if ($isSlowClient && $skipSlowClients) {
        echo "  Skipping $clientName (slow client, use --only-slow to update)\n";
        continue;
    }
    
    // For slow clients, check if enough time has passed since last update
    if ($isSlowClient && $lastSyncAt) {
        $lastSync = strtotime($lastSyncAt);
        $timeSinceSync = time() - $lastSync;
        if ($timeSinceSync < $SLOW_CLIENT_INTERVAL) {
            $remaining = $SLOW_CLIENT_INTERVAL - $timeSinceSync;
            echo "  Skipping $clientName (updated " . round($timeSinceSync/60) . " min ago, next in " . round($remaining/60) . " min)\n";
            continue;
        }
    }
    
    echo "  Updating $clientName" . ($isSlowClient ? " (slow client)" : "") . "... ";
    
    // Connect to client database
    $clientDb = @new mysqli($dbHost, $dbUser, $dbPass, $clientName);
    if ($clientDb->connect_error) {
        echo "SKIP (DB not found)\n";
        continue;
    }
    
    // Get visitor count (unique UUIDs, excluding test personas)
    $visitorCount = 0;
    $visitorResult = $clientDb->query("
        SELECT COUNT(DISTINCT uuid) as cnt 
        FROM superpixel_resolution_log 
        WHERE uuid IS NOT NULL AND uuid != ''
          AND uuid NOT IN ($TEST_UUID_SQL)
    ");
    if ($visitorResult && $row = $visitorResult->fetch_assoc()) {
        $visitorCount = (int)$row['cnt'];
    }
    
    // Get event count (excluding test personas)
    $eventCount = 0;
    $eventResult = $clientDb->query("
        SELECT COUNT(*) as cnt 
        FROM superpixel_resolution_log 
        WHERE uuid IS NOT NULL AND uuid != ''
          AND uuid NOT IN ($TEST_UUID_SQL)
    ");
    if ($eventResult && $row = $eventResult->fetch_assoc()) {
        $eventCount = (int)$row['cnt'];
    }
    
    // Get last event timestamp and convert to MySQL format (excluding test personas)
    $lastEventAt = null;
    $lastEventResult = $clientDb->query("
        SELECT MAX(event_timestamp) as last_event 
        FROM superpixel_resolution_log 
        WHERE uuid IS NOT NULL AND uuid != ''
          AND uuid NOT IN ($TEST_UUID_SQL)
          AND LOWER(COALESCE(event_type,'')) NOT LIKE '%test%'
    ");
    if ($lastEventResult && $row = $lastEventResult->fetch_assoc()) {
        $rawTimestamp = $row['last_event'];
        if ($rawTimestamp) {
            // Convert ISO 8601 format to MySQL datetime format
            $dt = new DateTime($rawTimestamp);
            $lastEventAt = $dt->format('Y-m-d H:i:s');
        }
    }
    
    $clientDb->close();
    
    // Update pixel_sheets
    $updateSql = "UPDATE pixel_sheets SET 
        visitors = ?,
        events = ?,
        last_event_at = ?
        WHERE id = ?";
    
    $stmt = $pixelDb->prepare($updateSql);
    $stmt->bind_param("iisi", $visitorCount, $eventCount, $lastEventAt, $clientId);
    
    if ($stmt->execute()) {
        echo "OK (visitors: $visitorCount, events: $eventCount)\n";
    } else {
        echo "FAILED: " . $stmt->error . "\n";
    }
    $stmt->close();
}

$pixelDb->close();

$elapsed = round(microtime(true) - $startTime, 2);
echo "[" . date('Y-m-d H:i:s') . "] Completed in {$elapsed}s\n";
?>

