-- Simple fix for historical timestamps using created_at
-- This avoids STR_TO_DATE issues by using the already-parsed created_at column

-- Fix last_seen_at to use the most recent created_at for each visitor
UPDATE superpixel_visitors v
INNER JOIN (
    SELECT 
        uuid,
        MAX(created_at) as latest_visit
    FROM superpixel_resolution_log
    WHERE uuid IS NOT NULL AND uuid != ''
    GROUP BY uuid
) latest ON v.uuid = latest.uuid
SET v.last_seen_at = latest.latest_visit
WHERE v.uuid IS NOT NULL;

-- Fix first_seen_at to use the earliest created_at for each visitor
UPDATE superpixel_visitors v
INNER JOIN (
    SELECT 
        uuid,
        MIN(created_at) as first_visit
    FROM superpixel_resolution_log
    WHERE uuid IS NOT NULL AND uuid != ''
    GROUP BY uuid
) earliest ON v.uuid = earliest.uuid
SET v.first_seen_at = earliest.first_visit
WHERE v.uuid IS NOT NULL;

SELECT 'Historical timestamps fixed using created_at!' as status; 