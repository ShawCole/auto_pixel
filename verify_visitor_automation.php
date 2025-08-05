<?php
// Verify visitor automation is working correctly
// This script checks that triggers are in place and functioning

require_once 'config.php';

function verifyVisitorAutomation($client = null) {
    global $connection;
    
    echo "🔍 VERIFYING VISITOR AUTOMATION STATUS\n";
    echo "=" . str_repeat("=", 60) . "\n\n";
    
    // Get databases to check
    if ($client) {
        $databases = [$client];
    } else {
        // Get all client databases
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
        
        // 1. Check if trigger exists
        $trigger_check = $connection->query("SHOW TRIGGERS LIKE 'after_resolution_log_insert_visitor_update'");
        $has_trigger = ($trigger_check && $trigger_check->num_rows > 0);
        
        if ($has_trigger) {
            echo "   ✅ Trigger is active\n";
            $triggers_active++;
        } else {
            echo "   ❌ Trigger is MISSING!\n";
            $triggers_missing++;
        }
        
        // 2. Check visitor/event sync status
        $sync_query = "
            SELECT 
                (SELECT COUNT(DISTINCT uuid) FROM superpixel_resolution_log WHERE uuid IS NOT NULL AND uuid != '') as events_with_uuid,
                (SELECT COUNT(*) FROM superpixel_visitors) as total_visitors,
                (SELECT COUNT(*) FROM superpixel_resolution_log r 
                 LEFT JOIN superpixel_visitors v ON r.uuid = v.uuid 
                 WHERE r.uuid IS NOT NULL AND r.uuid != '' AND v.uuid IS NULL) as missing_visitors
        ";
        
        $sync_result = $connection->query($sync_query);
        if ($sync_result) {
            $sync_data = $sync_result->fetch_assoc();
            echo "   📊 Stats:\n";
            echo "      - Events with UUID: {$sync_data['events_with_uuid']}\n";
            echo "      - Total Visitors: {$sync_data['total_visitors']}\n";
            echo "      - Missing Visitors: {$sync_data['missing_visitors']}\n";
            
            if ($sync_data['missing_visitors'] > 0) {
                echo "   ⚠️  Warning: {$sync_data['missing_visitors']} events have UUIDs but no visitor records\n";
                $sync_issues++;
            }
        }
        
        // 3. Check recent activity (last 24 hours)
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
        
        // 4. Test visitor data quality
        $quality_query = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN event_count > 0 THEN 1 ELSE 0 END) as has_event_count,
                SUM(CASE WHEN url IS NOT NULL AND url != '' THEN 1 ELSE 0 END) as has_url,
                SUM(CASE WHEN event_type IS NOT NULL AND event_type != '' THEN 1 ELSE 0 END) as has_event_type,
                SUM(CASE WHEN business_email IS NOT NULL AND business_email != '' THEN 1 ELSE 0 END) as has_business_email,
                SUM(CASE WHEN npn IS NOT NULL AND npn != '' THEN 1 ELSE 0 END) as has_npn,
                SUM(CASE WHEN crd IS NOT NULL AND crd != '' THEN 1 ELSE 0 END) as has_crd
            FROM superpixel_visitors
            LIMIT 1
        ";
        
        $quality_result = $connection->query($quality_query);
        if ($quality_result && $quality_data = $quality_result->fetch_assoc()) {
            if ($quality_data['total'] > 0) {
                echo "   📋 Visitor Data Quality:\n";
                $event_count_pct = round(($quality_data['has_event_count'] / $quality_data['total']) * 100, 1);
                $url_pct = round(($quality_data['has_url'] / $quality_data['total']) * 100, 1);
                $event_type_pct = round(($quality_data['has_event_type'] / $quality_data['total']) * 100, 1);
                echo "      - Event counts tracked: {$event_count_pct}%\n";
                echo "      - Has URL data: {$url_pct}%\n";
                echo "      - Has event type: {$event_type_pct}%\n";
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
    
    if ($triggers_missing > 0) {
        echo "⚠️  ACTION REQUIRED:\n";
        echo "Some databases are missing the automation trigger.\n";
        echo "Run this command to fix:\n";
        echo "  php deploy_visitor_trigger_all.php --all\n\n";
    }
    
    if ($sync_issues > 0) {
        echo "⚠️  SYNC ISSUES DETECTED:\n";
        echo "Some databases have events without corresponding visitor records.\n";
        echo "This might indicate:\n";
        echo "  • Trigger was added after events were already processed\n";
        echo "  • Trigger failed for some events\n";
        echo "Run backfill script to fix historical data:\n";
        echo "  php backfill_missing_visitors.php\n\n";
    }
    
    if ($triggers_active == $total_checks && $sync_issues == 0) {
        echo "🎉 EXCELLENT! All databases have active triggers and data is in sync!\n";
    }
}

// Parse command line arguments
if (isset($argv[1])) {
    verifyVisitorAutomation($argv[1]);
} else {
    verifyVisitorAutomation();
}
?> 