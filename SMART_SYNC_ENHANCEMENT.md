# Smart Sync Enhancement - Visitor Logic

## 🎯 **Problem Identified**
The scheduled sync script (`smart_sync.php`) was only pushing data from database → Google Sheets without ensuring visitor consistency.

## ✅ **Solution Implemented**

### **Enhanced `smart_sync.php` with Visitor Consistency**

### **What Was Added:**

1. **Standardized Visitor Functions Integration**
   ```php
   require_once __DIR__ . '/visitor_upsert_functions.php';
   ```

2. **Configurable Visitor Consistency Checks**
   ```php
   $VISITOR_CONSISTENCY_CHECK = true;     // Enable/disable feature
   $VISITOR_BACKFILL_LIMIT = 500;         // Max visitors per client per run
   $VISITOR_CHECK_FREQUENCY = 5;          // Check every N runs (every 10 minutes)
   ```

3. **Pre-Sync Visitor Consistency Logic**
   - Checks for missing visitors before syncing to sheets
   - Automatically backfills missing visitor records
   - Runs every 10 minutes (configurable) to avoid overhead
   - **Always runs** for new sheets to ensure immediate consistency

### **New Sync Flow:**

```
🔄 smart_sync.php (every 2 minutes via cron)
     ↓
🔍 Check visitor consistency (every 10 minutes)
     ↓
🛠️  Backfill missing visitors (if any)
     ↓
📊 Sync Visitors tab → Google Sheets
     ↓
📝 Sync Events tab → Google Sheets
     ↓
⏱️  Update last_sync_at timestamp
```

### **Sample Output:**

```
=== Syncing AcquireUp ===
Started at: 2025-08-01 10:20:02
🔍 Checking visitor consistency for AcquireUp...
✅ Visitor consistency OK (no missing visitors)
Updating both tabs (Visitors + Events) for AcquireUp...
Syncing visitors for AcquireUp (limit: 10000)...
Updated 68 visitor records (max: 10000)
Syncing events for AcquireUp (limit: 100000)...
No new event data to sync
✅ Sync completed successfully for AcquireUp

=== Syncing Country_Life ===  
Started at: 2025-08-01 10:20:18
🔍 Checking visitor consistency for Country_Life...
✅ Backfilled 15 missing visitors
Updating both tabs (Visitors + Events) for Country_Life...
...
```

## ⚙️ **Configuration Options**

### **Enable/Disable Feature:**
```php
$VISITOR_CONSISTENCY_CHECK = false; // Disable if causing issues
```

### **Adjust Frequency:**
```php
$VISITOR_CHECK_FREQUENCY = 3; // Check every 6 minutes instead of 10
```

### **Adjust Backfill Limit:**
```php
$VISITOR_BACKFILL_LIMIT = 1000; // Process more visitors per run
```

## 📊 **Benefits**

✅ **Proactive Consistency** - Fixes visitor gaps before they affect sheets  
✅ **Configurable Performance** - Frequency control prevents overhead  
✅ **New Sheet Priority** - Always checks consistency for new sheets  
✅ **Detailed Logging** - Clear output shows what's happening  
✅ **Backward Compatible** - Can be disabled if needed  

## 🔄 **Current Cron Schedule**

```bash
*/2 * * * * /usr/bin/php /opt/auto-pixel/smart_sync.php >> /opt/auto-pixel/sync.log 2>&1
```

- **Runs every 2 minutes**
- **Visitor checks every 10 minutes** (configurable)
- **Logs to** `/opt/auto-pixel/sync.log`

## 📝 **Monitoring**

Check the sync log to monitor visitor consistency fixes:

```bash
tail -f /opt/auto-pixel/sync.log | grep -E "(Backfilled|consistency)"
```

## 🎉 **Result**

The scheduled sync script now **automatically maintains 100% visitor consistency** across all client databases while syncing to Google Sheets! 