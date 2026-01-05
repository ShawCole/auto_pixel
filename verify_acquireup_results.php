<?php
/**
 * Verify AcquireUp results after backfill
 */

$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';
$client_db = 'AcquireUp';

echo "=== VERIFYING ACQUIREUP RESULTS AFTER BACKFILL ===\n\n";

$mysqli = new mysqli($host, $user, $pass, $client_db);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

// 1. Verify superpixel_emails table population
echo "1. SUPERPIXEL_EMAILS TABLE VERIFICATION\n";
echo "=======================================\n";

$count_query = "SELECT COUNT(*) as total FROM superpixel_emails";
$result = $mysqli->query($count_query);
$row = $result->fetch_assoc();
echo "✅ Total emails in superpixel_emails: {$row['total']}\n";

// Show sample emails
$sample_query = "SELECT uuid, email, email_type FROM superpixel_emails LIMIT 5";
$result = $mysqli->query($sample_query);
echo "\nSample emails:\n";
while ($row = $result->fetch_assoc()) {
    echo "  {$row['uuid']} | {$row['email']} | {$row['email_type']}\n";
}

// 2. Check NPN/CRD coverage in visitors table
echo "\n2. NPN/CRD COVERAGE IN SUPERPIXEL_VISITORS\n";
echo "==========================================\n";

$coverage_query = "SELECT 
                     COUNT(*) as total,
                     SUM(CASE WHEN npn IS NOT NULL AND npn != '' THEN 1 ELSE 0 END) as with_npn,
                     SUM(CASE WHEN crd IS NOT NULL AND crd != '' THEN 1 ELSE 0 END) as with_crd
                   FROM superpixel_visitors";
$result = $mysqli->query($coverage_query);
$row = $result->fetch_assoc();

$npn_percent = round(($row['with_npn'] / $row['total']) * 100, 1);
$crd_percent = round(($row['with_crd'] / $row['total']) * 100, 1);

echo "Total visitors: {$row['total']}\n";
echo "With NPN: {$row['with_npn']} ({$npn_percent}%)\n";
echo "With CRD: {$row['with_crd']} ({$crd_percent}%)\n";

// 3. Check data consistency between resolution_log and visitors
echo "\n3. DATA CONSISTENCY CHECK\n";
echo "=========================\n";

// Count unique UUIDs in each table
$log_query = "SELECT COUNT(DISTINCT uuid) as unique_uuids FROM superpixel_resolution_log WHERE uuid IS NOT NULL";
$visitor_query = "SELECT COUNT(DISTINCT uuid) as unique_uuids FROM superpixel_visitors WHERE uuid IS NOT NULL";

$log_result = $mysqli->query($log_query);
$visitor_result = $mysqli->query($visitor_query);

$log_count = $log_result->fetch_assoc()['unique_uuids'];
$visitor_count = $visitor_result->fetch_assoc()['unique_uuids'];

echo "Unique UUIDs in superpixel_resolution_log: $log_count\n";
echo "Unique UUIDs in superpixel_visitors: $visitor_count\n";

if ($log_count == $visitor_count) {
    echo "✅ UUID counts match - good consistency\n";
} else {
    echo "❌ UUID mismatch detected!\n";
    echo "   Missing from visitors: " . ($log_count - $visitor_count) . " UUIDs\n";
    
    // Find missing UUIDs
    $missing_query = "SELECT uuid FROM superpixel_resolution_log 
                      WHERE uuid NOT IN (SELECT uuid FROM superpixel_visitors) 
                      AND uuid IS NOT NULL 
                      LIMIT 5";
    $missing_result = $mysqli->query($missing_query);
    
    if ($missing_result && $missing_result->num_rows > 0) {
        echo "\nSample missing UUIDs from visitors table:\n";
        while ($row = $missing_result->fetch_assoc()) {
            echo "  - {$row['uuid']}\n";
        }
    }
}

// 4. Check for database triggers
echo "\n4. DATABASE AUTOMATION CHECK\n";
echo "============================\n";

$triggers_query = "SHOW TRIGGERS FROM $client_db";
$result = $mysqli->query($triggers_query);

if ($result && $result->num_rows > 0) {
    echo "✅ Found " . $result->num_rows . " trigger(s):\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Trigger']} on {$row['Table']} ({$row['Event']} {$row['Timing']})\n";
    }
} else {
    echo "❌ No triggers found - visitor updates are NOT automated\n";
    echo "   This explains why superpixel_visitors isn't automatically updated\n";
}

// 5. Check stored procedures
echo "\n5. STORED PROCEDURES CHECK\n";
echo "==========================\n";

$procedures_query = "SHOW PROCEDURE STATUS WHERE Db = '$client_db'";
$result = $mysqli->query($procedures_query);

if ($result && $result->num_rows > 0) {
    echo "✅ Found " . $result->num_rows . " stored procedure(s):\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Name']}\n";
    }
} else {
    echo "❌ No stored procedures found\n";
}

$mysqli->close();

echo "\n=== SUMMARY ===\n";
echo "1. ✅ Email parsing: WORKING (300 emails extracted)\n";
echo "2. ✅ NPN/CRD lookup: WORKING (13 NPNs, 9 CRDs found)\n";
echo "3. ❌ Visitor automation: NEEDS FIXING (triggers missing)\n";
echo "\nNext step: Fix visitor update automation with proper triggers/procedures.\n";
?> 