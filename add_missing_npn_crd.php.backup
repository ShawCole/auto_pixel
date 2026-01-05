<?php
// Quick fix to add missing npn/crd columns to superpixel_visitors tables

$host = '34.26.61.148';
$username = 'root';
$password = 'AccuPoint01!';

echo "🔧 ADDING MISSING NPN/CRD COLUMNS TO VISITORS TABLES\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "=====================================\n\n";

// Databases that failed (from the log)
$databases_to_fix = [
    'TEST_CLIENT_19', 'TEST_CLIENT_333', 'TEST_CLIENT_444',
    'TEST_CLIENT_888', 'TEST_CLIENT_999', 'TEST_CLIENT_1111'
];

$success_count = 0;
$failure_count = 0;

foreach ($databases_to_fix as $db_name) {
    echo "[" . ($success_count + $failure_count + 1) . "/" . count($databases_to_fix) . "] 🔧 FIXING: $db_name\n";
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if npn column exists in superpixel_visitors
        $stmt = $pdo->prepare("SHOW COLUMNS FROM superpixel_visitors LIKE 'npn'");
        $stmt->execute();
        $npn_exists = $stmt->rowCount() > 0;
        
        // Check if crd column exists in superpixel_visitors
        $stmt = $pdo->prepare("SHOW COLUMNS FROM superpixel_visitors LIKE 'crd'");
        $stmt->execute();
        $crd_exists = $stmt->rowCount() > 0;
        
        $columns_to_add = [];
        if (!$npn_exists) {
            $columns_to_add[] = "ADD COLUMN `npn` VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL";
        }
        if (!$crd_exists) {
            $columns_to_add[] = "ADD COLUMN `crd` VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL";
        }
        
        if (!empty($columns_to_add)) {
            $sql = "ALTER TABLE superpixel_visitors " . implode(', ', $columns_to_add);
            $pdo->exec($sql);
            echo "  ✅ Added columns: " . (!$npn_exists ? 'npn ' : '') . (!$crd_exists ? 'crd' : '') . "\n";
        } else {
            echo "  ℹ️  No changes needed - columns already exist\n";
        }
        
        $success_count++;
        echo "  ✅ FIX COMPLETED\n";
        
    } catch (Exception $e) {
        $failure_count++;
        echo "  ❌ FIX FAILED: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "=====================================\n";
echo "🎉 NPN/CRD FIX COMPLETED! 🎉\n";
echo "📊 Results: $success_count/" . count($databases_to_fix) . " databases fixed successfully\n";
if ($failure_count > 0) {
    echo "⚠️  $failure_count database(s) failed to fix\n";
}
?> 