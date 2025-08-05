<?php
/**
 * Check which client databases are missing the superpixel_emails table
 * This script identifies databases that need the updated 3-table schema
 */

// Database configuration
$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';

$mysqli = new mysqli($host, $user, $pass);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== CLIENT DATABASES SCHEMA CHECK ===\n\n";

// Get all client databases that have pixel tables
$query = "
    SELECT DISTINCT TABLE_SCHEMA as database_name
    FROM information_schema.TABLES 
    WHERE TABLE_NAME IN ('superpixel_resolution_log', 'superpixel_visitors')
      AND TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', 'pixel', 'accupoint_solutions')
    ORDER BY TABLE_SCHEMA
";

$result = $mysqli->query($query);
if (!$result) {
    die("Error finding client databases: " . $mysqli->error . "\n");
}

$client_databases = [];
while ($row = $result->fetch_assoc()) {
    $client_databases[] = $row['database_name'];
}

echo "Found " . count($client_databases) . " client databases with pixel tables:\n\n";

$databases_needing_emails_table = [];
$databases_with_complete_schema = [];
$problematic_databases = [];

foreach ($client_databases as $db) {
    echo "Checking database: $db\n";
    
    // Check for required tables
    $tables_check = [
        'superpixel_resolution_log' => false,
        'superpixel_visitors' => false,
        'superpixel_emails' => false
    ];
    
    foreach ($tables_check as $table => $exists) {
        $check_result = $mysqli->query("SHOW TABLES FROM `$db` LIKE '$table'");
        if ($check_result && $check_result->num_rows > 0) {
            $tables_check[$table] = true;
            echo "  ✅ $table\n";
        } else {
            echo "  ❌ $table MISSING\n";
        }
    }
    
    // Categorize the database
    if ($tables_check['superpixel_resolution_log'] && $tables_check['superpixel_visitors']) {
        if ($tables_check['superpixel_emails']) {
            $databases_with_complete_schema[] = $db;
            echo "  🎯 Status: COMPLETE SCHEMA\n";
        } else {
            $databases_needing_emails_table[] = $db;
            echo "  ⚠️  Status: NEEDS superpixel_emails table\n";
        }
    } else {
        $problematic_databases[] = $db;
        echo "  💥 Status: MISSING CORE TABLES\n";
    }
    
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Total client databases: " . count($client_databases) . "\n";
echo "Databases with complete schema: " . count($databases_with_complete_schema) . "\n";
echo "Databases needing superpixel_emails table: " . count($databases_needing_emails_table) . "\n";
echo "Databases with missing core tables: " . count($problematic_databases) . "\n\n";

if (!empty($databases_needing_emails_table)) {
    echo "=== DATABASES NEEDING superpixel_emails TABLE ===\n";
    foreach ($databases_needing_emails_table as $db) {
        echo "- $db\n";
    }
    echo "\nTo add superpixel_emails tables to these databases, run:\n";
    echo "php add_emails_tables.php\n\n";
}

if (!empty($databases_with_complete_schema)) {
    echo "=== DATABASES WITH COMPLETE SCHEMA ===\n";
    foreach ($databases_with_complete_schema as $db) {
        echo "- $db\n";
    }
    echo "\n";
}

if (!empty($problematic_databases)) {
    echo "=== PROBLEMATIC DATABASES (missing core tables) ===\n";
    foreach ($problematic_databases as $db) {
        echo "- $db\n";
    }
    echo "\nThese databases may need full schema repair with fix_all_schemas.php\n\n";
}

// Generate commands for manual fixing if needed
if (!empty($databases_needing_emails_table)) {
    echo "=== MANUAL SQL COMMANDS ===\n";
    echo "If you prefer to add superpixel_emails tables manually:\n\n";
    
    foreach ($databases_needing_emails_table as $db) {
        echo "-- Add superpixel_emails table to $db\n";
        echo "CREATE TABLE `$db`.superpixel_emails (\n";
        echo "    id INT AUTO_INCREMENT PRIMARY KEY,\n";
        echo "    uuid VARCHAR(100) NOT NULL,\n";
        echo "    email VARCHAR(255) NOT NULL,\n";
        echo "    email_type ENUM('personal', 'business', 'deep_verified') NOT NULL,\n";
        echo "    source_column VARCHAR(50),\n";
        echo "    source_table ENUM('resolution_log', 'visitors') DEFAULT 'resolution_log',\n";
        echo "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n";
        echo "    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
        echo "    UNIQUE KEY unique_uuid_email (uuid, email),\n";
        echo "    INDEX idx_email (email),\n";
        echo "    INDEX idx_uuid (uuid),\n";
        echo "    INDEX idx_email_type (email_type)\n";
        echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";
    }
}

$mysqli->close();
?> 