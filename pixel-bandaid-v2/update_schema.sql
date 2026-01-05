-- Add OPLET columns to pixel_sheets
ALTER TABLE `pixel`.`pixel_sheets` ADD COLUMN `on_platform_url` VARCHAR(255) AFTER `segment_api`;
ALTER TABLE `pixel`.`pixel_sheets` ADD COLUMN `oplet` VARCHAR(64) NULL AFTER `on_platform_url`;
ALTER TABLE `pixel`.`pixel_sheets` ADD COLUMN `oplet_polling_active` TINYINT(1) DEFAULT 1 AFTER `oplet`;

-- Robust Deduplication for superpixel_resolution_log
-- Adding a composite unique index ensures that the same event 
-- cannot be uploaded twice, even if the script is run multiple times.
-- Note: This should be run on each individual client database.

-- ALTER TABLE `superpixel_resolution_log` 
-- ADD UNIQUE INDEX `idx_robust_dedupe` (`uuid`, `event_timestamp`, `event_type`, `pixel_id`);
