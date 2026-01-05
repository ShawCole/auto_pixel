<?php
// test_db.php - CLI Test for MySQL Connectivity

echo "--- PHP MySQL Connectivity Test ---\n";
echo "PHP Version: " . phpversion() . "\n";

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!'; // Default fallback for test
$dbName = getenv('DB_NAME') ?: 'pixel';

echo "Target Host: $dbHost\n";
echo "Target User: $dbUser\n";
echo "Target DB: $dbName\n";

$startTime = microtime(true);

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

    if ($mysqli->connect_error) {
        throw new Exception("Connect Error: " . $mysqli->connect_error);
    }

    $duration = microtime(true) - $startTime;
    echo "SUCCESS! Connected in " . number_format($duration, 4) . " seconds.\n";
    echo "Server Info: " . $mysqli->server_info . "\n";
    echo "Host Info: " . $mysqli->host_info . "\n";

    $mysqli->close();
} catch (Throwable $e) {
    $duration = microtime(true) - $startTime;
    echo "FAILURE! Duration: " . number_format($duration, 4) . " seconds.\n";
    echo "Error: " . $e->getMessage() . "\n";
}
?>