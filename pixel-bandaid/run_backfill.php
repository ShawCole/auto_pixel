<?php
// run_backfill.php
// CLI script to trigger visitor backfill for a specific client database

require_once __DIR__ . '/visitor_upsert_functions.php';

// Argument 1: Client Database Name (optional override, otherwise uses ENV)
// Argument 2: Backfill Limit (default 5000)

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';
$client = isset($argv[1]) ? preg_replace('/[^a-zA-Z0-9_]/', '', $argv[1]) : (getenv('CLIENT_NAME') ?: null);
$dbName = $client ?: (getenv('DB_NAME') ?: 'pixel');
$limit = isset($argv[2]) ? intval($argv[2]) : 5000;

if (!$dbName) {
    echo json_encode(['error' => 'No database specified']);
    exit(1);
}

// Log to separate backfill log
$logFile = __DIR__ . '/backfill_debug.log';
function backfillLog($msg)
{
    global $logFile;
    file_put_contents($logFile, "[" . date('c') . "] " . $msg . "\n", FILE_APPEND);
}

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }

    // Explicitly call the backfill function
    $result = backfillMissingVisitors($mysqli, $limit, "cli-backfill");

    // Output JSON result
    echo json_encode([
        'status' => 'success',
        'database' => $dbName,
        'backfilled' => $result['backfilled_count'],
        'errors' => $result['error_count']
    ]);

} catch (Throwable $e) {
    backfillLog("Error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
}
?>