<?php
/**
 * Backfill superpixel_emails table and perform NPN/CRD lookup
 * In the hybrid approach:
 * - Database triggers should parse emails automatically
 * - This script focuses on NPN/CRD lookup for existing data
 * - Can also parse emails if triggers are not working
 * 
 * Usage: php backfill_emails_from_events.php <database_name> [debug]
 */

require_once __DIR__ . '/process_visitor_emails.php';

if ($argc < 2) {
    die("Usage: php backfill_emails_from_events.php <database_name>\n");
}

$database = $argv[1];
$debug = isset($argv[2]) && $argv[2] === 'debug';

// Database configuration (consistent with other scripts)
$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';

// Connect to database
$mysqli = new mysqli($host, $user, $pass, $database);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== BACKFILLING EMAILS FOR DATABASE: $database ===\n\n";

// Check if tables exist
$tables = ['superpixel_resolution_log', 'superpixel_visitors', 'superpixel_emails'];
foreach ($tables as $table) {
    $result = $mysqli->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows == 0) {
        die("Error: Table '$table' does not exist in database '$database'\n");
    }
}

// Get count of existing emails
$result = $mysqli->query("SELECT COUNT(*) as count FROM superpixel_emails");
$row = $result->fetch_assoc();
$existing_emails = $row['count'];
echo "Existing emails in superpixel_emails: $existing_emails\n\n";

// Get all unique UUIDs with emails
$query = "
    SELECT DISTINCT uuid
    FROM (
        SELECT uuid FROM superpixel_resolution_log 
        WHERE uuid IS NOT NULL AND uuid != '' AND uuid != 'null'
        AND (business_email IS NOT NULL OR personal_emails IS NOT NULL OR deep_verified_emails IS NOT NULL)
        
        UNION
        
        SELECT uuid FROM superpixel_visitors
        WHERE uuid IS NOT NULL AND uuid != '' AND uuid != 'null'
        AND (business_email IS NOT NULL OR personal_emails IS NOT NULL OR deep_verified_emails IS NOT NULL)
    ) all_uuids
    ORDER BY uuid
";

$result = $mysqli->query($query);
if (!$result) {
    die("Error getting UUIDs: " . $mysqli->error . "\n");
}

$total_uuids = $result->num_rows;
echo "Found $total_uuids UUIDs with emails to process\n\n";

$processed = 0;
$emails_parsed = 0;
$npns_found = 0;
$crds_found = 0;

// Process each UUID
while ($row = $result->fetch_assoc()) {
    $uuid = $row['uuid'];
    $processed++;
    
    if ($debug) {
        echo "[$processed/$total_uuids] Processing UUID: $uuid\n";
    }
    
    // Process emails for this UUID
    $results = processVisitorEmails($database, $uuid, true, $debug);
    
    $emails_parsed += $results['emails_parsed'];
    if ($results['npn_found']) $npns_found++;
    if ($results['crd_found']) $crds_found++;
    
    // Show progress every 100 records
    if ($processed % 100 == 0) {
        $percent = round(($processed / $total_uuids) * 100, 1);
        echo "Progress: $processed/$total_uuids ($percent%) - Emails: $emails_parsed, NPNs: $npns_found, CRDs: $crds_found\n";
    }
}

echo "\n=== BACKFILL COMPLETE ===\n";
echo "UUIDs processed: $processed\n";
echo "Emails parsed: $emails_parsed\n";
echo "NPNs found: $npns_found\n";
echo "CRDs found: $crds_found\n";

// Show final count
$result = $mysqli->query("SELECT COUNT(*) as count FROM superpixel_emails");
$row = $result->fetch_assoc();
$final_emails = $row['count'];
$new_emails = $final_emails - $existing_emails;
echo "\nTotal emails in superpixel_emails: $final_emails (+" . $new_emails . " new)\n";

// Show NPN/CRD coverage
$result = $mysqli->query("
    SELECT 
        COUNT(DISTINCT uuid) as total_visitors,
        COUNT(DISTINCT CASE WHEN npn IS NOT NULL THEN uuid END) as with_npn,
        COUNT(DISTINCT CASE WHEN crd IS NOT NULL THEN uuid END) as with_crd
    FROM superpixel_visitors
");
$coverage = $result->fetch_assoc();

echo "\nVisitor NPN/CRD Coverage:\n";
echo "Total visitors: " . $coverage['total_visitors'] . "\n";
echo "With NPN: " . $coverage['with_npn'] . " (" . round($coverage['with_npn'] / $coverage['total_visitors'] * 100, 1) . "%)\n";
echo "With CRD: " . $coverage['with_crd'] . " (" . round($coverage['with_crd'] / $coverage['total_visitors'] * 100, 1) . "%)\n";

$mysqli->close();
?> 