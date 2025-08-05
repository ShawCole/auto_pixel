<?php
/**
 * Debug NPN/CRD System - Find out why triggers and procedures are failing
 */

$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';

$mysqli = new mysqli($host, $user, $pass);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== NPN/CRD SYSTEM DIAGNOSTIC ===\n\n";

// Test with a known database (or create test one)
$test_db = 'EmailSystem_Test_001';

// Check if database exists
$result = $mysqli->query("SHOW DATABASES LIKE '$test_db'");
if ($result->num_rows === 0) {
    echo "Database '$test_db' doesn't exist. Let's check an existing one...\n";
    
    // Find any client database
    $result = $mysqli->query("
        SELECT DISTINCT TABLE_SCHEMA 
        FROM information_schema.TABLES 
        WHERE TABLE_NAME = 'superpixel_resolution_log'
          AND TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', 'pixel')
        LIMIT 1
    ");
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $test_db = $row['TABLE_SCHEMA'];
        echo "Using existing database: $test_db\n\n";
    } else {
        die("No client databases found!\n");
    }
}

echo "Testing NPN/CRD system with database: $test_db\n\n";

// 1. Check if superpixel_emails table exists
echo "1. Checking superpixel_emails table...\n";
$result = $mysqli->query("SHOW TABLES FROM `$test_db` LIKE 'superpixel_emails'");
if ($result->num_rows === 0) {
    echo "   ❌ superpixel_emails table MISSING\n";
} else {
    echo "   ✅ superpixel_emails table exists\n";
    
    // Check table structure
    $result = $mysqli->query("DESCRIBE `$test_db`.superpixel_emails");
    echo "   Columns: ";
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    echo implode(', ', $columns) . "\n";
}

// 2. Check if parse_visitor_emails procedure exists
echo "\n2. Checking parse_visitor_emails procedure...\n";
$result = $mysqli->query("SHOW PROCEDURE STATUS WHERE Db = '$test_db' AND Name = 'parse_visitor_emails'");
if ($result->num_rows === 0) {
    echo "   ❌ parse_visitor_emails procedure MISSING\n";
    
    // Try to create it and see the exact error
    echo "   Attempting to create procedure to see error...\n";
    
    $procedure_sql = "
        DROP PROCEDURE IF EXISTS `$test_db`.parse_visitor_emails;
        
        DELIMITER ;;
        CREATE PROCEDURE `$test_db`.parse_visitor_emails(
            IN p_uuid VARCHAR(100),
            IN p_email_string TEXT,
            IN p_email_type ENUM('personal', 'business', 'deep_verified'),
            IN p_source_column VARCHAR(50)
        )
        BEGIN
            DECLARE email_item VARCHAR(255);
            DECLARE remaining_string TEXT;
            DECLARE comma_pos INT;
            
            SET remaining_string = TRIM(p_email_string);
            IF remaining_string IS NULL OR remaining_string = '' THEN
                LEAVE parse_emails;
            END IF;
            
            parse_emails: WHILE LENGTH(remaining_string) > 0 DO
                SET comma_pos = LOCATE(',', remaining_string);
                IF comma_pos = 0 THEN
                    SET email_item = TRIM(remaining_string);
                    SET remaining_string = '';
                ELSE
                    SET email_item = TRIM(SUBSTRING(remaining_string, 1, comma_pos - 1));
                    SET remaining_string = TRIM(SUBSTRING(remaining_string, comma_pos + 1));
                END IF;
                
                IF email_item REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\\\.[A-Za-z]{2,}$' THEN
                    INSERT IGNORE INTO `$test_db`.superpixel_emails 
                    (uuid, email, email_type, source_column) 
                    VALUES (p_uuid, email_item, p_email_type, p_source_column);
                END IF;
            END WHILE parse_emails;
        END;;
        DELIMITER ;
    ";
    
    if (!$mysqli->query($procedure_sql)) {
        echo "   ❌ PROCEDURE CREATION ERROR: " . $mysqli->error . "\n";
    } else {
        echo "   ✅ Procedure created successfully\n";
    }
} else {
    echo "   ✅ parse_visitor_emails procedure exists\n";
}

// 3. Check if triggers exist
echo "\n3. Checking email parsing triggers...\n";
$triggers = ['after_resolution_log_insert', 'after_visitors_insert', 'after_visitors_update'];

foreach ($triggers as $trigger_name) {
    $result = $mysqli->query("SHOW TRIGGERS FROM `$test_db` WHERE Trigger = '$trigger_name'");
    if ($result->num_rows === 0) {
        echo "   ❌ Trigger '$trigger_name' MISSING\n";
    } else {
        echo "   ✅ Trigger '$trigger_name' exists\n";
    }
}

// 4. Check if accupoint_solutions.match_emails exists (for NPN/CRD lookup)
echo "\n4. Checking NPN/CRD lookup table...\n";
$result = $mysqli->query("SHOW TABLES FROM accupoint_solutions LIKE 'match_emails'");
if ($result->num_rows === 0) {
    echo "   ❌ accupoint_solutions.match_emails table MISSING - NPN/CRD lookup impossible!\n";
} else {
    echo "   ✅ accupoint_solutions.match_emails table exists\n";
    
    // Check row count
    $result = $mysqli->query("SELECT COUNT(*) as count FROM accupoint_solutions.match_emails");
    $count = $result->fetch_assoc()['count'];
    echo "   Contains: " . number_format($count) . " rows\n";
}

// 5. Test the PHP NPN/CRD lookup script
echo "\n5. Testing PHP NPN/CRD script...\n";
if (file_exists('process_visitor_emails.php')) {
    echo "   ✅ process_visitor_emails.php exists\n";
    
    // Test with a dummy UUID
    require_once 'process_visitor_emails.php';
    
    // Create a test UUID with some emails
    $test_uuid = 'test-uuid-' . time();
    $test_email = 'test@example.com';
    
    // Insert test data
    $mysqli->query("USE `$test_db`");
    $mysqli->query("INSERT IGNORE INTO superpixel_emails (uuid, email, email_type, source_column) VALUES ('$test_uuid', '$test_email', 'business', 'business_email')");
    
    // Test the function
    echo "   Testing processVisitorEmails function...\n";
    $result = processVisitorEmails($test_db, $test_uuid, false, true);
    echo "   Result: " . json_encode($result) . "\n";
    
    // Cleanup
    $mysqli->query("DELETE FROM superpixel_emails WHERE uuid = '$test_uuid'");
    
} else {
    echo "   ❌ process_visitor_emails.php MISSING\n";
}

echo "\n=== DIAGNOSIS COMPLETE ===\n";
echo "The above results show exactly what's broken in your NPN/CRD system.\n";

$mysqli->close();
?> 