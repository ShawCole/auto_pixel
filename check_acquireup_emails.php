<?php
/**
 * Check AcquireUp superpixel_emails table population
 */

$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';
$client_db = 'AcquireUp';

echo "=== CHECKING ACQUIREUP SUPERPIXEL_EMAILS TABLE ===\n\n";

$mysqli = new mysqli($host, $user, $pass, $client_db);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

// Check if superpixel_emails table exists and has data
echo "1. CHECKING TABLE EXISTENCE AND COUNT\n";
echo "=====================================\n";

$count_query = "SELECT COUNT(*) as total FROM superpixel_emails";
$result = $mysqli->query($count_query);
if ($result && $row = $result->fetch_assoc()) {
    echo "✅ superpixel_emails table exists\n";
    echo "📊 Total emails: {$row['total']}\n";
    
    if ($row['total'] == 0) {
        echo "❌ Table is EMPTY - needs population!\n";
    }
} else {
    echo "❌ superpixel_emails table missing or query failed\n";
    exit;
}

// Show sample emails if any exist
if ($row['total'] > 0) {
    echo "\n2. SAMPLE EMAILS IN TABLE\n";
    echo "=========================\n";
    
    $sample_query = "SELECT uuid, email, email_type, source_column 
                     FROM superpixel_emails 
                     LIMIT 10";
    $result = $mysqli->query($sample_query);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "UUID: {$row['uuid']} | Email: {$row['email']} | Type: {$row['email_type']}\n";
        }
    }
    
    // Check unique email count
    echo "\n3. UNIQUE EMAIL ANALYSIS\n";
    echo "========================\n";
    
    $unique_query = "SELECT 
                        COUNT(DISTINCT email) as unique_emails,
                        COUNT(DISTINCT uuid) as unique_uuids,
                        COUNT(*) as total_rows
                     FROM superpixel_emails";
    $result = $mysqli->query($unique_query);
    if ($result && $row = $result->fetch_assoc()) {
        echo "Unique emails: {$row['unique_emails']}\n";
        echo "Unique UUIDs: {$row['unique_uuids']}\n";
        echo "Total rows: {$row['total_rows']}\n";
    }
}

// Check superpixel_visitors for email data that needs parsing
echo "\n4. CHECKING SOURCE DATA IN SUPERPIXEL_VISITORS\n";
echo "===============================================\n";

$visitor_query = "SELECT COUNT(*) as total,
                         SUM(CASE WHEN personal_emails IS NOT NULL AND personal_emails != '' THEN 1 ELSE 0 END) as with_personal,
                         SUM(CASE WHEN business_email IS NOT NULL AND business_email != '' THEN 1 ELSE 0 END) as with_business
                  FROM superpixel_visitors";
$result = $mysqli->query($visitor_query);
if ($result && $row = $result->fetch_assoc()) {
    echo "Total visitors: {$row['total']}\n";
    echo "With personal_emails: {$row['with_personal']}\n";
    echo "With business_email: {$row['with_business']}\n";
}

// Sample visitor emails to see format
echo "\n5. SAMPLE VISITOR EMAIL DATA\n";
echo "============================\n";

$sample_visitor_query = "SELECT uuid, personal_emails, business_email 
                         FROM superpixel_visitors 
                         WHERE (personal_emails IS NOT NULL AND personal_emails != '') 
                            OR (business_email IS NOT NULL AND business_email != '')
                         LIMIT 3";
$result = $mysqli->query($sample_visitor_query);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "UUID: {$row['uuid']}\n";
        echo "  Personal: {$row['personal_emails']}\n";
        echo "  Business: {$row['business_email']}\n";
        echo "---\n";
    }
} else {
    echo "❌ No visitors with email data found\n";
}

$mysqli->close();

echo "\n=== RECOMMENDATION ===\n";
echo "If superpixel_emails is empty but visitors have email data:\n";
echo "👉 Run: php backfill_emails_from_events.php AcquireUp\n";
echo "This will parse comma-separated emails and populate superpixel_emails table.\n";
?> 