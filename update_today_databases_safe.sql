-- Update today's test databases with missing columns
-- Safe version - manually add columns one by one (skip if already exist)

-- Add missing columns to TEST_NEW_COLUMNS.superpixel_visitors
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN hem_sha256 text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN last_visited_url varchar(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN last_element text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN last_percentage int DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN last_referrer varchar(1000) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN last_timestamp varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN last_event varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN npn varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_visitors ADD COLUMN crd varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;

-- Add missing columns to TEST_NEW_COLUMNS.superpixel_resolution_log
ALTER TABLE TEST_NEW_COLUMNS.superpixel_resolution_log ADD COLUMN elements text CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_resolution_log ADD COLUMN npn varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;
ALTER TABLE TEST_NEW_COLUMNS.superpixel_resolution_log ADD COLUMN crd varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL;

SELECT 'TEST_NEW_COLUMNS updated' as status; 