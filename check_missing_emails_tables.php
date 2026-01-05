<?php
/**
 * Check which confirmed CLIENT databases are missing the superpixel_emails table
 * Only checks databases that are registered in pixel_sheets (actual clients)
 */

// Database configuration
$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';

$mysqli = new mysqli($host, $user, $pass);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== CONFIRMED CLIENT DATABASES SCHEMA CHECK ===\n\n";

// Get confirmed client databases from pixel_sheets table
$query = "
    SELECT DISTINCT client_name 
    FROM pixel.pixel_sheets 
    WHERE client_name IS NOT NULL 
      AND client_name != ''
      AND client_name NOT LIKE '%test%'
      AND client_name NOT LIKE '%Test%'
      AND client_name NOT LIKE '%TEST%'
    ORDER BY client_name
";

$result = $mysqli->query($query);
if (!$result) {
    die("Error finding client databases from pixel_sheets: " . $mysqli->error . "\n");
}

$confirmed_clients = [];
while ($row = $result->fetch_assoc()) {
    $confirmed_clients[] = $row['client_name'];
}

echo "Found " . count($confirmed_clients) . " confirmed clients in pixel_sheets:\n";
foreach ($confirmed_clients as $client) {
    echo "- $client\n";
}
echo "\n";

$databases_needing_emails_table = [];
$databases_with_complete_schema = [];
$missing_databases = [];

foreach ($confirmed_clients as $client) {
    echo "Checking client database: $client\n";
    
    // Check if database exists
    $db_check = $mysqli->query("SHOW DATABASES LIKE '$client'");
    if (!$db_check || $db_check->num_rows === 0) {
        echo "  ❌ DATABASE DOESN'T EXIST\n";
        $missing_databases[] = $client;
        echo "\n";
        continue;
    }
    
    // Check for required tables
    $tables_check = [
        'superpixel_resolution_log' => false,
        'superpixel_visitors' => false,
        'superpixel_emails' => false
    ];
    
    foreach ($tables_check as $table => $exists) {
        $check_result = $mysqli->query("SHOW TABLES FROM `$client` LIKE '$table'");
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
            $databases_with_complete_schema[] = $client;
            echo "  🎯 Status: COMPLETE SCHEMA\n";
        } else {
            $databases_needing_emails_table[] = $client;
            echo "  ⚠️  Status: NEEDS superpixel_emails table\n";
        }
    } else {
        echo "  💥 Status: MISSING CORE TABLES - needs full schema repair\n";
    }
    
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Total confirmed clients: " . count($confirmed_clients) . "\n";
echo "Clients with complete schema: " . count($databases_with_complete_schema) . "\n";
echo "Clients needing superpixel_emails table: " . count($databases_needing_emails_table) . "\n";
echo "Clients with missing databases: " . count($missing_databases) . "\n\n";

if (!empty($databases_needing_emails_table)) {
    echo "=== CLIENTS NEEDING superpixel_emails TABLE ===\n";
    foreach ($databases_needing_emails_table as $client) {
        echo "- $client\n";
    }
    echo "\nTo add superpixel_emails tables to these client databases, run:\n";
    echo "php add_emails_tables.php\n\n";
}

if (!empty($databases_with_complete_schema)) {
    echo "=== CLIENTS WITH COMPLETE SCHEMA (ready for NPN/CRD lookup) ===\n";
    foreach ($databases_with_complete_schema as $client) {
        echo "- $client\n";
    }
    echo "\n";
}

if (!empty($missing_databases)) {
    echo "=== CLIENTS WITH MISSING DATABASES ===\n";
    foreach ($missing_databases as $client) {
        echo "- $client (database doesn't exist)\n";
    }
    echo "\nThese clients are in pixel_sheets but their databases don't exist.\n";
    echo "You may need to create databases or clean up pixel_sheets.\n\n";
}

// Show SQL commands for the specific clients that need emails tables
if (!empty($databases_needing_emails_table)) {
    echo "=== SQL COMMANDS FOR CLIENT DATABASES NEEDING superpixel_emails ===\n";
    
    foreach ($databases_needing_emails_table as $client) {
        echo "-- Add superpixel_emails table to CLIENT: $client\n";
        echo "CREATE TABLE `$client`.superpixel_emails (\n";
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