<?php
/**
 * Check for problematic schemas across all client databases
 * This script identifies databases with missing columns and schema inconsistencies
 */

// Database configuration
$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';

// Connect to database
$mysqli = new mysqli($host, $user, $pass);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== SCHEMA DIAGNOSTIC REPORT ===\n\n";

// Get all client databases
$client_databases = [];
$result = $mysqli->query("
    SELECT DISTINCT TABLE_SCHEMA 
    FROM information_schema.TABLES 
    WHERE TABLE_NAME IN ('superpixel_resolution_log', 'superpixel_visitors')
      AND TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', 'pixel')
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $client_databases[] = $row['TABLE_SCHEMA'];
    }
}

echo "Found " . count($client_databases) . " client databases with pixel tables\n\n";

// Expected columns for superpixel_resolution_log (based on AcquireUp schema)
$expected_resolution_columns = [
    'timestamp', 'title', 'url', 'valid_phones', 'element', 'percentage', 'referrer', 'npn', 'crd'
];

// Expected columns for superpixel_visitors
$expected_visitor_columns = [
    'url', 'element', 'percentage', 'referrer', 'event_timestamp', 'event_type', 'npn', 'crd'
];

$problematic_databases = [];

foreach ($client_databases as $db) {
    echo "Checking database: $db\n";
    
    $issues = [];
    
    // Check superpixel_resolution_log schema
    $result = $mysqli->query("DESCRIBE `$db`.superpixel_resolution_log");
    if (!$result) {
        $issues[] = "superpixel_resolution_log table missing or inaccessible";
    } else {
        $existing_columns = [];
        while ($row = $result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
        
        $missing_columns = array_diff($expected_resolution_columns, $existing_columns);
        if (!empty($missing_columns)) {
            $issues[] = "superpixel_resolution_log missing columns: " . implode(', ', $missing_columns);
        }
        
        // Check for the problematic 'elements' vs 'element' issue
        if (in_array('elements', $existing_columns) && !in_array('element', $existing_columns)) {
            $issues[] = "superpixel_resolution_log has 'elements' instead of 'element'";
        }
    }
    
    // Check superpixel_visitors schema
    $result = $mysqli->query("DESCRIBE `$db`.superpixel_visitors");
    if (!$result) {
        $issues[] = "superpixel_visitors table missing or inaccessible";
    } else {
        $existing_columns = [];
        while ($row = $result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
        
        $missing_columns = array_diff($expected_visitor_columns, $existing_columns);
        if (!empty($missing_columns)) {
            $issues[] = "superpixel_visitors missing columns: " . implode(', ', $missing_columns);
        }
    }
    
    // Check for superpixel_emails table (new requirement)
    $result = $mysqli->query("SHOW TABLES FROM `$db` LIKE 'superpixel_emails'");
    if (!$result || $result->num_rows === 0) {
        $issues[] = "superpixel_emails table missing";
    }
    
    if (!empty($issues)) {
        $problematic_databases[$db] = $issues;
        echo "  ❌ ISSUES FOUND:\n";
        foreach ($issues as $issue) {
            echo "    - $issue\n";
        }
    } else {
        echo "  ✅ Schema looks good\n";
    }
    
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Total databases checked: " . count($client_databases) . "\n";
echo "Databases with issues: " . count($problematic_databases) . "\n";

if (!empty($problematic_databases)) {
    echo "\nProblematic databases:\n";
    foreach ($problematic_databases as $db => $issues) {
        echo "- $db (" . count($issues) . " issues)\n";
    }
    
    echo "\n=== RECOMMENDED ACTIONS ===\n";
    echo "1. Run schema fix script for databases with missing columns\n";
    echo "2. Create superpixel_emails tables where missing\n";
    echo "3. Fix 'elements' vs 'element' column naming\n";
    echo "4. Verify NPN/CRD triggers are working\n";
}

$mysqli->close();
?> 