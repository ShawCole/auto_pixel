<?php
// batch_populate_import_hash.php
// One-time script to populate import_hash for the last 30 days across all client databases.

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
        echo "\n>>> Processing Client: $client\n";
        $db = new mysqli($dbHost, $dbUser, $dbPass, $client);
        if ($db->connect_error) {
            echo "Skipping $client: " . $db->connect_error . "\n";
            continue;
        }

        // 1. Ensure Schema (Add column/index if missing)
        echo "Ensuring Schema...\n";
        ensureSchema($db);

        // 2. Clear out any ghost rows that would block uniqueness
        echo "Cleaning ghost rows...\n";
        $db->query("DELETE FROM superpixel_resolution_log WHERE uuid IS NULL OR TRIM(uuid) = '' OR LENGTH(TRIM(uuid)) < 20");

        // 3. Populate import_hash for the last 30 days
        echo "Backfilling import_hash for last 30 days...\n";
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

        // 4. Deduplicate existing hashes to ensure unique index can be created
        echo "Deduplicating...\n";
        $db->query("CREATE INDEX IF NOT EXISTS tmp_hash_idx ON superpixel_resolution_log(import_hash)");
        $db->query("
            DELETE t1 FROM superpixel_resolution_log t1
            INNER JOIN superpixel_resolution_log t2 
            WHERE t1.import_hash = t2.import_hash AND t1.id > t2.id
        ");
        $db->query("DROP INDEX IF EXISTS tmp_hash_idx ON superpixel_resolution_log");

        // 5. Ensure Unique Index
        echo "Finalizing index...\n";
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS idx_import_hash ON superpixel_resolution_log (import_hash)");

        $db->close();
    }

    echo "\n\nBatch migration complete!\n";

} catch (Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}
