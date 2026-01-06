<?php
// batch_populate_import_hash.php
// One-time script to populate import_hash across all client databases.
// Optimized to avoid "Duplicate entry" errors by indexing LAST.

require_once __DIR__ . '/visitor_upsert_functions.php';

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';

// Get all client databases from the central pixel table
$centralDb = 'pixel';
try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $centralDb);
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }

    $result = $mysqli->query("SELECT client_name FROM pixel_sheets WHERE client_name IS NOT NULL AND client_name != ''");
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row['client_name'];
    }
    $mysqli->close();

    echo "Found " . count($clients) . " clients to process.\n";

    foreach ($clients as $client) {
        if ($client == 'VettaFi') {
            echo "\n>>> Skipping VettaFi (Manual Migration in Progress)\n";
            continue;
        }

        echo "\n>>> Processing Client: $client\n";
        $db = new mysqli($dbHost, $dbUser, $dbPass, $client);
        if ($db->connect_error) {
            echo "Skipping $client: " . $db->connect_error . "\n";
            continue;
        }

        // 1. Cleanup old schema & indexes that block migration
        echo "Cleaning up old schema & blocking indexes...\n";
        $db->query("DROP INDEX IF EXISTS uniq_event_conditional ON superpixel_resolution_log");
        $db->query("DROP INDEX IF EXISTS idx_import_hash ON superpixel_resolution_log");

        $checkDedupe = $db->query("SHOW COLUMNS FROM superpixel_resolution_log LIKE 'dedupe_uuid'");
        if ($checkDedupe && $checkDedupe->num_rows > 0) {
            $db->query("ALTER TABLE superpixel_resolution_log DROP COLUMN dedupe_uuid");
        }

        // 2. Add import_hash column (NOT UNIQUE yet)
        echo "Adding import_hash column...\n";
        $db->query("ALTER TABLE superpixel_resolution_log ADD COLUMN IF NOT EXISTS import_hash VARCHAR(64) AFTER uuid");

        // 3. Clear out any ghost rows that would block uniqueness
        echo "Cleaning ghost rows...\n";
        $db->query("DELETE FROM superpixel_resolution_log WHERE uuid IS NULL OR TRIM(uuid) = '' OR LENGTH(TRIM(uuid)) < 20");

        // 4. Populate import_hash for the last 30 days
        echo "Backfilling import_hash (30 days)...\n";
        $hashSql = "
            UPDATE superpixel_resolution_log
            SET import_hash = SHA2(CONCAT(COALESCE(uuid, ''), '|', COALESCE(event_type, ''), '|', COALESCE(event_timestamp, '')), 256)
            WHERE import_hash IS NULL
              AND CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND uuid IS NOT NULL
        ";

        if ($db->query($hashSql)) {
            echo "Successfully hashed " . $db->affected_rows . " rows.\n";
        } else {
            echo "Error hashing rows: " . $db->error . "\n";
        }

        // 5. Deduplicate existing hashes
        echo "Deduplicating...\n";
        $db->query("CREATE INDEX IF NOT EXISTS tmp_hash_idx ON superpixel_resolution_log(import_hash)");
        $db->query("
            DELETE t1 FROM superpixel_resolution_log t1
            INNER JOIN superpixel_resolution_log t2 
            WHERE t1.import_hash = t2.import_hash AND t1.id > t2.id
        ");
        $db->query("DROP INDEX IF EXISTS tmp_hash_idx ON superpixel_resolution_log");

        // 6. Finalize UNIQUE Index
        echo "Creating Final Unique Index...\n";
        if ($db->query("CREATE UNIQUE INDEX idx_import_hash ON superpixel_resolution_log (import_hash)")) {
            echo "✅ Migration complete for $client\n";
        } else {
            echo "❌ Failed to create unique index for $client: " . $db->error . "\n";
        }

        $db->close();
    }

    echo "\n\nBatch migration complete (skipped VettaFi)!\n";

} catch (Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}
