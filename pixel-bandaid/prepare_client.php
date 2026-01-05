<?php
// prepare_client.php
// Ensures database schema is ready for idempotency

require_once __DIR__ . '/visitor_upsert_functions.php';

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';
$client = isset($argv[1]) ? preg_replace('/[^a-zA-Z0-9_]/', '', $argv[1]) : (getenv('CLIENT_NAME') ?: null);
$dbName = $client ?: (getenv('DB_NAME') ?: 'pixel');

if (!$dbName) {
    echo json_encode(['error' => 'No database specified']);
    exit(1);
}

// Log to separate debug log
$logFile = __DIR__ . '/schema_debug.log';
function schemaLog($msg)
{
    global $logFile;
    file_put_contents($logFile, "[" . date('c') . "] " . $msg . "\n", FILE_APPEND);
}

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }

    // Run Schema Check
    ensureSchema($mysqli);

    echo json_encode(['status' => 'success', 'database' => $dbName]);

} catch (Throwable $e) {
    schemaLog("Error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
}
?>