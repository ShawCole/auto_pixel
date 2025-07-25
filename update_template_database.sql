-- Update template database (pixel) to ensure all new client databases get the new columns
USE pixel;

-- Add columns to superpixel_resolution_log template
ALTER TABLE superpixel_resolution_log ADD COLUMN npn VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_resolution_log ADD COLUMN crd VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_resolution_log ADD COLUMN elements TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;

-- Add columns to superpixel_visitors template
ALTER TABLE superpixel_visitors ADD COLUMN hem_sha256 TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_visited_url VARCHAR(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_element TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_percentage INT DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_referrer VARCHAR(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_timestamp VARCHAR(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN last_event VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN npn VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE superpixel_visitors ADD COLUMN crd VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;

-- Create indexes on template tables
CREATE INDEX IF NOT EXISTS idx_npn ON superpixel_resolution_log(npn);
CREATE INDEX IF NOT EXISTS idx_crd ON superpixel_resolution_log(crd);
CREATE INDEX IF NOT EXISTS idx_visitor_npn ON superpixel_visitors(npn);
CREATE INDEX IF NOT EXISTS idx_visitor_crd ON superpixel_visitors(crd);
CREATE INDEX IF NOT EXISTS idx_visitor_hem ON superpixel_visitors(hem_sha256(255)); 