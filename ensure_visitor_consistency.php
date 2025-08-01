<?php
// Ensure Visitor Consistency Across All Client Databases
// This script audits and fixes visitor records for all client databases

require_once __DIR__ . '/visitor_upsert_functions.php';

$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

// Configuration
$DRY_RUN = isset($argv[1]) && $argv[1] === '--dry-run';
$SPECIFIC_CLIENT = isset($argv[1]) && $argv[1] !== '--dry-run' ? $argv[1] : null;
$BATCH_SIZE = 1000; // Process in batches to avoid memory issues

echo "=== Visitor Consistency Audit & Fix ===\n";
echo "Mode: " . ($DRY_RUN ? "DRY RUN (no changes)" : "LIVE (will fix issues)") . "\n";
echo "Target: " . ($SPECIFIC_CLIENT ? "Client '$SPECIFIC_CLIENT'" : "ALL clients") . "\n\n";

function getClientDatabases($mysqli) {
    $sql = "SELECT TABLE_SCHEMA as db_name
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', 'pixel', 'template')
              AND TABLE_NAME = 'superpixel_resolution_log'
            GROUP BY TABLE_SCHEMA
            HAVING COUNT(CASE WHEN TABLE_NAME = 'superpixel_visitors' THEN 1 END) > 0
            ORDER BY TABLE_SCHEMA";
    
    $result = $mysqli->query($sql);
    $databases = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $databases[] = $row['db_name'];
        }
    }
    
    return $databases;
}

function auditDatabase($dbName, $mysqli, $dryRun = true) {
    echo "=== Auditing $dbName ===\n";
    
    // Select the database
    if (!$mysqli->select_db($dbName)) {
        echo "❌ Failed to select database $dbName: " . $mysqli->error . "\n";
        return null;
    }
    
    // Get event statistics
    $eventsQuery = "SELECT 
                        COUNT(*) as total_events,
                        COUNT(DISTINCT uuid) as unique_uuids,
                        COUNT(CASE WHEN uuid IS NOT NULL AND uuid != '' AND uuid != 'null' THEN 1 END) as events_with_uuid
                    FROM superpixel_resolution_log";
    
    $eventsResult = $mysqli->query($eventsQuery);
    if (!$eventsResult) {
        echo "❌ Failed to query events: " . $mysqli->error . "\n";
        return null;
    }
    $eventStats = $eventsResult->fetch_assoc();
    
    // Get visitor statistics
    $visitorsQuery = "SELECT COUNT(*) as total_visitors FROM superpixel_visitors";
    $visitorsResult = $mysqli->query($visitorsQuery);
    if (!$visitorsResult) {
        echo "❌ Failed to query visitors: " . $mysqli->error . "\n";
        return null;
    }
    $visitorStats = $visitorsResult->fetch_assoc();
    
    // Find missing visitors
    $missingQuery = "SELECT COUNT(DISTINCT r.uuid) as missing_visitors
                     FROM superpixel_resolution_log r
                     LEFT JOIN superpixel_visitors v ON r.uuid = v.uuid
                     WHERE v.uuid IS NULL 
                       AND r.uuid IS NOT NULL 
                       AND r.uuid != '' 
                       AND r.uuid != 'null'";
    
    $missingResult = $mysqli->query($missingQuery);
    $missingStats = $missingResult ? $missingResult->fetch_assoc() : ['missing_visitors' => 'ERROR'];
    
    // Calculate coverage
    $expectedVisitors = $eventStats['unique_uuids'];
    $actualVisitors = $visitorStats['total_visitors'];
    $coverage = $expectedVisitors > 0 ? round(($actualVisitors / $expectedVisitors) * 100, 1) : 0;
    
    $audit = [
        'database' => $dbName,
        'total_events' => $eventStats['total_events'],
        'events_with_uuid' => $eventStats['events_with_uuid'],
        'unique_uuids' => $expectedVisitors,
        'total_visitors' => $actualVisitors,
        'missing_visitors' => $missingStats['missing_visitors'],
        'coverage_percent' => $coverage,
        'status' => $coverage >= 100 ? 'GOOD' : ($coverage >= 90 ? 'WARNING' : 'CRITICAL')
    ];
    
    // Display results
    echo "📊 Events: {$audit['total_events']} total, {$audit['events_with_uuid']} with UUID\n";
    echo "👥 Unique UUIDs: {$audit['unique_uuids']}\n";
    echo "👤 Visitors: {$audit['total_visitors']} (Coverage: {$audit['coverage_percent']}%)\n";
    echo "⚠️  Missing: {$audit['missing_visitors']} visitors\n";
    echo "🎯 Status: {$audit['status']}\n";
    
    if ($audit['missing_visitors'] > 0 && !$dryRun) {
        echo "🔧 Fixing missing visitors...\n";
        $result = backfillMissingVisitors($mysqli, 10000, $dbName);
        echo "✅ Backfilled {$result['backfilled_count']} visitors (errors: {$result['error_count']})\n";
        
        // Re-audit to get updated stats
        $updatedAudit = auditDatabase($dbName, $mysqli, true);
        if ($updatedAudit) {
            echo "📈 Updated coverage: {$updatedAudit['coverage_percent']}%\n";
        }
    }
    
    echo "\n";
    return $audit;
}

try {
    // Connect to MySQL
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
    if ($mysqli->connect_error) {
        die("❌ MySQL connection failed: " . $mysqli->connect_error);
    }
    
    // Get list of client databases
    if ($SPECIFIC_CLIENT) {
        $databases = [$SPECIFIC_CLIENT];
    } else {
        $databases = getClientDatabases($mysqli);
    }
    
    echo "📋 Found " . count($databases) . " client database(s)\n\n";
    
    $audits = [];
    $totalIssues = 0;
    
    foreach ($databases as $dbName) {
        $audit = auditDatabase($dbName, $mysqli, $DRY_RUN);
        if ($audit) {
            $audits[] = $audit;
            if ($audit['status'] !== 'GOOD') {
                $totalIssues++;
            }
        }
    }
    
    // Summary report
    echo "=== SUMMARY REPORT ===\n";
    echo "Databases audited: " . count($audits) . "\n";
    echo "Issues found: $totalIssues\n\n";
    
    if (!empty($audits)) {
        echo "📊 Detailed Results:\n";
        printf("%-25s %-8s %-8s %-10s %-8s %-10s\n", 
               "Database", "Events", "UUIDs", "Visitors", "Missing", "Status");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($audits as $audit) {
            $statusEmoji = [
                'GOOD' => '✅',
                'WARNING' => '⚠️ ',
                'CRITICAL' => '❌'
            ];
            
            printf("%-25s %-8d %-8d %-10d %-8d %s %-8s\n",
                   $audit['database'],
                   $audit['total_events'],
                   $audit['unique_uuids'],
                   $audit['total_visitors'],
                   $audit['missing_visitors'],
                   $statusEmoji[$audit['status']],
                   $audit['status']
            );
        }
    }
    
    if ($DRY_RUN && $totalIssues > 0) {
        echo "\n💡 To fix issues, run: php ensure_visitor_consistency.php\n";
    } elseif (!$DRY_RUN && $totalIssues > 0) {
        echo "\n🎉 All identified issues have been resolved!\n";
    } else {
        echo "\n✅ All databases have consistent visitor records!\n";
    }
    
    $mysqli->close();
    
} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Audit Complete ===\n";
?> 