# Visitor Consistency Solution

## 🚨 **The Problem**

Previously, some scripts were inserting events into `superpixel_resolution_log` **without** creating corresponding visitor records in `superpixel_visitors`. This led to:

- ❌ Events with UUIDs but no visitor records
- ❌ Inaccurate visitor counts in Google Sheets 
- ❌ Coverage gaps (e.g., Country_Life had 0% visitor coverage)
- ❌ Inconsistent behavior across scripts

## ✅ **The Solution**

### **1. Standardized Visitor Functions** (`visitor_upsert_functions.php`)
A reusable library with consistent visitor logic:

- **`upsertVisitorFromEvent()`** - Creates/updates a visitor from event data
- **`batchUpsertVisitorsFromEvents()`** - Processes multiple events
- **`backfillMissingVisitors()`** - Fixes missing historical visitors

### **2. Fixed Problematic Scripts**
- ✅ **`pixel_import_re.php`** - Added visitor upsert logic
- ✅ **`backfill_missing_visitors.php`** - Updated to use standardized functions

### **3. Comprehensive Audit Tool** (`ensure_visitor_consistency.php`)
- Audits ALL client databases for visitor consistency
- Automatically fixes issues when run in live mode
- Provides detailed reporting and coverage statistics

## 🛠️ **How to Use**

### **Audit All Databases (Dry Run)**
```bash
php ensure_visitor_consistency.php --dry-run
```

### **Audit Specific Client (Dry Run)**
```bash
php ensure_visitor_consistency.php ClientName --dry-run
```

### **Fix All Issues (Live Mode)**
```bash
php ensure_visitor_consistency.php
```

### **Fix Specific Client (Live Mode)**
```bash
php ensure_visitor_consistency.php ClientName
```

### **Manual Backfill (Original Script)**
```bash
php backfill_missing_visitors.php ClientName
```

## 📊 **Sample Output**

```
=== Visitor Consistency Audit & Fix ===
Mode: LIVE (will fix issues)
Target: ALL clients

📋 Found 45 client database(s)

=== Auditing AcquireUp ===
📊 Events: 783 total, 783 with UUID
👥 Unique UUIDs: 60
👤 Visitors: 60 (Coverage: 100.0%)
⚠️  Missing: 0 visitors
🎯 Status: GOOD

=== Auditing Country_Life ===
📊 Events: 7891 total, 7891 with UUID
👥 Unique UUIDs: 3916
👤 Visitors: 0 (Coverage: 0.0%)
⚠️  Missing: 3916 visitors
🎯 Status: CRITICAL
🔧 Fixing missing visitors...
✅ Backfilled 3916 visitors (errors: 0)
📈 Updated coverage: 100.0%

=== SUMMARY REPORT ===
Database                  Events   UUIDs    Visitors   Missing  Status
--------------------------------------------------------------------------------
AcquireUp                 783      60       60         0        ✅ GOOD
Country_Life              7891     3916     3916       0        ✅ GOOD
VettaFi                   3        2        2          0        ✅ GOOD
...
```

## 🔧 **For Developers**

### **When Adding New Scripts**
If you create scripts that insert into `superpixel_resolution_log`:

1. **Include the library:**
   ```php
   require_once __DIR__ . '/visitor_upsert_functions.php';
   ```

2. **After each event insert:**
   ```php
   // Insert event
   $mysqli->query("INSERT INTO superpixel_resolution_log...");
   
   // Create/update visitor
   upsertVisitorFromEvent($mysqli, $event_data, "context_info");
   ```

### **Schema-Aware Design**
All functions automatically detect available columns and only use what exists, making them compatible with:
- ✅ Old database schemas
- ✅ New database schemas  
- ✅ Partial schema updates
- ✅ Custom client configurations

## 🎯 **Coverage Targets**

- **✅ GOOD**: 100% coverage (all UUIDs have visitors)
- **⚠️ WARNING**: 90-99% coverage (minor gaps)
- **❌ CRITICAL**: <90% coverage (major issues)

## 🚀 **Automated Monitoring**

You can schedule regular consistency checks:

```bash
# Daily audit (dry run for monitoring)
0 2 * * * /usr/bin/php /opt/auto-pixel/ensure_visitor_consistency.php --dry-run >> /var/log/visitor-audit.log 2>&1

# Weekly fix (live mode)  
0 3 * * 0 /usr/bin/php /opt/auto-pixel/ensure_visitor_consistency.php >> /var/log/visitor-fix.log 2>&1
```

## 📝 **Updated Scripts**

### **Scripts WITH Visitor Logic** ✅
- `pixel_import.php` - Main webhook (was already good)
- `pixel_import_final.php` - Fixed version (was already good)  
- `pixel_import_re.php` - **FIXED** ✅
- `backfill_missing_visitors.php` - **UPDATED** ✅

### **Scripts That Only Read Data** (No Changes Needed)
- `dynamic_sync.php` - Syncs to Google Sheets
- `sheets_sync_optimized.php` - Syncs to Google Sheets
- `reset_sheet_view.php` - Resets Google Sheets

## 🔄 **Data Flow**

```
📝 Events → superpixel_resolution_log
     ↓ (automatic via pixel_import.php)
👥 Visitors → superpixel_visitors  
     ↓ (automatic via dynamic_sync.php)
📊 Google Sheets
```

## 🚨 **Troubleshooting**

### **If Coverage Issues Persist:**
1. Check if `pixel_import.php` is being used (not older versions)
2. Run `ensure_visitor_consistency.php` to fix historical data
3. Verify database schema has all required columns
4. Check error logs for visitor upsert failures

### **Common Issues:**
- **Missing columns**: Functions skip missing fields gracefully
- **Old events**: Use backfill functions to create missing visitors
- **Permission errors**: Ensure database user has INSERT/UPDATE rights

This solution ensures **100% visitor consistency** across all current and future client databases! 🎉 