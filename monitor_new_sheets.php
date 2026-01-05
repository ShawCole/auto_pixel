<?php
// monitor_new_sheets.php - Continuous background monitoring for new sheets
require_once __DIR__ . '/vendor/autoload.php';

// Configuration
$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/opt/auto-pixel/credentials.json';

// Monitoring configuration
$CHECK_INTERVAL = 10; // Check every 10 seconds
$LOG_FILE = '/opt/auto-pixel/monitor.log';

function logMessage($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    echo $logEntry;
    file_put_contents($LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

function checkForNewSheets() {
    global $dbHost, $dbUser, $dbPass;
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
    if ($mysqli->connect_error) {
        logMessage("ERROR: Could not connect to pixel database: " . $mysqli->connect_error);
        return [];
    }
    
    // Find sheets that have never been synced (last_sync_at is NULL)
    $sql = "SELECT client_name, sheet_id FROM pixel_sheets 
            WHERE sheet_id IS NOT NULL AND last_sync_at IS NULL";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        logMessage("ERROR: Could not query pixel_sheets: " . $mysqli->error);
        $mysqli->close();
        return [];
    }
    
    $newSheets = [];
    while ($row = $result->fetch_assoc()) {
        $newSheets[] = $row;
    }
    
    $mysqli->close();
    return $newSheets;
}

function triggerImmediateSync($clientName, $sheetId) {
    logMessage("🚨 TRIGGERING IMMEDIATE SYNC for $clientName");
    
    // Execute smart_sync.php with specific client parameter
    $command = "cd /opt/auto-pixel && php smart_sync.php --client=$clientName >> /opt/auto-pixel/immediate_sync.log 2>&1 &";
    exec($command);
    
    logMessage("✅ Immediate sync triggered for $clientName (running in background)");
}

// Main monitoring loop
logMessage("🔍 Starting new sheet monitor (checking every ${CHECK_INTERVAL}s)");

$lastCheckedSheets = [];

while (true) {
    try {
        $newSheets = checkForNewSheets();
        
        // Check if we have any new sheets that weren't there before
        foreach ($newSheets as $sheet) {
            $sheetKey = $sheet['client_name'] . '|' . $sheet['sheet_id'];
            if (!in_array($sheetKey, $lastCheckedSheets)) {
                logMessage("🆕 NEW SHEET DETECTED: " . $sheet['client_name']);
                triggerImmediateSync($sheet['client_name'], $sheet['sheet_id']);
            }
        }
        
        // Update our tracking
        $lastCheckedSheets = [];
        foreach ($newSheets as $sheet) {
            $lastCheckedSheets[] = $sheet['client_name'] . '|' . $sheet['sheet_id'];
        }
        
        // Log status every minute
        if (date('s') < 10) {
            logMessage("📊 Monitor status: " . count($newSheets) . " unsynced sheets found");
        }
        
    } catch (Exception $e) {
        logMessage("ERROR: " . $e->getMessage());
    }
    
    sleep($CHECK_INTERVAL);
}
?> 