<?php
// batch_populate_import_hash.php
// Highly robust one-time script for import_hash migration.
// Handles older MySQL versions and large datasets across all pixels.

require_once __DIR__ . '/visitor_upsert_functions.php';

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';

/**
 * Utility to check if an index exists
 */
function indexExists($db, $tableName, $indexName)
{
    try {
        $result = $db->query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
        return ($result && $result->num_rows > 0);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Utility to check if a column exists
 */
function columnExists($db, $tableName, $columnName)
{
    try {
        $result = $db->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    } catch (Throwable $e) {
        return false;
    }
}

// Get all client databases
$centralDb = 'pixel';
try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $centralDb);
    if ($mysqli->connect_error) {
        throw new Exception("Central connection failed: " . $mysqli->connect_error);
    }

    $result = $mysqli->query("SELECT client_name FROM pixel_sheets WHERE client_name IS NOT NULL AND client_name != ''");
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row['client_name'];
    }
    $mysqli->close();

    echo "[BATCH] Found " . count($clients) . " clients.\n";

    foreach ($clients as $client) {
        if ($client == 'VettaFi') {
            echo "\n>>> [SKIP] VettaFi (Manual handling in Navicat)\n";
            continue;
        }

        echo "\n>>> [MIGRATING] $client\n";
        $db = new mysqli($dbHost, $dbUser, $dbPass, $client);
        if ($db->connect_error) {
            echo "    Failed to connect to $client, skipping.\n";
            continue;
        }

        // --- STEP 1: CLEANUP BLOCKING INDEXES ---
        // We drop these first so they don't block the column modifications or hashing
        echo "    1. Removing blocking unique constraints...\n";
        if (indexExists($db, 'superpixel_resolution_log', 'uniq_event_conditional')) {
            $db->query("DROP INDEX uniq_event_conditional ON superpixel_resolution_log");
        }
        if (indexExists($db, 'superpixel_resolution_log', 'idx_import_hash')) {
            $db->query("DROP INDEX idx_import_hash ON superpixel_resolution_log");
        }
        if (indexExists($db, 'superpixel_resolution_log', 'idx_robust_dedupe')) {
            $db->query("DROP INDEX idx_robust_dedupe ON superpixel_resolution_log");
        }

        // --- STEP 2: CLEANUP OLD COLUMNS ---
        echo "    2. Cleaning up old columns...\n";
        if (columnExists($db, 'superpixel_resolution_log', 'dedupe_uuid')) {
            // Drop it. Large tables will take time.
            echo "       Dropping dedupe_uuid...\n";
            $db->query("ALTER TABLE superpixel_resolution_log DROP COLUMN dedupe_uuid");
        }

        // --- STEP 3: ENSURE NEW COLUMN ---
        echo "    3. Preparing import_hash column...\n";
        if (!columnExists($db, 'superpixel_resolution_log', 'import_hash')) {
            $db->query("ALTER TABLE superpixel_resolution_log ADD COLUMN import_hash VARCHAR(64) AFTER uuid");
        }

        // --- STEP 4: CLEANUP GHOST DATA ---
        echo "    4. Removing ghost/null records...\n";
        $db->query("DELETE FROM superpixel_resolution_log WHERE uuid IS NULL OR TRIM(uuid) = '' OR LENGTH(TRIM(uuid)) < 20");

        // --- STEP 5: BACKFILL HASHES ---
        echo "    5. Calculating hashes (last 30 days)...\n";
        $backfillSql = "
            UPDATE superpixel_resolution_log
            SET import_hash = SHA2(CONCAT(COALESCE(uuid, ''), '|', COALESCE(event_type, ''), '|', COALESCE(event_timestamp, '')), 256)
            WHERE import_hash IS NULL
              AND CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND uuid IS NOT NULL
        ";
        if ($db->query($backfillSql)) {
            echo "       Updated " . $db->affected_rows . " rows.\n";
        } else {
            echo "       Backfill Error: " . $db->error . "\n";
        }

        // --- STEP 6: DEDUPLICATE ---
        echo "    6. Deduplicating events...\n";
        // Create temp index to make the join-delete fast
        if (!indexExists($db, 'superpixel_resolution_log', 'tmp_migration_idx')) {
            $db->query("CREATE INDEX tmp_migration_idx ON superpixel_resolution_log(import_hash)");
        }

        $deleteSql = "
            DELETE t1 FROM superpixel_resolution_log t1
            INNER JOIN superpixel_resolution_log t2 
            WHERE t1.import_hash = t2.import_hash AND t1.id > t2.id
        ";
        if ($db->query($deleteSql)) {
            echo "       Removed " . $db->affected_rows . " duplicate records.\n";
        }

        // Remove temp index
        $db->query("DROP INDEX tmp_migration_idx ON superpixel_resolution_log");

        // --- STEP 7: FINALIZE UNIQUE INDEX ---
        echo "    7. Creating final UNIQUE constraint...\n";
        if ($db->query("CREATE UNIQUE INDEX idx_import_hash ON superpixel_resolution_log (import_hash)")) {
            echo "    [SUCCESS] $client is fully migrated and indexed!\n";
        } else {
            echo "    [ERROR] Failed to index $client: " . $db->error . "\n";
        }

        $db->close();
    }

    echo "\n\n>>> BATCH MIGRATION COMPLETE! <<<\n";

} catch (Throwable $e) {
    echo "Fatal Cluster Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>