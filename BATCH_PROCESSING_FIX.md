# Batch Processing Issue - FIXED

## The Problem
The webhook handler was causing what appeared to be duplicate events because it was processing `processVisitorEmails()` synchronously for each event in a batch. This function takes ~8 seconds per call to:
1. Parse emails from the visitor record
2. Look up NPN/CRD in the match_emails table  
3. Update the superpixel_resolution_log with found values

When AudienceLab sends a batch of 4 events (all with the same `event_timestamp`), they were being processed sequentially:
- Event 1: Insert → 8 second email processing → Event 2: Insert → 8 second email processing → etc.

This created the pattern you observed:
- All 4 events have the same `event_timestamp` (from AudienceLab batch)
- But `created_at` times are 8 seconds apart (due to sequential processing)
- Same IP addresses for the batch (they're from the same AudienceLab processing run)

## The Solution
The optimized webhook handler (`pixel_import_optimized.php`) fixes this by:

1. **First Phase - Insert all events quickly**
   - Process all events in the batch
   - Insert them into superpixel_resolution_log immediately
   - Collect unique UUIDs for later processing
   - No delays between insertions

2. **Second Phase - Batch email processing**
   - After ALL events are inserted
   - Process emails/NPN/CRD lookups for unique UUIDs only
   - Doesn't delay the webhook response significantly

## Benefits
- Events from the same batch maintain similar `created_at` times
- No more 8-second gaps between related events
- Faster webhook response times
- More efficient processing (each UUID processed once even if multiple events)

## Implementation
To deploy this fix:

```bash
# On the server
sudo cp /var/www/hook.thynkdata.com/pixel_import.php /var/www/hook.thynkdata.com/pixel_import_backup.php
sudo cp /opt/auto-pixel/pixel_import_optimized.php /var/www/hook.thynkdata.com/pixel_import.php
sudo chown www-data:www-data /var/www/hook.thynkdata.com/pixel_import.php
sudo chmod 644 /var/www/hook.thynkdata.com/pixel_import.php
```

## Testing
Test with a curl command to verify batch processing works correctly:

```bash
curl -X POST "https://hook.thynkdata.com/pixel_import.php?client=AcquireUp" \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "pixel_id": "test-batch-1",
        "event_timestamp": "2025-08-08T12:00:00Z",
        "event_type": "page_view",
        "ip_address": "192.168.1.1",
        "resolution": {
          "UUID": "batch_test_uuid_1",
          "FIRST_NAME": "Test1",
          "LAST_NAME": "Batch1"
        }
      },
      {
        "pixel_id": "test-batch-2",
        "event_timestamp": "2025-08-08T12:00:00Z",
        "event_type": "page_view",
        "ip_address": "192.168.1.1",
        "resolution": {
          "UUID": "batch_test_uuid_2",
          "FIRST_NAME": "Test2",
          "LAST_NAME": "Batch2"
        }
      }
    ]
  }'
```

Then check that both events have similar `created_at` times:

```sql
SELECT uuid, first_name, created_at 
FROM superpixel_resolution_log 
WHERE uuid LIKE 'batch_test_uuid_%' 
ORDER BY created_at DESC;
``` 