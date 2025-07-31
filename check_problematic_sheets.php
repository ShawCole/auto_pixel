<?php
/**
 * Check Problematic Database Schemas
 * Identifies databases that have schema issues causing sync failures
 */

$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

echo "🔍 Checking Database Schemas for Sync Issues\n";
echo "============================================\n\n";

// Get sheet mappings to see which databases are being synced
$sheetMappings = [];
$mappingFile = '/opt/auto-pixel/sheet_mappings.json';
if (file_exists($mappingFile)) {
    $sheetMappings = json_decode(file_get_contents($mappingFile), true) ?: [];
    echo "📋 Found " . count($sheetMappings) . " databases in sheet_mappings.json\n";
} else {
    echo "⚠️  sheet_mappings.json not found\n";
}

// Databases that are known to be failing from sync logs
$knownFailingDatabases = [
    'Test'
];

$problematicDatabases = [];
$allDatabases = array_unique(array_merge(array_keys($sheetMappings), $knownFailingDatabases));

echo "🔍 Checking " . count($allDatabases) . " databases for schema issues...\n\n";

try {
    $rootConnection = new mysqli($dbHost, $dbUser, $dbPass);
    if ($rootConnection->connect_error) {
        die("❌ Connection failed: " . $rootConnection->connect_error . "\n");
    }
    
    foreach ($allDatabases as $dbName) {
        echo "🔍 Checking: $dbName\n";
        
        // Check if database exists
        $dbCheckQuery = "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?";
        $stmt = $rootConnection->prepare($dbCheckQuery);
        $stmt->bind_param("s", $dbName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo "   ❌ Database does not exist\n";
            $problematicDatabases[] = [
                'database' => $dbName,
                'issue' => 'Database does not exist',
                'severity' => 'critical',
                'action' => 'remove_mapping'
            ];
            continue;
        }
        
        // Connect to specific database
        $dbConnection = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($dbConnection->connect_error) {
            echo "   ❌ Cannot connect to database\n";
            $problematicDatabases[] = [
                'database' => $dbName,
                'issue' => 'Cannot connect to database',
                'severity' => 'critical',
                'action' => 'remove_mapping'
            ];
            continue;
        }
        
        // Check required tables
        $requiredTables = ['superpixel_resolution_log', 'superpixel_visitors'];
        $missingTables = [];
        
        foreach ($requiredTables as $table) {
            $tableCheck = $dbConnection->query("SHOW TABLES LIKE '$table'");
            if ($tableCheck->num_rows === 0) {
                $missingTables[] = $table;
            }
        }
        
        if (!empty($missingTables)) {
            echo "   ❌ Missing tables: " . implode(', ', $missingTables) . "\n";
            $problematicDatabases[] = [
                'database' => $dbName,
                'issue' => 'Missing tables: ' . implode(', ', $missingTables),
                'severity' => 'critical',
                'action' => 'remove_mapping'
            ];
            continue;
        }
        
        // Check critical columns in superpixel_resolution_log
        $requiredColumns = ['id', 'uuid', 'url', 'element',  'event_type'];
        $missingColumns = [];
        
        $columnsResult = $dbConnection->query("SHOW COLUMNS FROM superpixel_resolution_log");
        $existingColumns = [];
        while ($row = $columnsResult->fetch_assoc()) {
            $existingColumns[] = $row['Field'];
        }
        
        foreach ($requiredColumns as $column) {
            if (!in_array($column, $existingColumns)) {
                $missingColumns[] = $column;
            }
        }
        
        if (!empty($missingColumns)) {
            echo "   ⚠️  Missing columns in superpixel_resolution_log: " . implode(', ', $missingColumns) . "\n";
            $problematicDatabases[] = [
                'database' => $dbName,
                'issue' => 'Missing columns: ' . implode(', ', $missingColumns),
                'severity' => 'warning',
                'action' => 'fix_schema'
            ];
            continue;
        }
        
        echo "   ✅ Schema looks good\n";
        $dbConnection->close();
    }
    
    $rootConnection->close();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "📊 PROBLEMATIC DATABASES SUMMARY\n";
echo str_repeat("=", 60) . "\n\n";

if (empty($problematicDatabases)) {
    echo "✅ No problematic databases found!\n";
} else {
    echo "Found " . count($problematicDatabases) . " problematic database(s):\n\n";
    
    $critical = array_filter($problematicDatabases, function($db) { return $db['severity'] === 'critical'; });
    $warnings = array_filter($problematicDatabases, function($db) { return $db['severity'] === 'warning'; });
    
    if (!empty($critical)) {
        echo "🚨 CRITICAL ISSUES (Remove from sheet mappings):\n";
        echo "-----------------------------------------------\n";
        foreach ($critical as $i => $db) {
            echo ($i + 1) . ". {$db['database']}: {$db['issue']}\n";
        }
        
        echo "\n💻 Commands to remove them from sheet_mappings.json:\n";
        foreach ($critical as $db) {
            echo "php -r \"\$data = json_decode(file_get_contents('/opt/auto-pixel/sheet_mappings.json'), true); unset(\$data['{$db['database']}']); file_put_contents('/opt/auto-pixel/sheet_mappings.json', json_encode(\$data, JSON_PRETTY_PRINT));\"\n";
        }
        echo "\n";
    }
    
    if (!empty($warnings)) {
        echo "⚠️  SCHEMA WARNINGS (Can be fixed):\n";
        echo "-----------------------------------\n";
        foreach ($warnings as $i => $db) {
            echo ($i + 1) . ". {$db['database']}: {$db['issue']}\n";
        }
        echo "\n";
    }
}

echo str_repeat("=", 60) . "\n";
echo "✅ Check completed!\n";
?> 
