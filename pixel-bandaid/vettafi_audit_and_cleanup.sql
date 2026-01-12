-- VettaFi Final Audit & Cleanup Script
-- Use this to identify gaps and remove duplicates between hashed/non-hashed rows.

-- ============================================================
-- AUDIT 1: Count events by Date and Hash Status
-- ============================================================
SELECT 
    DATE(created_at) as ingestion_date,
    LEFT(event_timestamp, 10) as event_date,
    CASE WHEN import_hash IS NULL THEN 'MISSING HASH' ELSE 'HAS HASH' END as status,
    COUNT(*) as row_count,
    COUNT(DISTINCT uuid, event_type, event_timestamp) as unique_events
FROM superpixel_resolution_log
GROUP BY ingestion_date, event_date, status
ORDER BY ingestion_date DESC, event_date DESC;

-- ============================================================
-- CLEANUP 1: Remove unhashed rows that already exist as hashed rows
-- This prevents "Duplicate entry" errors when we try to backfill hashes later.
-- ============================================================
DELETE t1 FROM superpixel_resolution_log t1
JOIN (
    SELECT import_hash, uuid, event_type, event_timestamp 
    FROM superpixel_resolution_log 
    WHERE import_hash IS NOT NULL
) t2 ON t1.uuid = t2.uuid 
    AND t1.event_type = t2.event_type 
    AND t1.event_timestamp = t2.event_timestamp
WHERE t1.import_hash IS NULL;

-- ============================================================
-- CLEANUP 2: Remove internal duplicates among unhashed rows
-- In case there are multiple identical rows that BOTH lack hashes.
-- ============================================================
DELETE t1 FROM superpixel_resolution_log t1
INNER JOIN superpixel_resolution_log t2 
WHERE t1.uuid = t2.uuid 
  AND t1.event_type = t2.event_type 
  AND t1.event_timestamp = t2.event_timestamp
  AND t1.import_hash IS NULL 
  AND t2.import_hash IS NULL
  AND t1.id > t2.id;

-- ============================================================
-- CLEANUP 3: Backfill missing hashes
-- Now that we've cleared collisions, this should be safe.
-- ============================================================
UPDATE superpixel_resolution_log
SET import_hash = SHA2(CONCAT(COALESCE(uuid, ''), '|', COALESCE(event_type, ''), '|', COALESCE(event_timestamp, '')), 256)
WHERE import_hash IS NULL
  AND uuid IS NOT NULL;

-- ============================================================
-- VERIFICATION: The "Final Count"
-- ============================================================
SELECT 
    COUNT(*) as total_rows,
    COUNT(DISTINCT import_hash) as unique_hashes,
    MIN(event_timestamp) as earliest_event,
    MAX(event_timestamp) as latest_event
FROM superpixel_resolution_log;
