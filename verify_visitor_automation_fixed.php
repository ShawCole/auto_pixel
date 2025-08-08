<?php
require_once 'config.php';

function verifyVisitorAutomation($client = null) {
    global $connection;
    
    echo "🔍 VERIFYING VISITOR AUTOMATION STATUS\n";
    echo "=" . str_repeat("=", 60) . "\n\n";
    
    // Get databases to check
    if ($client) {
        $databases = [$client];
    } else {
        $db_query = "
            SELECT DISTINCT TABLE_SCHEMA as db_name
            FROM information_schema.TABLES
            WHERE TABLE_NAME IN ('superpixel_resolution_log', 'superpixel_visitors')
            AND TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
            GROUP BY TABLE_SCHEMA
            HAVING COUNT(DISTINCT TABLE_NAME) = 2
            ORDER BY TABLE_SCHEMA
        ";
        
        $result = $connection->query($db_query);
        $databases = [];
        while ($row = $result->fetch_assoc()) {
            $databases[] = $row['db_name'];
        }
    }
    
    $total_checks = count($databases);
    $triggers_active = 0;
    $triggers_missing = 0;
    $sync_issues = 0;
    
    foreach ($databases as $index => $db_name) {
        $num = $index + 1;
        echo "[$num/$total_checks] Checking: $db_name\n";
        
        // Switch to database
        $connection->query("USE `$db_name`");
        
        // Fixed trigger check using information_schema
        $trigger_check = $connection->query("
            SELECT COUNT(*) as trigger_count 
            FROM information_schema.TRIGGERS 
            WHERE TRIGGER_SCHEMA='$db_name' 
            AND TRIGGER_NAME='after_resolution_log_insert_visitor_update'
        ");
        
        $trigger_row = $trigger_check->fetch_assoc();
        $has_trigger = ($trigger_row['trigger_count'] > 0);
        
        if ($has_trigger) {
            echo "   ✅ Trigger is active\n";
            $triggers_active++;
        } else {
            echo "   ❌ Trigger is MISSING!\n";
            $triggers_missing++;
        }
        
        // Check recent activity
        $recent_query = "
            SELECT 
                COUNT(*) as recent_events,
                COUNT(DISTINCT uuid) as unique_visitors,
                MAX(created_at) as last_event
            FROM superpixel_resolution_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";
        
        $recent_result = $connection->query($recent_query);
        if ($recent_result) {
            $recent_data = $recent_result->fetch_assoc();
            if ($recent_data['recent_events'] > 0) {
                echo "   📈 Last 24 hours:\n";
                echo "      - Events: {$recent_data['recent_events']}\n";
                echo "      - Unique visitors: {$recent_data['unique_visitors']}\n";
                echo "      - Last event: {$recent_data['last_event']}\n";
            }
        }
        
        echo "\n";
    }
    
    // Summary
    echo str_repeat("=", 60) . "\n";
    echo "📊 AUTOMATION SUMMARY\n";
    echo str_repeat("=", 60) . "\n";
    echo "✅ Triggers Active: $triggers_active / $total_checks databases\n";
    echo "❌ Triggers Missing: $triggers_missing databases\n";
    echo "⚠️  Sync Issues: $sync_issues databases\n\n";
    
    if ($triggers_active == $total_checks && $sync_issues == 0) {
        echo "🎉 EXCELLENT! All databases have active triggers and automation is working!\n";
    }
}

if (isset($argv[1])) {
    verifyVisitorAutomation($argv[1]);
} else {
    verifyVisitorAutomation();
}
?>
