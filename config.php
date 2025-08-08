<?php
// Database connection configuration for Auto_Pixel system

// Global database connection (for connecting to databases)
$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

// Create global connection variable
$connection = new mysqli($dbHost, $dbUser, $dbPass);

if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

// Function to get connection to specific database
function getClientConnection($clientName) {
    global $dbHost, $dbUser, $dbPass;
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $clientName);
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed for $clientName: " . $mysqli->connect_error);
    }
    return $mysqli;
}

// Debugging function for consistent logging
if (!function_exists('debugLog')) {
    function debugLog($message) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] $message\n";
        error_log("[$timestamp] $message");
    }
}
?>
