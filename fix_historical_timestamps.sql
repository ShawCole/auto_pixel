-- Fix historical timestamp data in superpixel_visitors
-- This updates last_seen_at to match the most recent event_timestamp for each visitor

-- Update last_seen_at to the most recent event timestamp for each UUID
UPDATE superpixel_visitors v
INNER JOIN (
    SELECT 
        uuid,
        MAX(created_at) as latest_created_at,
        MAX(CASE 
            WHEN event_timestamp IS NOT NULL 
                AND event_timestamp != '' 
                AND event_timestamp REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
            THEN event_timestamp 
            ELSE NULL 
        END) as latest_event_timestamp
    FROM superpixel_resolution_log
    WHERE uuid IS NOT NULL AND uuid != ''
    GROUP BY uuid
) latest_events ON v.uuid = latest_events.uuid
SET 
    v.last_seen_at = CASE 
        WHEN latest_events.latest_event_timestamp IS NOT NULL 
            AND latest_events.latest_event_timestamp != ''
            AND latest_events.latest_event_timestamp REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
        THEN STR_TO_DATE(latest_events.latest_event_timestamp, '%Y-%m-%dT%H:%i:%sZ')
        ELSE latest_events.latest_created_at
    END
WHERE v.uuid IS NOT NULL;

-- Update first_seen_at to the earliest event timestamp for each UUID
UPDATE superpixel_visitors v
INNER JOIN (
    SELECT 
        uuid,
        MIN(created_at) as earliest_created_at,
        MIN(CASE 
            WHEN event_timestamp IS NOT NULL 
                AND event_timestamp != '' 
                AND event_timestamp REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
            THEN event_timestamp 
            ELSE NULL 
        END) as earliest_event_timestamp
    FROM superpixel_resolution_log
    WHERE uuid IS NOT NULL AND uuid != ''
    GROUP BY uuid
) earliest_events ON v.uuid = earliest_events.uuid
SET 
    v.first_seen_at = CASE 
        WHEN earliest_events.earliest_event_timestamp IS NOT NULL 
            AND earliest_events.earliest_event_timestamp != ''
            AND earliest_events.earliest_event_timestamp REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
        THEN STR_TO_DATE(earliest_events.earliest_event_timestamp, '%Y-%m-%dT%H:%i:%sZ')
        ELSE earliest_events.earliest_created_at
    END
WHERE v.uuid IS NOT NULL;

SELECT 'Historical timestamps fixed!' as status; 