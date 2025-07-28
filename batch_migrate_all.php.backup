<?php
require_once 'vendor/autoload.php';

// Database connection
$host = '34.31.66.104';
$username = 'root';
$password = 'AccuPoint01!';

echo "🔄 BATCH MIGRATION STARTED\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "=====================================\n\n";

// Databases that need migration (from the query results)
$databases_to_migrate = [
    'AcquireUp', 'Country_Life', 'DEBUG_DATABASE_CREATION', 'TEST_CLIENT',
    'TEST_CLIENT_1111', 'TEST_CLIENT_1234', 'TEST_CLIENT_1235', 'TEST_CLIENT_17',
    'TEST_CLIENT_19', 'TEST_CLIENT_2', 'TEST_CLIENT_222', 'TEST_CLIENT_3',
    'TEST_CLIENT_333', 'TEST_CLIENT_444', 'TEST_CLIENT_555', 'TEST_CLIENT_666',
    'TEST_CLIENT_777', 'TEST_CLIENT_888', 'TEST_CLIENT_999', 'TEST_DB_ONLY',
    'TEST_LOCAL', 'TEST_MANUAL', 'TEST_NEW_COLUMNS', 'TEST_PROPER_JSON',
    'TEST_TEST', 'accupoint_solutions_new', 'env_test_1753333036', 'test',
    'test1', 'test_2', 'test_88', 'test_v66', 'test_v77', 'working_test',
    'template' // Include template database
];

$success_count = 0;
$failure_count = 0;

foreach ($databases_to_migrate as $db_name) {
    echo "[" . ($success_count + $failure_count + 1) . "/" . count($databases_to_migrate) . "] 🔧 MIGRATING: $db_name\n";
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if columns exist before adding them
        $columns_to_add_visitors = [];
        $columns_to_add_events = [];
        
        // Check superpixel_visitors columns
        $visitor_columns = ['url', 'element', 'percentage', 'referrer', 'event_timestamp', 'event_type'];
        foreach ($visitor_columns as $col) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM superpixel_visitors LIKE ?");
            $stmt->execute([$col]);
            if ($stmt->rowCount() == 0) {
                $columns_to_add_visitors[] = $col;
            }
        }
        
        // Check superpixel_resolution_log columns
        $event_columns = ['npn', 'crd'];
        foreach ($event_columns as $col) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM superpixel_resolution_log LIKE ?");
            $stmt->execute([$col]);
            if ($stmt->rowCount() == 0) {
                $columns_to_add_events[] = $col;
            }
        }
        
        $changes_made = false;
        
        // Add missing columns to superpixel_visitors
        if (!empty($columns_to_add_visitors)) {
            $alter_statements = [];
            foreach ($columns_to_add_visitors as $col) {
                switch ($col) {
                    case 'url':
                        $alter_statements[] = "ADD COLUMN `url` TEXT CHARACTER SET utf32 COLLATE utf32_general_ci";
                        break;
                    case 'element':
                        $alter_statements[] = "ADD COLUMN `element` TEXT CHARACTER SET utf32 COLLATE utf32_general_ci";
                        break;
                    case 'percentage':
                        $alter_statements[] = "ADD COLUMN `percentage` INT DEFAULT NULL";
                        break;
                    case 'referrer':
                        $alter_statements[] = "ADD COLUMN `referrer` TEXT CHARACTER SET utf32 COLLATE utf32_general_ci";
                        break;
                    case 'event_timestamp':
                        $alter_statements[] = "ADD COLUMN `event_timestamp` VARCHAR(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL";
                        break;
                    case 'event_type':
                        $alter_statements[] = "ADD COLUMN `event_type` VARCHAR(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL";
                        break;
                }
            }
            
            if (!empty($alter_statements)) {
                $sql = "ALTER TABLE superpixel_visitors " . implode(', ', $alter_statements);
                $pdo->exec($sql);
                echo "  ✅ Added columns to superpixel_visitors: " . implode(', ', $columns_to_add_visitors) . "\n";
                $changes_made = true;
            }
        }
        
        // Add missing columns to superpixel_resolution_log
        if (!empty($columns_to_add_events)) {
            $alter_statements = [];
            foreach ($columns_to_add_events as $col) {
                switch ($col) {
                    case 'npn':
                        $alter_statements[] = "ADD COLUMN `npn` VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL";
                        break;
                    case 'crd':
                        $alter_statements[] = "ADD COLUMN `crd` VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL";
                        break;
                }
            }
            
            if (!empty($alter_statements)) {
                $sql = "ALTER TABLE superpixel_resolution_log " . implode(', ', $alter_statements);
                $pdo->exec($sql);
                echo "  ✅ Added columns to superpixel_resolution_log: " . implode(', ', $columns_to_add_events) . "\n";
                $changes_made = true;
            }
        }
        
        if (!$changes_made) {
            echo "  ℹ️  No changes needed - already up to date\n";
        }
        
        $success_count++;
        echo "  ✅ MIGRATION COMPLETED\n";
        
    } catch (Exception $e) {
        $failure_count++;
        echo "  ❌ MIGRATION FAILED: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "=====================================\n";
echo "🎉 BATCH MIGRATION COMPLETED! 🎉\n";
echo "📊 Results: $success_count/$" . count($databases_to_migrate) . " databases migrated successfully\n";
if ($failure_count > 0) {
    echo "⚠️  $failure_count database(s) failed to migrate\n";
}
echo "⏱️  Total time: " . number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) . "s\n";
?> 