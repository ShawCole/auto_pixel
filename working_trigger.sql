DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update;

CREATE TRIGGER after_resolution_log_insert_visitor_update
AFTER INSERT ON superpixel_resolution_log
FOR EACH ROW
BEGIN
    IF NEW.uuid IS NOT NULL AND NEW.uuid != '' AND NEW.uuid != 'null' THEN
        IF EXISTS (SELECT 1 FROM superpixel_visitors WHERE uuid = NEW.uuid) THEN
            UPDATE superpixel_visitors 
            SET
                first_name = COALESCE(NULLIF(first_name, ''), NEW.first_name),
                last_name = COALESCE(NULLIF(last_name, ''), NEW.last_name),
                business_email = CASE 
                    WHEN business_email IS NOT NULL AND business_email != '' THEN business_email 
                    ELSE NEW.business_email 
                END,
                url = CASE WHEN NEW.url IS NOT NULL AND NEW.url != '' THEN NEW.url ELSE url END,
                element = CASE WHEN NEW.element IS NOT NULL AND NEW.element != '' THEN NEW.element ELSE element END,
                percentage = CASE 
                    WHEN NEW.percentage IS NOT NULL AND NEW.percentage != '' THEN CAST(NEW.percentage AS SIGNED)
                    ELSE percentage 
                END,
                referrer = CASE WHEN NEW.referrer IS NOT NULL AND NEW.referrer != '' THEN NEW.referrer ELSE referrer END,
                event_timestamp = CASE WHEN NEW.event_timestamp IS NOT NULL AND NEW.event_timestamp != '' THEN NEW.event_timestamp ELSE event_timestamp END,
                event_type = CASE WHEN NEW.event_type IS NOT NULL AND NEW.event_type != '' THEN NEW.event_type ELSE event_type END,
                event_count = event_count + 1,
                last_seen_at = CURRENT_TIMESTAMP
            WHERE uuid = NEW.uuid;
        ELSE
            INSERT INTO superpixel_visitors (
                uuid, first_name, last_name, business_email,
                url, element, percentage, referrer, event_timestamp, event_type,
                event_count, first_seen_at, last_seen_at
            ) VALUES (
                NEW.uuid, NEW.first_name, NEW.last_name, NEW.business_email,
                NEW.url, NEW.element, 
                CASE WHEN NEW.percentage IS NOT NULL AND NEW.percentage != '' THEN CAST(NEW.percentage AS SIGNED) ELSE NULL END,
                NEW.referrer, NEW.event_timestamp, NEW.event_type,
                1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            );
        END IF;
    END IF;
END; 