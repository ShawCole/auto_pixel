<?php
// Script to backfill missing visitors from existing events in AcquireUp database

header('Content-Type: application/json');

$dbHost = '34.31.66.104';
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
    // Get all unique UUIDs from events that are missing from visitors table
    // Use a subquery to get the latest event data for each UUID
    $query = "
        SELECT r1.uuid, r1.first_name, r1.last_name, r1.personal_address, r1.personal_city, 
               r1.personal_state, r1.personal_zip, r1.personal_zip4, r1.age_range, r1.children, 
               r1.gender, r1.homeowner, r1.married, r1.net_worth, r1.income_range, r1.direct_number, 
               r1.direct_number_dnc, r1.mobile_phone, r1.mobile_phone_dnc, r1.personal_phone, 
               r1.personal_phone_dnc, r1.business_email, r1.personal_emails, r1.deep_verified_emails, 
               r1.sha256_personal_email, r1.sha256_business_email, r1.job_title, r1.headline, 
               r1.department, r1.seniority_level, r1.inferred_years_experience, r1.company_name_history, 
               r1.job_title_history, r1.education_history, r1.company_address, r1.company_description, 
               r1.company_domain, r1.company_employee_count, r1.company_name, r1.company_phone, 
               r1.company_revenue, r1.company_sic, r1.company_naics, r1.company_city, r1.company_state, 
               r1.company_zip, r1.company_industry, r1.linkedin_url, r1.twitter_url, r1.facebook_url, 
               r1.social_connections, r1.skills, r1.interests, r1.skiptrace_match_score, r1.skiptrace_name, 
               r1.skiptrace_address, r1.skiptrace_city, r1.skiptrace_state, r1.skiptrace_zip, 
               r1.skiptrace_landline_numbers, r1.skiptrace_wireless_numbers, r1.skiptrace_credit_rating, 
               r1.skiptrace_dnc, r1.skiptrace_exact_age, r1.skiptrace_ethnic_code, r1.skiptrace_language_code, 
               r1.skiptrace_ip, r1.skiptrace_b2b_address, r1.skiptrace_b2b_phone, r1.skiptrace_b2b_source, 
               r1.skiptrace_b2b_website, r1.valid_phones, r1.hem_sha256, r1.url, r1.element, r1.percentage, 
               r1.referrer, r1.timestamp as event_timestamp, r1.event_type,
               counts.event_count
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
