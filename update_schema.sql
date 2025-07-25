-- Update schema for superpixel_resolution_log table
-- Add NPN and CRD columns
ALTER TABLE superpixel_resolution_log 
ADD COLUMN IF NOT EXISTS npn VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS crd VARCHAR(255) DEFAULT NULL;

-- Update schema for superpixel_visitors table
-- Add all the new columns needed
ALTER TABLE superpixel_visitors
ADD COLUMN IF NOT EXISTS last_visited_url VARCHAR(1000) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_element TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_percentage INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_referrer VARCHAR(1000) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_timestamp DATETIME DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_event VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS npn VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS crd VARCHAR(255) DEFAULT NULL;

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_npn ON superpixel_resolution_log(npn);
CREATE INDEX IF NOT EXISTS idx_crd ON superpixel_resolution_log(crd);
CREATE INDEX IF NOT EXISTS idx_visitor_npn ON superpixel_visitors(npn);
CREATE INDEX IF NOT EXISTS idx_visitor_crd ON superpixel_visitors(crd); 