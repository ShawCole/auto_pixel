<?php
// Script to backfill missing visitors from existing events in AcquireUp database

header('Content-Type: application/json');

$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

// Get database name from command line argument
if ($argc < 2) {
    die("❌ Usage: php backfill_missing_visitors.php <database_name>\n");
}
$dbName = $argv[1];

echo "🔄 Starting backfill for missing visitors in $dbName database...\n";

// Connect to database
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    die("❌ Connection failed: " . $mysqli->connect_error);
}

echo "✅ Connected to database: $dbName\n";

try {
    // Get actual columns from the superpixel_resolution_log table
    echo "🔍 Checking table schema...\n";
    $columnsResult = $mysqli->query("SHOW COLUMNS FROM superpixel_resolution_log");
    $availableColumns = [];
    while ($row = $columnsResult->fetch_assoc()) {
        $availableColumns[] = $row['Field'];
    }
    
    // Define desired columns and check which ones exist
    $desiredColumns = [
        'uuid', 'first_name', 'last_name', 'personal_address', 'personal_city', 
        'personal_state', 'personal_zip', 'personal_zip4', 'age_range', 'children', 
        'gender', 'homeowner', 'married', 'net_worth', 'income_range', 'direct_number', 
        'direct_number_dnc', 'mobile_phone', 'mobile_phone_dnc', 'personal_phone', 
        'personal_phone_dnc', 'business_email', 'personal_emails', 'deep_verified_emails', 
        'sha256_personal_email', 'sha256_business_email', 'job_title', 'headline', 
        'department', 'seniority_level', 'inferred_years_experience', 'company_name_history', 
        'job_title_history', 'education_history', 'company_address', 'company_description', 
        'company_domain', 'company_employee_count', 'company_name', 'company_phone', 
        'company_revenue', 'company_sic', 'company_naics', 'company_city', 'company_state', 
        'company_zip', 'company_industry', 'linkedin_url', 'twitter_url', 'facebook_url', 
        'social_connections', 'skills', 'interests', 'skiptrace_match_score', 'skiptrace_name', 
        'skiptrace_address', 'skiptrace_city', 'skiptrace_state', 'skiptrace_zip', 
        'skiptrace_landline_numbers', 'skiptrace_wireless_numbers', 'skiptrace_credit_rating', 
        'skiptrace_dnc', 'skiptrace_exact_age', 'skiptrace_ethnic_code', 'skiptrace_language_code', 
        'skiptrace_ip', 'skiptrace_b2b_address', 'skiptrace_b2b_phone', 'skiptrace_b2b_source', 
        'skiptrace_b2b_website', 'valid_phones', 'hem_sha256', 'url', 'element', 'percentage', 
        'referrer', 'event_type'
    ];
    
    // Build SELECT clause with only existing columns
    $selectColumns = [];
    foreach ($desiredColumns as $col) {
        if (in_array($col, $availableColumns)) {
            $selectColumns[] = "r1.$col";
        }
    }
    
    // Handle timestamp field (might be 'timestamp' or 'event_timestamp')
    if (in_array('timestamp', $availableColumns)) {
        $selectColumns[] = "r1.timestamp as event_timestamp";
    } elseif (in_array('event_timestamp', $availableColumns)) {
        $selectColumns[] = "r1.event_timestamp";
    }
    
    $selectClause = implode(", ", $selectColumns) . ", counts.event_count";
    
    echo "📋 Using " . count($selectColumns) . " available columns\n";
    
    // Get all unique UUIDs from events that are missing from visitors table
    // Use a subquery to get the latest event data for each UUID
    $query = "
        SELECT $selectClause
        FROM superpixel_resolution_log r1
        INNER JOIN (
            SELECT r.uuid, COUNT(*) as event_count, MAX(r.id) as latest_event_id
            FROM superpixel_resolution_log r 
            LEFT JOIN superpixel_visitors v ON r.uuid = v.uuid 
            WHERE v.uuid IS NULL 
              AND r.uuid IS NOT NULL 
              AND r.uuid != '' 
              AND r.uuid != 'null'
            GROUP BY r.uuid
        ) counts ON r1.uuid = counts.uuid AND r1.id = counts.latest_event_id
        ORDER BY counts.latest_event_id DESC
    ";

    echo "🔍 Querying for missing visitors...\n";
    $result = $mysqli->query($query);
    
    if (!$result) {
        throw new Exception("Query failed: " . $mysqli->error);
    }

    $missingVisitors = [];
    while ($row = $result->fetch_assoc()) {
        $missingVisitors[] = $row;
    }

    $totalMissing = count($missingVisitors);
    echo "📊 Found $totalMissing missing visitors to backfill\n";

    if ($totalMissing === 0) {
        echo "✅ No missing visitors found. All events already have corresponding visitor records.\n";
        exit;
    }

    // Display first few missing visitors for verification
    echo "\n📋 Sample missing visitors:\n";
    for ($i = 0; $i < min(5, $totalMissing); $i++) {
        $visitor = $missingVisitors[$i];
        echo "   - {$visitor['first_name']} {$visitor['last_name']} (UUID: {$visitor['uuid']}, Events: {$visitor['event_count']})\n";
    }

    // Ask for confirmation
    echo "\n⚠️  This will create $totalMissing new visitor records. Continue? (y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 'y') {
        echo "❌ Cancelled by user.\n";
        exit;
    }

    echo "\n🚀 Starting backfill process...\n";

    $successCount = 0;
    $failedCount = 0;

    foreach ($missingVisitors as $index => $visitorData) {
        $uuid = $visitorData['uuid'];
        echo "Processing visitor " . ($index + 1) . "/$totalMissing: {$visitorData['first_name']} {$visitorData['last_name']} (UUID: $uuid)...";

        try {
            // Prepare visitor data for insertion
            $columns = [];
            $values = [];

            foreach ($visitorData as $key => $value) {
                // Skip computed fields
                if (in_array($key, ['event_count', 'latest_event_id'])) {
                    continue;
                }

                $columns[] = "`" . $mysqli->real_escape_string($key) . "`";
                $values[] = ($value === null || $value === '') ? "NULL" : "'" . $mysqli->real_escape_string($value) . "'";
            }

            // Add metadata fields
            $columns[] = "`event_count`";
            $values[] = intval($visitorData['event_count']);
            
            $columns[] = "`first_seen_at`";
            $values[] = "CURRENT_TIMESTAMP";
            
            $columns[] = "`last_seen_at`";
            $values[] = "CURRENT_TIMESTAMP";

            // Insert visitor record
            $insertSql = "INSERT INTO superpixel_visitors (" . implode(",", $columns) . ") VALUES (" . implode(",", $values) . ")";
            
            if ($mysqli->query($insertSql)) {
                echo " ✅\n";
                $successCount++;
            } else {
                echo " ❌ Failed: " . $mysqli->error . "\n";
                $failedCount++;
            }

        } catch (Exception $e) {
            echo " ❌ Error: " . $e->getMessage() . "\n";
            $failedCount++;
        }
    }

    echo "\n📊 Backfill Results:\n";
    echo "   ✅ Successfully created: $successCount visitors\n";
    echo "   ❌ Failed: $failedCount visitors\n";
    echo "   📈 Total processed: " . ($successCount + $failedCount) . "\n";

    // Verify final counts
    echo "\n🔍 Verifying final counts...\n";
    
    $eventCountQuery = "SELECT COUNT(*) as count FROM superpixel_resolution_log WHERE uuid IS NOT NULL AND uuid != '' AND uuid != 'null'";
    $eventResult = $mysqli->query($eventCountQuery);
    $eventCount = $eventResult->fetch_assoc()['count'];
    
    $visitorCountQuery = "SELECT COUNT(*) as count FROM superpixel_visitors";
    $visitorResult = $mysqli->query($visitorCountQuery);
    $visitorCount = $visitorResult->fetch_assoc()['count'];
    
    $uniqueUuidCountQuery = "SELECT COUNT(DISTINCT uuid) as count FROM superpixel_resolution_log WHERE uuid IS NOT NULL AND uuid != '' AND uuid != 'null'";
    $uniqueUuidResult = $mysqli->query($uniqueUuidCountQuery);
    $uniqueUuidCount = $uniqueUuidResult->fetch_assoc()['count'];
    
    echo "   📝 Events with UUIDs: $eventCount\n";
    echo "   👥 Unique UUIDs in events: $uniqueUuidCount\n";
    echo "   👤 Visitors in table: $visitorCount\n";
    
    if ($visitorCount >= $uniqueUuidCount) {
        echo "\n🎉 SUCCESS! All unique visitors are now properly tracked.\n";
    } else {
        $remaining = $uniqueUuidCount - $visitorCount;
        echo "\n⚠️  Still missing $remaining visitors. Some records may have failed.\n";
    }

} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
} finally {
    $mysqli->close();
}

echo "\n✅ Backfill process complete!\n";
?> 