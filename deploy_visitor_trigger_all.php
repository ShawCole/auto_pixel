<?php
// Deploy visitor automation trigger to all client databases
// This script will apply the trigger to all databases that have the required tables

require_once 'config.php';

function deployTriggerToAllDatabases() {
    global $connection;
    
    echo "🚀 DEPLOYING VISITOR AUTOMATION TRIGGER TO ALL DATABASES\n";
    echo "=" . str_repeat("=", 60) . "\n\n";
    
    // Get the trigger SQL
    $trigger_sql = file_get_contents('create_visitor_automation_trigger.sql');
    if (!$trigger_sql) {
        echo "❌ Could not read trigger SQL file\n";
        exit(1);
    }
    
    // Get all databases with the required tables
    $db_query = "
        SELECT SCHEMA_NAME as db_name
        FROM information_schema.SCHEMATA
        WHERE SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
        AND SCHEMA_NAME IN (
            SELECT DISTINCT TABLE_SCHEMA
            FROM information_schema.TABLES
            WHERE TABLE_NAME IN ('superpixel_resolution_log', 'superpixel_visitors')
            GROUP BY TABLE_SCHEMA
            HAVING COUNT(DISTINCT TABLE_NAME) = 2
        )
        ORDER BY SCHEMA_NAME
    ";
    
    $result = $connection->query($db_query);
    if (!$result) {
        echo "❌ Failed to get database list: " . $connection->error . "\n";
        exit(1);
    }
    
    $databases = [];
    while ($row = $result->fetch_assoc()) {
        $databases[] = $row['db_name'];
    }
    
    echo "📊 Found " . count($databases) . " databases with required tables:\n";
    foreach ($databases as $db) {
        echo "   • $db\n";
    }
    echo "\n";
    
    $success_count = 0;
    $failed_count = 0;
    $skipped_count = 0;
    $failed_dbs = [];
    
    foreach ($databases as $index => $db_name) {
        $num = $index + 1;
        echo "[$num/" . count($databases) . "] Processing: $db_name\n";
        
        // Switch to database
        if (!$connection->query("USE `$db_name`")) {
            echo "   ❌ Failed to switch to database: " . $connection->error . "\n";
            $failed_count++;
            $failed_dbs[] = $db_name;
            continue;
        }
        
        // Check if trigger already exists
        $trigger_check = $connection->query("SHOW TRIGGERS LIKE 'after_resolution_log_insert_visitor_update'");
        if ($trigger_check && $trigger_check->num_rows > 0) {
            echo "   ⚠️  Trigger already exists. Dropping and recreating...\n";
            $connection->query("DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update");
        }
        
        // Deploy the trigger
        if ($connection->multi_query($trigger_sql)) {
            // Clear any remaining results
            while ($connection->more_results() && $connection->next_result()) {
                if ($result = $connection->store_result()) {
                    $result->free();
                }
            }
            echo "   ✅ Trigger deployed successfully!\n";
            $success_count++;
            
            // Verify trigger was created
            $verify = $connection->query("SHOW TRIGGERS LIKE 'after_resolution_log_insert_visitor_update'");
            if (!$verify || $verify->num_rows == 0) {
                echo "   ⚠️  Warning: Trigger verification failed\n";
            }
        } else {
            echo "   ❌ Failed to deploy trigger: " . $connection->error . "\n";
            $failed_count++;
            $failed_dbs[] = $db_name;
        }
        
        echo "\n";
    }
    
    // Summary
    echo str_repeat("=", 60) . "\n";
    echo "📊 DEPLOYMENT SUMMARY\n";
    echo str_repeat("=", 60) . "\n";
    echo "✅ Successful: $success_count databases\n";
    echo "❌ Failed: $failed_count databases\n";
    echo "⏭️  Skipped: $skipped_count databases\n";
    
    if (count($failed_dbs) > 0) {
        echo "\n⚠️  Failed databases:\n";
        foreach ($failed_dbs as $db) {
            echo "   • $db\n";
        }
    }
    
    echo "\n";
    if ($success_count > 0) {
        echo "🎉 Visitor automation is now active for $success_count databases!\n";
        echo "The trigger will automatically:\n";
        echo "  • Create new visitors when events arrive with new UUIDs\n";
        echo "  • Update existing visitors with new event data\n";
        echo "  • Preserve business emails over personal emails\n";
        echo "  • Track event counts and last seen timestamps\n";
        echo "  • Update 'last' fields (URL, element, percentage, etc.)\n";
    }
}

// Add option to deploy to specific database or all
if (isset($argv[1])) {
    $target = $argv[1];
    if ($target === '--all') {
        deployTriggerToAllDatabases();
    } else {
        // Deploy to specific database
        echo "Deploying to specific database: $target\n";
        $connection->query("USE `$target`");
        
        $trigger_sql = file_get_contents('create_visitor_automation_trigger.sql');
        
        // Drop existing trigger
        $connection->query("DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update");
        
        // Deploy new trigger
        if ($connection->multi_query($trigger_sql)) {
            while ($connection->more_results() && $connection->next_result()) {
                if ($result = $connection->store_result()) {
                    $result->free();
                }
            }
            echo "✅ Trigger deployed successfully to $target!\n";
        } else {
            echo "❌ Failed to deploy trigger: " . $connection->error . "\n";
        }
    }
} else {
    echo "Usage:\n";
    echo "  Deploy to all databases:  php deploy_visitor_trigger_all.php --all\n";
    echo "  Deploy to specific db:    php deploy_visitor_trigger_all.php DATABASE_NAME\n";
    echo "\n";
    echo "Example:\n";
    echo "  php deploy_visitor_trigger_all.php AcquireUp\n";
    echo "  php deploy_visitor_trigger_all.php --all\n";
}
?> 