-- Add poll_segments column to pixel_sheets
ALTER TABLE `pixel`.`pixel_sheets` 
ADD COLUMN IF NOT EXISTS `poll_segments` TINYINT(1) DEFAULT 1 AFTER `last_sync_at`;

-- Robust Deduplication for superpixel_resolution_log
-- Adding a composite unique index ensures that the same event 
-- cannot be uploaded twice, even if the script is run multiple times.
-- Note: This should be run on each individual client database.

ALTER TABLE `superpixel_resolution_log` 
ADD UNIQUE INDEX IF NOT EXISTS `idx_robust_dedupe` (`uuid`, `event_timestamp`, `event_type`, `pixel_id`);

-- Optional: Add index to visitors for faster upserts
ALTER TABLE `superpixel_visitors` 
ADD UNIQUE INDEX IF NOT EXISTS `idx_uuid` (`uuid`);
