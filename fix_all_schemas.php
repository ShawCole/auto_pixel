<?php
/**
 * Comprehensive Schema Fix Script
 * Fixes all known schema inconsistencies across client databases
 */

// Database configuration
$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';

// Connect to database
$mysqli = new mysqli($host, $user, $pass);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== COMPREHENSIVE SCHEMA FIX ===\n\n";

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

echo "Found " . count($client_databases) . " client databases\n\n";

foreach ($client_databases as $db) {
    echo "=== Processing database: $db ===\n";
    
    // Step 1: Fix superpixel_resolution_log schema
    echo "1. Checking superpixel_resolution_log schema...\n";
    
    // Get current columns
    $existing_columns = [];
    $result = $mysqli->query("DESCRIBE `$db`.superpixel_resolution_log");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
    }
    
    // Add missing columns one by one with proper error handling
    $columns_to_add = [
        'timestamp' => "ADD COLUMN timestamp varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER referrer",
        'title' => "ADD COLUMN title text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER timestamp",
        'url' => "ADD COLUMN url text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER title",
        'valid_phones' => "ADD COLUMN valid_phones text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER url",
        'element' => "ADD COLUMN element text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER valid_phones",
        'percentage' => "ADD COLUMN percentage varchar(10) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER element",
        'npn' => "ADD COLUMN npn varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER created_at",
        'crd' => "ADD COLUMN crd varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER npn"
    ];
    
    foreach ($columns_to_add as $column => $alter_sql) {
        if (!in_array($column, $existing_columns)) {
            $full_sql = "ALTER TABLE `$db`.superpixel_resolution_log $alter_sql";
            echo "  Adding column '$column'... ";
            
            if ($mysqli->query($full_sql)) {
                echo "✅ Success\n";
            } else {
                echo "❌ Error: " . $mysqli->error . "\n";
            }
        } else {
            echo "  Column '$column' already exists ✓\n";
        }
    }
    
    // Handle elements vs element issue
    if (in_array('elements', $existing_columns) && !in_array('element', $existing_columns)) {
        echo "  Fixing 'elements' → 'element' column name... ";
        $sql = "ALTER TABLE `$db`.superpixel_resolution_log CHANGE elements element text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL";
        if ($mysqli->query($sql)) {
            echo "✅ Success\n";
        } else {
            echo "❌ Error: " . $mysqli->error . "\n";
        }
    }
    
    // Add indexes for new columns
    $indexes_to_add = [
        'idx_npn' => "ADD INDEX idx_npn (npn)",
        'idx_crd' => "ADD INDEX idx_crd (crd)",
        'idx_url' => "ADD INDEX idx_url (url(255))"
    ];
    
    foreach ($indexes_to_add as $index_name => $index_sql) {
        echo "  Adding index '$index_name'... ";
        $full_sql = "ALTER TABLE `$db`.superpixel_resolution_log $index_sql";
        $result = $mysqli->query($full_sql);
        if ($result || strpos($mysqli->error, "Duplicate key name") !== false) {
            echo "✅ Success\n";
        } else {
            echo "❌ Error: " . $mysqli->error . "\n";
        }
    }
    
    // Step 2: Fix superpixel_visitors schema
    echo "\n2. Checking superpixel_visitors schema...\n";
    
    // Get current visitor columns
    $existing_visitor_columns = [];
    $result = $mysqli->query("DESCRIBE `$db`.superpixel_visitors");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $existing_visitor_columns[] = $row['Field'];
        }
    }
    
    // Add missing visitor columns
    $visitor_columns_to_add = [
        'url' => "ADD COLUMN url text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER valid_phones",
        'element' => "ADD COLUMN element text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER url",
        'percentage' => "ADD COLUMN percentage int DEFAULT NULL AFTER element",
        'referrer' => "ADD COLUMN referrer text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER percentage",
        'event_timestamp' => "ADD COLUMN event_timestamp varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER referrer",
        'event_type' => "ADD COLUMN event_type varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER event_timestamp",
        'npn' => "ADD COLUMN npn varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER event_type",
        'crd' => "ADD COLUMN crd varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL AFTER npn"
    ];
    
    foreach ($visitor_columns_to_add as $column => $alter_sql) {
        if (!in_array($column, $existing_visitor_columns)) {
            $full_sql = "ALTER TABLE `$db`.superpixel_visitors $alter_sql";
            echo "  Adding visitor column '$column'... ";
            
            if ($mysqli->query($full_sql)) {
                echo "✅ Success\n";
            } else {
                echo "❌ Error: " . $mysqli->error . "\n";
            }
        } else {
            echo "  Visitor column '$column' already exists ✓\n";
        }
    }
    
    // Step 3: Create superpixel_emails table if missing
    echo "\n3. Checking superpixel_emails table...\n";
    
    $result = $mysqli->query("SHOW TABLES FROM `$db` LIKE 'superpixel_emails'");
    if (!$result || $result->num_rows === 0) {
        echo "  Creating superpixel_emails table... ";
        
        $create_emails_sql = "
            CREATE TABLE `$db`.superpixel_emails (
                id int AUTO_INCREMENT PRIMARY KEY,
                uuid varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci NOT NULL,
                email varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci NOT NULL,
                email_type ENUM('personal', 'business', 'deep_verified') NOT NULL,
                source_column varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci NOT NULL,
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_uuid_email (uuid, email),
                KEY idx_uuid (uuid),
                KEY idx_email (email),
                KEY idx_email_type (email_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf32
        ";
        
        if ($mysqli->query($create_emails_sql)) {
            echo "✅ Success\n";
        } else {
            echo "❌ Error: " . $mysqli->error . "\n";
        }
    } else {
        echo "  superpixel_emails table already exists ✓\n";
    }
    
    echo "\n✅ Database '$db' processing complete\n";
    echo "----------------------------------------\n\n";
}

echo "=== SCHEMA FIX COMPLETE ===\n";
echo "All databases have been processed.\n";
echo "Next steps:\n";
echo "1. Verify NPN/CRD triggers are working\n";
echo "2. Run visitor consistency check\n";
echo "3. Test pixel generation with fixed schemas\n";

$mysqli->close();
?> 