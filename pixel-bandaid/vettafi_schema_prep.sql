-- VettaFi Schema Preparation for 16-Day Historical Import
-- This script prepares the superpixel_resolution_log table for deduplication
-- Run each step INDIVIDUALLY and verify success before proceeding

-- ============================================================
-- STEP 1: Check if problematic indexes exist
-- ============================================================
-- Run this first to see what we're dealing with
SHOW INDEX FROM superpixel_resolution_log WHERE Key_name IN ('uniq_event_conditional', 'idx_import_hash');

-- ============================================================
-- STEP 2: Drop old blocking index (if exists)
-- ============================================================
-- Only run if Step 1 shows 'uniq_event_conditional' exists
-- If it doesn't exist, you'll get an error - that's OK, just skip to Step 3
DROP INDEX uniq_event_conditional ON superpixel_resolution_log;

-- ============================================================
-- STEP 3: Check if import_hash column exists
-- ============================================================
SHOW COLUMNS FROM superpixel_resolution_log LIKE 'import_hash';

-- ============================================================
-- STEP 4: Add import_hash column (if not exists)
-- ============================================================
-- Only run if Step 3 shows no results
-- If column already exists, skip this step
ALTER TABLE superpixel_resolution_log 
ADD COLUMN import_hash VARCHAR(64) AFTER uuid;

-- ============================================================
-- STEP 5: Drop existing idx_import_hash if it exists
-- ============================================================
-- Only run if Step 1 showed this index exists
-- This ensures we start fresh
DROP INDEX idx_import_hash ON superpixel_resolution_log;

-- ============================================================
-- STEP 6: Backfill import_hash for RECENT data only (CRITICAL)
-- ============================================================
-- This limits the scope to avoid timeout
-- We only hash the last 30 days since that's all we need for the 16-day import
UPDATE superpixel_resolution_log
SET import_hash = SHA2(CONCAT(
    COALESCE(uuid, ''), 
    '|', 
    COALESCE(event_type, ''), 
    '|', 
    COALESCE(event_timestamp, '')
), 256)
WHERE import_hash IS NULL
  AND uuid IS NOT NULL
  AND event_timestamp >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 30 DAY), '%Y-%m-%dT%H:%i:%s.000Z');

-- Check progress
SELECT COUNT(*) as total_rows,
       COUNT(import_hash) as hashed_rows,
       COUNT(*) - COUNT(import_hash) as remaining_nulls
FROM superpixel_resolution_log;

-- ============================================================
-- STEP 7: Clean ghost rows (optional but recommended)
-- ============================================================
-- Remove any rows with invalid UUIDs
-- Run this BEFORE creating the unique index
DELETE FROM superpixel_resolution_log 
WHERE uuid IS NULL 
   OR TRIM(uuid) = '' 
   OR LENGTH(TRIM(uuid)) < 20;

-- ============================================================
-- STEP 8: Remove duplicates in BATCHES (if any exist)
-- ============================================================
-- First, check if duplicates exist
SELECT import_hash, COUNT(*) as dup_count
FROM superpixel_resolution_log
WHERE import_hash IS NOT NULL
GROUP BY import_hash
HAVING COUNT(*) > 1
LIMIT 10;

-- If duplicates exist, create a temporary index for faster deletion
CREATE INDEX tmp_import_hash ON superpixel_resolution_log (import_hash);

-- Delete duplicates (keep the oldest/lowest ID)
-- Run this in batches if you have many duplicates
DELETE t1 FROM superpixel_resolution_log t1
INNER JOIN superpixel_resolution_log t2 
WHERE t1.import_hash = t2.import_hash 
  AND t1.id > t2.id
  AND t1.import_hash IS NOT NULL
LIMIT 10000;

-- Repeat the DELETE until it returns "0 rows affected"
-- Then drop the temporary index
DROP INDEX tmp_import_hash ON superpixel_resolution_log;

-- ============================================================
-- STEP 9: Create UNIQUE index on import_hash
-- ============================================================
-- This is the final step - ensures all future inserts are deduplicated
CREATE UNIQUE INDEX idx_import_hash ON superpixel_resolution_log (import_hash);

-- ============================================================
-- STEP 10: Verify schema is ready
-- ============================================================
SHOW INDEX FROM superpixel_resolution_log WHERE Key_name = 'idx_import_hash';

SELECT 
    COUNT(*) as total_rows,
    COUNT(DISTINCT import_hash) as unique_hashes,
    COUNT(import_hash) as non_null_hashes,
    COUNT(*) - COUNT(import_hash) as null_hashes
FROM superpixel_resolution_log;

-- You're ready when:
-- 1. idx_import_hash exists and is UNIQUE
-- 2. null_hashes is 0 (or only very old rows you don't care about)
-- 3. total_rows = unique_hashes (no duplicates)
