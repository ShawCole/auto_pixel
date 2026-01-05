# SuperPixel Visitor Synchronization: Complete Implementation Guide

**Date:** January 3, 2026
**System:** SuperPixel Database Management
**Objective:** Automate the daily synchronization of visitor profile timestamps (`first_seen_at`, `last_seen_at`) using the raw event log (`superpixel_resolution_log`) as the immutable Source of Truth.

---

## 1. Executive Summary: The Logic

To ensure the `superpixel_visitors` table is always accurate, we established a **4-Phase Routine**. This routine addresses the three specific data failures we discovered during debugging:
1.  **Ghost Data:** Invisible characters (tabs/spaces) causing JOIN failures.
2.  **Missing Profiles:** Active users in the Log who had not yet been created in the Visitors table (The USA Financial Issue).
3.  **Timestamp Drift:** Existing users whose timestamps were technically "valid" but historically inaccurate due to timezone shifts or partial updates (The TruVestments Issue).

---

## 2. The 4-Phase SQL Routine

## Phase 1: Garbage Collection (The Pre-Clean)
**Goal:** Remove corrupted data that breaks SQL JOIN operations.
**Context:** We identified "Ghost UUIDs" (rows containing only invisible whitespace) that caused update queries to fail silently.

```sql
DELETE FROM `superpixel_visitors`
WHERE uuid IS NULL 
   OR TRIM(uuid) = '' 
   OR LENGTH(TRIM(uuid)) < 20;

## Phase 2: The Backfill (Handling New Traffic)

**Goal:** Create profiles for Active Users (last 45 days) who do not exist in the visitor table yet.  
**Context:** This fixed the "0 rows affected" issue on USA Financial. Standard updates fail if the row doesn't exist; this creates it.

### SQL

```sql
INSERT INTO `superpixel_visitors` (uuid, first_seen_at, last_seen_at)
SELECT
  uuid,
  -- Clean timestamps using String Casting
  DATE_FORMAT(
    MIN(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)),
    '%Y-%m-%d %H:%i:%s'
  ),
  DATE_FORMAT(
    MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)),
    '%Y-%m-%d %H:%i:%s'
  )
FROM `superpixel_resolution_log`
WHERE uuid IS NOT NULL
  AND LENGTH(uuid) > 20
GROUP BY uuid
HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
-- If they exist, update them immediately
ON DUPLICATE KEY UPDATE
  first_seen_at = VALUES(first_seen_at),
  last_seen_at = VALUES(last_seen_at);
```

---

## Phase 3: The Force Sync (Correction)

**Goal:** Force existing profiles to match the Log exactly.  
**Context:** This fixed the "Drift" issue on TruVestments. We convert timestamps to Strings (`YYYY-MM-DD HH:MM:SS`) to bypass timezone conversions or microsecond truncation logic that often prevents standard updates from firing.

### SQL

```sql
UPDATE `superpixel_visitors` v
JOIN (
  SELECT
    uuid,
    DATE_FORMAT(
      MIN(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)),
      '%Y-%m-%d %H:%i:%s'
    ) as true_first_seen_str,
    DATE_FORMAT(
      MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)),
      '%Y-%m-%d %H:%i:%s'
    ) as true_last_seen_str
  FROM `superpixel_resolution_log`
  WHERE uuid IS NOT NULL
    AND LENGTH(uuid) > 20
  GROUP BY uuid
  HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
) stats ON v.uuid = stats.uuid
SET
  v.first_seen_at = stats.true_first_seen_str,
  v.last_seen_at  = stats.true_last_seen_str
-- Optimization: Only update if the string representation differs
WHERE DATE_FORMAT(v.last_seen_at,  '%Y-%m-%d %H:%i:%s') != stats.true_last_seen_str
   OR DATE_FORMAT(v.first_seen_at, '%Y-%m-%d %H:%i:%s') != stats.true_first_seen_str;
```

---

## Phase 4: Verification (The Audit)

**Goal:** Confirm the system is clean.  
**Success Criteria:** This query must return **0 rows**.

### SQL

```sql
SELECT
  v.uuid,
  v.last_seen_at AS current,
  stats.true_last_seen AS target
FROM `superpixel_visitors` v
JOIN (
  SELECT
    uuid,
    MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) as true_last_seen
  FROM `superpixel_resolution_log`
  WHERE uuid IS NOT NULL
    AND LENGTH(uuid) > 20
  GROUP BY uuid
  HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
) stats ON v.uuid = stats.uuid
WHERE DATE_FORMAT(v.last_seen_at, '%Y-%m-%d %H:%i:%s')
   != DATE_FORMAT(stats.true_last_seen, '%Y-%m-%d %H:%i:%s');
```

---

## 3. Daily Implementation (PHP Script)

This is the recommended logic for your `daily_pixel_ingestion.php` script.

### PHP

```php
<?php
// DAILY VISITOR SYNC LOGIC
$clients = getClientDatabases(); // Your internal function to get DB list

foreach ($clients as $dbName) {
  echo "Starting Sync for: $dbName \n";

  $conn->select_db($dbName);

  // --- PHASE 1: GARBAGE COLLECTION ---
  // Remove invisible UUIDs that break joins
  $conn->query("DELETE FROM `superpixel_visitors` WHERE uuid IS NULL OR TRIM(uuid) = '' OR LENGTH(TRIM(uuid)) < 20");

  // --- PHASE 2: UPSERT (BACKFILL) ---
  // Insert new users found in the log who aren't in visitors yet
  // Also updates them if found (ON DUPLICATE KEY UPDATE)
  $sql_upsert = "
    INSERT INTO `superpixel_visitors` (uuid, first_seen_at, last_seen_at)
    SELECT
      uuid,
      DATE_FORMAT(MIN(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s'),
      DATE_FORMAT(MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s')
    FROM `superpixel_resolution_log`
    WHERE uuid IS NOT NULL AND LENGTH(uuid) > 20
    GROUP BY uuid
    HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
    ON DUPLICATE KEY UPDATE
      first_seen_at = VALUES(first_seen_at),
      last_seen_at  = VALUES(last_seen_at)
  ";

  $conn->query($sql_upsert);

  // --- PHASE 3: FORCE SYNC (SAFETY NET) ---
  // Catch-all for any existing rows that drifted due to format issues
  $sql_force = "
    UPDATE `superpixel_visitors` v
    JOIN (
      SELECT
        uuid,
        DATE_FORMAT(MIN(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s') as true_first_seen_str,
        DATE_FORMAT(MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s') as true_last_seen_str
      FROM `superpixel_resolution_log`
      WHERE uuid IS NOT NULL AND LENGTH(uuid) > 20
      GROUP BY uuid
      HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
    ) stats ON v.uuid = stats.uuid
    SET
      v.first_seen_at = stats.true_first_seen_str,
      v.last_seen_at  = stats.true_last_seen_str
    WHERE DATE_FORMAT(v.last_seen_at,  '%Y-%m-%d %H:%i:%s') != stats.true_last_seen_str
       OR DATE_FORMAT(v.first_seen_at, '%Y-%m-%d %H:%i:%s') != stats.true_first_seen_str
  ";

  $conn->query($sql_force);

  // --- PHASE 4: AUDIT (LOGGING) ---
  // Optional: Check if anything remains broken
  $result = $conn->query("
    SELECT count(*) as cnt
    FROM `superpixel_visitors` v
    JOIN (
      SELECT
        uuid,
        MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) as true_last_seen
      FROM `superpixel_resolution_log`
      WHERE uuid IS NOT NULL AND LENGTH(uuid) > 20
      GROUP BY uuid
      HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
    ) stats ON v.uuid = stats.uuid
    WHERE DATE_FORMAT(v.last_seen_at, '%Y-%m-%d %H:%i:%s')
       != DATE_FORMAT(stats.true_last_seen, '%Y-%m-%d %H:%i:%s')
  ");

  $row = $result->fetch_assoc();

  if ($row['cnt'] == 0) {
    echo "SUCCESS: $dbName is fully synced.\n";
  } else {
    echo "WARNING: $dbName still has " . $row['cnt'] . " mismatched rows.\n";
  }
}
?>
```
