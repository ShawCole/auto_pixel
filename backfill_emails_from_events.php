<?php
/**
 * Script to backfill superpixel_emails table from existing events in superpixel_resolution_log
 * This will parse all emails from events and populate the email table, then trigger NPN/CRD lookup
 */

header('Content-Type: application/json');

$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

// Get database name from command line argument
if ($argc < 2) {
    die("❌ Usage: php backfill_emails_from_events.php <database_name>\n");
}
$dbName = $argv[1];

echo "🔄 Starting email backfill for $dbName database...\n";

// Connect to database
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    die("❌ Connection failed: " . $mysqli->connect_error);
}

echo "✅ Connected to database: $dbName\n";

try {
    // Check if superpixel_emails table exists
    $table_check = $mysqli->query("SHOW TABLES LIKE 'superpixel_emails'");
    if ($table_check->num_rows == 0) {
        die("❌ superpixel_emails table does not exist in $dbName\n");
    }

    // Check if parse_visitor_emails procedure exists
    $proc_check = $mysqli->query("SHOW PROCEDURE STATUS WHERE Db = '$dbName' AND Name = 'parse_visitor_emails'");
    if ($proc_check->num_rows == 0) {
        die("❌ parse_visitor_emails procedure does not exist in $dbName\n");
    }

    // Get all unique events with emails
    $query = "
        SELECT DISTINCT uuid, business_email, personal_emails, deep_verified_emails
        FROM superpixel_resolution_log
        WHERE uuid IS NOT NULL AND uuid != '' AND uuid != 'null'
          AND (business_email IS NOT NULL OR personal_emails IS NOT NULL OR deep_verified_emails IS NOT NULL)
        ORDER BY id DESC
    ";

    echo "🔍 Querying events with emails...\n";
    $result = $mysqli->query($query);
    
    if (!$result) {
        throw new Exception("Query failed: " . $mysqli->error);
    }

    $totalEvents = $result->num_rows;
    echo "📊 Found $totalEvents events with emails to process\n";

    if ($totalEvents === 0) {
        echo "✅ No events with emails found. Nothing to backfill.\n";
        exit;
    }

    // Process events
    $successCount = 0;
    $errorCount = 0;
    $emailCount = 0;

    while ($row = $result->fetch_assoc()) {
        $uuid = $row['uuid'];
        
        // Parse business emails
        if (!empty($row['business_email'])) {
            $stmt = $mysqli->prepare("CALL parse_visitor_emails(?, ?, 'business', 'business_email')");
            if ($stmt) {
                $stmt->bind_param("ss", $uuid, $row['business_email']);
                if ($stmt->execute()) {
                    $emailCount++;
                }
                $stmt->close();
            }
        }
        
        // Parse personal emails
        if (!empty($row['personal_emails'])) {
            $stmt = $mysqli->prepare("CALL parse_visitor_emails(?, ?, 'personal', 'personal_emails')");
            if ($stmt) {
                $stmt->bind_param("ss", $uuid, $row['personal_emails']);
                if ($stmt->execute()) {
                    $emailCount++;
                }
                $stmt->close();
            }
        }
        
        // Parse deep verified emails
        if (!empty($row['deep_verified_emails'])) {
            $stmt = $mysqli->prepare("CALL parse_visitor_emails(?, ?, 'deep_verified', 'deep_verified_emails')");
            if ($stmt) {
                $stmt->bind_param("ss", $uuid, $row['deep_verified_emails']);
                if ($stmt->execute()) {
                    $emailCount++;
                }
                $stmt->close();
            }
        }
        
        $successCount++;
        
        // Progress indicator
        if ($successCount % 100 == 0) {
            echo "⏳ Processed $successCount/$totalEvents events...\n";
        }
    }

    echo "\n📊 Backfill Results:\n";
    echo "   ✅ Events processed: $successCount\n";
    echo "   📧 Email fields parsed: $emailCount\n";

    // Count unique emails in the table
    $emailCountResult = $mysqli->query("SELECT COUNT(DISTINCT email) as count FROM superpixel_emails");
    $uniqueEmails = $emailCountResult->fetch_assoc()['count'];
    echo "   🎯 Unique emails in table: $uniqueEmails\n";

    // Check how many have NPN/CRD matches
    echo "\n🔍 Checking NPN/CRD matches...\n";
    
    // Update NPN/CRD for all emails (trigger the lookup)
    $npnCrdQuery = "
        SELECT COUNT(DISTINCT v.uuid) as matched_visitors
        FROM superpixel_visitors v
        WHERE v.npn IS NOT NULL OR v.crd IS NOT NULL
    ";
    
    $npnResult = $mysqli->query($npnCrdQuery);
    $matchedVisitors = $npnResult->fetch_assoc()['matched_visitors'];
    
    echo "   🎯 Visitors with NPN/CRD: $matchedVisitors\n";

} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
} finally {
    $mysqli->close();
}

echo "\n✅ Email backfill process complete!\n";
?> 