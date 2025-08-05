<?php
/**
 * Add superpixel_emails tables to confirmed CLIENT databases that are missing them
 * Only processes databases that are registered in pixel_sheets (actual clients)
 */

// Database configuration
$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';

$mysqli = new mysqli($host, $user, $pass);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== ADDING MISSING superpixel_emails TABLES TO CLIENT DATABASES ===\n\n";

// Get confirmed client databases from pixel_sheets that are missing superpixel_emails
$query = "
    SELECT DISTINCT ps.client_name
    FROM pixel.pixel_sheets ps
    WHERE ps.client_name IS NOT NULL 
      AND ps.client_name != ''
      AND ps.client_name NOT LIKE '%test%'
      AND ps.client_name NOT LIKE '%Test%'
      AND ps.client_name NOT LIKE '%TEST%'
      AND ps.client_name NOT IN (
          SELECT DISTINCT TABLE_SCHEMA 
          FROM information_schema.TABLES 
          WHERE TABLE_NAME = 'superpixel_emails'
            AND TABLE_SCHEMA = ps.client_name
      )
    ORDER BY ps.client_name
";

$result = $mysqli->query($query);
if (!$result) {
    die("Error finding client databases needing superpixel_emails: " . $mysqli->error . "\n");
}

$clients_needing_emails = [];
while ($row = $result->fetch_assoc()) {
    // Verify the client database actually exists before adding to list
    $db_check = $mysqli->query("SHOW DATABASES LIKE '" . $row['client_name'] . "'");
    if ($db_check && $db_check->num_rows > 0) {
        $clients_needing_emails[] = $row['client_name'];
    } else {
        echo "⚠️  Client '{$row['client_name']}' is in pixel_sheets but database doesn't exist - skipping\n";
    }
}

if (empty($clients_needing_emails)) {
    echo "✅ All confirmed client databases already have superpixel_emails tables!\n";
    $mysqli->close();
    exit(0);
}

echo "Found " . count($clients_needing_emails) . " confirmed clients needing superpixel_emails tables:\n";
foreach ($clients_needing_emails as $client) {
    echo "- $client\n";
}
echo "\n";

// Create superpixel_emails table schema
$emails_table_sql = "
    CREATE TABLE IF NOT EXISTS `{CLIENT}`.superpixel_emails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        email_type ENUM('personal', 'business', 'deep_verified') NOT NULL,
        source_column VARCHAR(50),
        source_table ENUM('resolution_log', 'visitors') DEFAULT 'resolution_log',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_uuid_email (uuid, email),
        INDEX idx_email (email),
        INDEX idx_uuid (uuid),
        INDEX idx_email_type (email_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";

$successful_additions = 0;
$failed_additions = [];

foreach ($clients_needing_emails as $client) {
    echo "Adding superpixel_emails table to CLIENT: $client\n";
    
    // Check if client has required tables first
    $has_resolution = $mysqli->query("SHOW TABLES FROM `$client` LIKE 'superpixel_resolution_log'");
    $has_visitors = $mysqli->query("SHOW TABLES FROM `$client` LIKE 'superpixel_visitors'");
    
    if (!$has_resolution || $has_resolution->num_rows === 0 || 
        !$has_visitors || $has_visitors->num_rows === 0) {
        echo "  ⚠️  Warning: Client $client missing core tables (superpixel_resolution_log or superpixel_visitors)\n";
        echo "  ❌ Skipping superpixel_emails creation - fix core schema first\n\n";
        $failed_additions[] = $client . " (missing core tables)";
        continue;
    }
    
    // Replace {CLIENT} placeholder with actual client name
    $sql = str_replace('{CLIENT}', $client, $emails_table_sql);
    
    if ($mysqli->query($sql)) {
        echo "  ✅ Successfully created superpixel_emails table for client $client\n";
        $successful_additions++;
        
        // Verify the table was created
        $verify_result = $mysqli->query("SHOW TABLES FROM `$client` LIKE 'superpixel_emails'");
        if ($verify_result && $verify_result->num_rows > 0) {
            echo "  ✅ Verified: superpixel_emails table exists for client $client\n";
            
            // Show table structure to confirm
            $desc_result = $mysqli->query("DESCRIBE `$client`.superpixel_emails");
            if ($desc_result) {
                $column_count = $desc_result->num_rows;
                echo "  📊 Table has $column_count columns and proper indexes\n";
            }
        } else {
            echo "  ⚠️  Warning: Could not verify table creation for client $client\n";
        }
    } else {
        echo "  ❌ Failed to create superpixel_emails table for client $client\n";
        echo "     Error: " . $mysqli->error . "\n";
        $failed_additions[] = $client . " (" . $mysqli->error . ")";
    }
    
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Confirmed clients processed: " . count($clients_needing_emails) . "\n";
echo "Successful additions: $successful_additions\n";
echo "Failed additions: " . count($failed_additions) . "\n";

if (!empty($failed_additions)) {
    echo "\nFailed clients:\n";
    foreach ($failed_additions as $failure) {
        echo "- $failure\n";
    }
    echo "\nYou may need to manually check these client databases for issues.\n";
}

echo "\n=== NEXT STEPS ===\n";
echo "1. Run 'php check_missing_emails_tables.php' to verify all client tables were added\n";
echo "2. Update process_visitor_emails.php to use the normalized match_emails table\n";
echo "3. Test the enhanced NPN/CRD lookup system\n";
echo "4. Consider backfilling emails for existing client data:\n";
echo "   php backfill_emails_from_events.php [client_name]\n";

$mysqli->close();
?> 