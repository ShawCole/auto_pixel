# Complete Guide for Column Updates

## Overview
This guide covers all the steps needed to:
1. Update database schemas with new columns
2. Create triggers for automatic NPN/CRD lookup
3. Update the PHP webhook to handle new columns
4. Update Google Sheets sync to include new columns
5. Update all sync scripts

## Step 1: Update Template Database Schema

First, connect to the MySQL server and update the template database tables:

```bash
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' pixel
```

Run these SQL commands:

```sql
-- Update schema for superpixel_resolution_log table
-- Add columns to superpixel_resolution_log (one at a time - MySQL doesn't support IF NOT EXISTS for ALTER TABLE ADD COLUMN)
ALTER TABLE superpixel_resolution_log ADD COLUMN npn VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_resolution_log ADD COLUMN crd VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_resolution_log ADD COLUMN elements TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;

-- Add columns to superpixel_visitors table (one at a time)
ALTER TABLE superpixel_visitors ADD COLUMN hem_sha256 TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_visited_url VARCHAR(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_element TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_percentage INT DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_referrer VARCHAR(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_timestamp VARCHAR(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_event VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN npn VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN crd VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;

-- Create indexes for better performance (these support IF NOT EXISTS)
CREATE INDEX IF NOT EXISTS idx_npn ON superpixel_resolution_log(npn);
CREATE INDEX IF NOT EXISTS idx_crd ON superpixel_resolution_log(crd);
CREATE INDEX IF NOT EXISTS idx_visitor_npn ON superpixel_visitors(npn);
CREATE INDEX IF NOT EXISTS idx_visitor_crd ON superpixel_visitors(crd);
CREATE INDEX IF NOT EXISTS idx_visitor_hem ON superpixel_visitors(hem_sha256(255));
```

## Step 2: Update All Existing Client Databases

For each existing client database, run the same ALTER TABLE commands:

```bash
# Get list of all client databases
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' -e "SHOW DATABASES LIKE 'TEST_CLIENT_%';"

# For each client database, run the update script
# Example for one client:
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' TEST_CLIENT_888 < update_schema.sql
```

## Step 3: Create Triggers for NPN/CRD Lookup

For each client database, create triggers that will automatically look up NPN/CRD:

```sql
USE TEST_CLIENT_888; -- Replace with actual client name

-- Trigger for superpixel_resolution_log table
DELIMITER $$

DROP TRIGGER IF EXISTS before_resolution_log_insert$$

CREATE TRIGGER before_resolution_log_insert
BEFORE INSERT ON superpixel_resolution_log
FOR EACH ROW
BEGIN
    DECLARE vNPN VARCHAR(255);
    DECLARE vCRD VARCHAR(255);

    -- Fetch values from accupoint_solutions database
    SELECT NPN, CRD
    INTO vNPN, vCRD
    FROM accupoint_solutions.hash_emails
    WHERE hash256 = NEW.hem_sha256
    LIMIT 1;

    -- Assign to NEW values
    SET NEW.npn = vNPN;
    SET NEW.crd = vCRD;
END$$

DELIMITER ;

-- Trigger for superpixel_visitors table
DELIMITER $$

DROP TRIGGER IF EXISTS before_visitors_insert$$

CREATE TRIGGER before_visitors_insert
BEFORE INSERT ON superpixel_visitors
FOR EACH ROW
BEGIN
    DECLARE vNPN VARCHAR(255);
    DECLARE vCRD VARCHAR(255);

    -- Fetch values from accupoint_solutions database
    SELECT NPN, CRD
    INTO vNPN, vCRD
    FROM accupoint_solutions.hash_emails
    WHERE hash256 = NEW.hem_sha256
    LIMIT 1;

    -- Assign to NEW values
    SET NEW.npn = vNPN;
    SET NEW.crd = vCRD;
END$$

DELIMITER ;

-- Update trigger for superpixel_visitors table
DELIMITER $$

DROP TRIGGER IF EXISTS before_visitors_update$$

CREATE TRIGGER before_visitors_update
BEFORE UPDATE ON superpixel_visitors
FOR EACH ROW
BEGIN
    DECLARE vNPN VARCHAR(255);
    DECLARE vCRD VARCHAR(255);

    -- Only update if NPN/CRD are null and hem_sha256 has changed or is being set
    IF (NEW.npn IS NULL OR NEW.crd IS NULL) AND NEW.hem_sha256 IS NOT NULL THEN
        -- Fetch values from accupoint_solutions database
        SELECT NPN, CRD
        INTO vNPN, vCRD
        FROM accupoint_solutions.hash_emails
        WHERE hash256 = NEW.hem_sha256
        LIMIT 1;

        -- Assign to NEW values if found
        IF vNPN IS NOT NULL THEN
            SET NEW.npn = vNPN;
        END IF;
        IF vCRD IS NOT NULL THEN
            SET NEW.crd = vCRD;
        END IF;
    END IF;
END$$

DELIMITER ;
```

## Step 4: Update pixel_import_final.php

Update the webhook script to handle the new last_* fields for visitors:

1. Find the section where visitor data is prepared (around line 250-280)
2. Add these lines before the visitor INSERT:

```php
// Add last_* fields from event data
$visitor_data['hem_sha256'] = $insert_data['hem_sha256'] ?? null;
$visitor_data['last_visited_url'] = $insert_data['visited_url'] ?? null;
$visitor_data['last_element'] = isset($event_data['element']) ? json_encode($event_data['element']) : null;
$visitor_data['last_percentage'] = $event_data['percentage'] ?? null;
$visitor_data['last_referrer'] = $event_data['referrer'] ?? null;
$visitor_data['last_timestamp'] = $event_data['timestamp'] ?? null;
$visitor_data['last_event'] = $event['event_type'] ?? null;
```

3. Update the ON DUPLICATE KEY UPDATE section to always update last_* fields:

```php
// In the update_parts loop, add special handling for last_* fields
if (strpos($key, 'last_') === 0) {
    $update_parts[] = "$escaped_key = $escaped_value";
} else {
    $update_parts[] = "$escaped_key = COALESCE(NULLIF($escaped_value, ''), $escaped_key, $escaped_value)";
}
```

## Step 5: Update All Sync Scripts

Replace the syncVisitorsToSheet and syncEventsToSheet functions in these files:
- smart_sync.php
- dynamic_sync.php
- staggered_sync.php
- sheets_sync.php

Use the updated functions from update_sync_columns.php.

## Step 6: Update create_client_sheet.php

Update the initial sheet creation to include all new columns with proper formatting:

```php
// In create_client_sheet.php, update the headers for both tabs

// Visitors tab headers
$visitorsHeaders = [
    'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 'Phone',
    'Personal Address', 'City', 'State', 'Zip', 'First Seen', 'Last Seen', 'Event Count',
    'Last Visited URL', 'Last Element', 'Last Percentage', 'Last Referrer',
    'Last Timestamp', 'Last Event', 'NPN', 'CRD'
];

// Events tab headers
$eventsHeaders = [
    'Timestamp', 'Event Type', 'URL', 'Elements', 'Referrer', 'IP Address', 
    'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 
    'Phone', 'City', 'State', 'HemSha256'
];
```

## Step 7: Deploy Changes

1. Commit and push all changes:
```bash
git add -A
git commit -m "Add new columns for visitors and events with NPN/CRD lookup"
git push origin main
```

2. On the server, pull changes:
```bash
cd /opt/auto-pixel
git pull origin main
```

3. Restart services:
```bash
pm2 restart all
# Restart the monitor if running
ps aux | grep monitor_new_sheets.php | grep -v grep | awk '{print $2}' | xargs kill
nohup php monitor_new_sheets.php > /opt/auto-pixel/monitor.log 2>&1 &
```

## Step 8: Test

1. Create a new test pixel to verify:
   - New database tables have all columns
   - Triggers are created automatically
   - Google Sheet has all new columns
   
2. Send test data to verify:
   - NPN/CRD lookup works
   - Last_* fields update correctly
   - Google Sheets sync includes all new data

## Column Summary

### Visitors Tab (Google Sheets)
1. UUID
2. First Name
3. Last Name
4. Company
5. Job Title
6. Emails
7. Phone
8. Personal Address (NEW)
9. City
10. State
11. Zip (NEW)
12. First Seen
13. Last Seen
14. Event Count
15. Last Visited URL (NEW)
16. Last Element (NEW)
17. Last Percentage (NEW)
18. Last Referrer (NEW)
19. Last Timestamp (NEW)
20. Last Event (NEW)
21. NPN (NEW)
22. CRD (NEW)

### Events Tab (Google Sheets)
1. Timestamp
2. Event Type
3. URL (NEW)
4. Elements (NEW)
5. Referrer (NEW)
6. IP Address
7. UUID
8. First Name
9. Last Name
10. Company
11. Job Title
12. Emails
13. Phone
14. City
15. State
16. HemSha256 (NEW) 