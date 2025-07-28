-- Safe update for template database (pixel) - handles existing columns
USE pixel;

-- Add columns to superpixel_resolution_log template (safe version)
SET @sql = '';
SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_resolution_log' AND column_name = 'npn';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_resolution_log ADD COLUMN npn VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column npn already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_resolution_log' AND column_name = 'crd';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_resolution_log ADD COLUMN crd VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column crd already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_resolution_log' AND column_name = 'elements';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_resolution_log ADD COLUMN elements TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column elements already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add columns to superpixel_visitors template (safe version)
SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'hem_sha256';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN hem_sha256 TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column hem_sha256 already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'last_visited_url';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN last_visited_url VARCHAR(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column last_visited_url already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'last_element';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN last_element TEXT CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column last_element already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'last_percentage';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN last_percentage INT DEFAULT NULL;', 'SELECT "Column last_percentage already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'last_referrer';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN last_referrer VARCHAR(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column last_referrer already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'last_timestamp';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN last_timestamp VARCHAR(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column last_timestamp already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'last_event';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN last_event VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column last_event already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'npn';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN npn VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column npn already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @exists FROM information_schema.columns 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND column_name = 'crd';
SET @sql = IF(@exists = 0, 'ALTER TABLE superpixel_visitors ADD COLUMN crd VARCHAR(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;', 'SELECT "Column crd already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create indexes (safe - will ignore if they exist)
CREATE INDEX IF NOT EXISTS idx_npn ON superpixel_resolution_log(npn);
CREATE INDEX IF NOT EXISTS idx_crd ON superpixel_resolution_log(crd);
CREATE INDEX IF NOT EXISTS idx_visitor_npn ON superpixel_visitors(npn);
CREATE INDEX IF NOT EXISTS idx_visitor_crd ON superpixel_visitors(crd);

-- Check if hem_sha256 index exists before creating it (MySQL doesn't support IF NOT EXISTS for all index types)
SET @sql = '';
SELECT COUNT(*) INTO @exists FROM information_schema.statistics 
WHERE table_schema = 'pixel' AND table_name = 'superpixel_visitors' AND index_name = 'idx_visitor_hem';
SET @sql = IF(@exists = 0, 'CREATE INDEX idx_visitor_hem ON superpixel_visitors(hem_sha256(255));', 'SELECT "Index idx_visitor_hem already exists";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Template database update completed successfully' as status; 