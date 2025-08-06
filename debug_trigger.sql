-- Debug version to find the STR_TO_DATE error
DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update;

DELIMITER $$

CREATE TRIGGER after_resolution_log_insert_visitor_update 
AFTER INSERT ON superpixel_resolution_log 
FOR EACH ROW 
BEGIN
    DECLARE debug_msg VARCHAR(500);
    
    -- Debug: Check what values we're getting
    SET debug_msg = CONCAT('UUID: ', IFNULL(NEW.uuid, 'NULL'), 
                          ', event_timestamp: ', IFNULL(NEW.event_timestamp, 'NULL'),
                          ', activity_start: ', IFNULL(NEW.activity_start_date, 'NULL'),
                          ', activity_end: ', IFNULL(NEW.activity_end_date, 'NULL'));
    
    -- Log to a table (we'll create this for debugging)
    INSERT INTO debug_log (message, created_at) VALUES (debug_msg, NOW());
    
    -- For now, skip the actual visitor update to isolate the issue
END$$

DELIMITER ;

-- Create debug log table if it doesn't exist
CREATE TABLE IF NOT EXISTS debug_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
); 