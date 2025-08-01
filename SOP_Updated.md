# Using SSH for Pixel-php's auto-pixel program

## 📊 **Monitoring & Logs**

### Monitor all critical logs simultaneously (in separate terminals)
```bash
tail -f /opt/auto-pixel/sync.log          # Smart sync system logs
tail -f /opt/auto-pixel/monitor.log       # New sheet detection logs  
tail -f /var/www/hook.thynkdata.com/pixel_import_debug.log  # Webhook processing logs
pm2 logs auto-pixel-api --lines 50        # Node.js API server logs
```

### Check if all processes are running
```bash
pm2 status                    # Node.js API server status
ps aux | grep smart_sync      # Smart sync process
ps aux | grep monitor_new     # Monitor process
crontab -l                    # Scheduled tasks

# Check database connectivity
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' -e "SELECT 'DB Connected' as status;"
```

## 🔄 **Sync Operations**

### Force sync operations
```bash
# Force immediate sync of all sheets
php force_sync.php

# Force sync specific client
php force_sync.php --client=CLIENT_NAME

# Force immediate sync of newest sheets
php smart_sync.php

# Monitor new sheets (runs continuously)
nohup php monitor_new_sheets.php > /dev/null 2>&1 &
```

## 🗄️ **Database Operations**

### Check client database counts
```bash
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT COUNT(*) as events FROM superpixel_resolution_log; SELECT COUNT(*) as visitors FROM superpixel_visitors;"

# Check specific client data
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT uuid, first_name, last_name, url, element FROM superpixel_visitors LIMIT 5;"

# Check events
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT uuid, event_type, url, timestamp FROM superpixel_resolution_log ORDER BY id DESC LIMIT 5;"
```

## 🧪 **Testing & Debugging**

### Clear logs and test webhook
```bash
# Clear webhook logs
sudo truncate -s 0 /var/www/hook.thynkdata.com/pixel_import_debug.log

# Test webhook with URL parameter (RECOMMENDED)
curl -X POST "https://hook.thynkdata.com/pixel_import.php?client=CLIENT_NAME" \
-H "Content-Type: application/json" \
-d '{
  "resolution": [
    {
      "UUID": "test-visitor-001",
      "FIRST_NAME": "John",
      "LAST_NAME": "Doe",
      "visited_url": "https://example.com/page",
      "element": "Button Click",
      "percentage": 85,
      "referrer": "https://google.com",
      "timestamp": "'$(date +%s)'",
      "event_type": "interaction",
      "ip_address": "192.168.1.1"
    }
  ]
}'

# Test webhook with database in payload (NOT RECOMMENDED - doesn't work)
curl -X POST "https://hook.thynkdata.com/pixel_import.php" \
-H "Content-Type: application/json" \
-d '{
  "database": "CLIENT_NAME",
  "resolution": [
    {
      "UUID": "test-visitor-002",
      "FIRST_NAME": "Jane",
      "LAST_NAME": "Smith",
      "visited_url": "https://example.com/contact",
      "element": "Contact Form",
      "percentage": 90,
      "referrer": "https://facebook.com",
      "timestamp": "'$(date +%s)'",
      "event_type": "form_view",
      "ip_address": "10.0.0.1"
    }
  ]
}'
```

### Test API endpoints
```bash
# Test pixel generation
curl -X POST http://localhost:4000/generate \
-H "Content-Type: application/json" \
-d '{"client":"TEST_CLIENT_NEW","website":"https://example.com"}'

# Check API status
curl http://localhost:4000/health
```

## 🔧 **Diagnostic & Maintenance Scripts**

### Check for problematic database schemas
```bash
# Identify databases with schema issues causing sync failures
php check_problematic_sheets.php

# This script checks for:
# - Missing databases referenced in pixel_sheets
# - Missing required tables (superpixel_resolution_log, superpixel_visitors)
# - Missing required columns (id, uuid, url, element, event_type)
# - Provides commands to fix or remove problematic entries
```

### Clean up problematic clients
```bash
# Delete a client completely from the system
php delete_client.php 'CLIENT_NAME'

# This script:
# - Removes client from pixel_sheets table
# - Clears all data from client's superpixel_visitors table
# - Clears all data from client's superpixel_resolution_log table
# - Handles cases where client database doesn't exist
# - Provides detailed progress feedback

# Example usage:
php delete_client.php 'Test_Client_To_Remove'
php delete_client.php 'Active_Wealth_Management-Retirement_Results'
```

### Fix common schema issues
```bash
# Add missing columns to existing databases
mysql -h 34.31.66.104 -u root -pAccuPoint01! -e "USE CLIENT_NAME; ALTER TABLE superpixel_resolution_log ADD COLUMN url text AFTER event_type;"
mysql -h 34.31.66.104 -u root -pAccuPoint01! -e "USE CLIENT_NAME; ALTER TABLE superpixel_resolution_log ADD COLUMN element text AFTER url;"
mysql -h 34.31.66.104 -u root -pAccuPoint01! -e "USE CLIENT_NAME; ALTER TABLE superpixel_resolution_log ADD COLUMN percentage int AFTER element;"
mysql -h 34.31.66.104 -u root -pAccuPoint01! -e "USE CLIENT_NAME; ALTER TABLE superpixel_resolution_log ADD COLUMN title text AFTER timestamp;"
```

## 🚨 **Troubleshooting Workflow**

### Step 1: Identify Issues
```bash
# Check for problematic databases
php check_problematic_sheets.php

# Monitor sync logs for errors
tail -f /opt/auto-pixel/sync.log | grep "❌\|Error"
```

### Step 2: Common Issues & Fixes

**"Unknown column 'url/element/percentage' in 'field list'"**
```bash
# Add missing columns
mysql -h 34.31.66.104 -u root -pAccuPoint01! -e "USE CLIENT_NAME; ALTER TABLE superpixel_resolution_log ADD COLUMN MISSING_COLUMN text;"
```

**"Unknown database 'CLIENT_NAME'"**
```bash
# Remove orphaned entry from pixel_sheets
php delete_client.php 'CLIENT_NAME'
```

**"Sync failed for CLIENT_NAME"**
```bash
# Check if database and tables exist
mysql -h 34.31.66.104 -u root -pAccuPoint01! -e "USE CLIENT_NAME; SHOW TABLES;"

# If missing, run diagnostic script
php check_problematic_sheets.php
```

### Step 3: Verify Fixes
```bash
# Re-run diagnostic script
php check_problematic_sheets.php

# Should show: "✅ No problematic databases found!"

# Monitor sync logs for clean operation
tail -f /opt/auto-pixel/sync.log
```

## 🧹 **Maintenance Operations**

### Clear logs and restart services
```bash
# Clear logs
sudo truncate -s 0 /var/www/hook.thynkdata.com/pixel_import_debug.log
sudo truncate -s 0 /opt/auto-pixel/sync.log

# Restart services
pm2 restart all
pm2 restart auto-pixel-api

# Check disk space
df -h
du -sh /opt/auto-pixel/
```

## 📋 **Quick Reference**

### Essential monitoring commands
```bash
# Terminal 1: Smart sync logs
tail -f /opt/auto-pixel/sync.log

# Terminal 2: Monitor logs  
tail -f /opt/auto-pixel/monitor.log

# Terminal 3: Webhook logs
tail -f /var/www/hook.thynkdata.com/pixel_import_debug.log

# Terminal 4: API server logs
pm2 logs auto-pixel-api --lines 50
```

### Force operations
```bash
# Force sync all sheets
php force_sync.php

# Run smart sync manually
php smart_sync.php

# Start monitor process
nohup php monitor_new_sheets.php > /dev/null 2>&1 &

# Check system status
pm2 status
crontab -l
```

### Database checks
```bash
# Check client counts
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT COUNT(*) as events FROM superpixel_resolution_log; SELECT COUNT(*) as visitors FROM superpixel_visitors;"

# Check visitor data
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT uuid, first_name, last_name, url, element FROM superpixel_visitors LIMIT 5;"

# Check event data
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT uuid, event_type, url, timestamp FROM superpixel_resolution_log ORDER BY id DESC LIMIT 5;"
```

### Test webhook
```bash
curl -X POST "https://hook.thynkdata.com/pixel_import.php?client=TEST_CLIENT" \
-H "Content-Type: application/json" \
-d '{
  "resolution": [
    {
      "UUID": "test-visitor-001",
      "FIRST_NAME": "John",
      "LAST_NAME": "Doe",
      "visited_url": "https://example.com/page",
      "element": "Button Click",
      "percentage": 85,
      "referrer": "https://google.com",
      "timestamp": "'$(date +%s)'",
      "event_type": "interaction",
      "ip_address": "192.168.1.1"
    }
  ]
}'
```

### Test API
```bash
curl -X POST http://localhost:4000/generate \
-H "Content-Type: application/json" \
-d '{"client":"TEST_CLIENT_NEW","website":"https://example.com"}'
```

---

## 📁 **Script Files Reference**

### Core Scripts
- `smart_sync.php` - Main sync orchestrator
- `force_sync.php` - Manual sync trigger
- `monitor_new_sheets.php` - New sheet detection
- `pixel_import.php` - Webhook for data ingestion

### Diagnostic Scripts  
- `check_problematic_sheets.php` - **NEW** - Identify schema issues
- `delete_client.php` - **NEW** - Clean up problematic clients

### Maintenance Scripts
- `reset_sheet_view.php` - Reset Google Sheets to default view
- `backfill_missing_visitors.php` - Backfill missing visitor records

**Last Updated**: July 31, 2025  
**Version**: 1.1.0 - Added diagnostic and maintenance scripts 