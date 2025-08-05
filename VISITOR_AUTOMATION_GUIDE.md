# 🤖 Visitor Automation Implementation Guide

## Overview
This guide documents the automated visitor creation/update system that eliminates manual processing when events arrive via webhook.

---

## 🎯 What's Automated

### Previously Manual Process:
1. Event arrives via webhook → Inserted into `superpixel_resolution_log`
2. PHP function manually called to create/update visitor in `superpixel_visitors`
3. Manual email parsing and NPN/CRD lookup
4. Manual sync to Google Sheets

### New Automated Process:
1. Event arrives via webhook → Inserted into `superpixel_resolution_log`
2. **Database trigger automatically fires** → Creates/updates visitor
3. Email parsing and NPN/CRD lookup (Priority 2 - coming next)
4. Sync to Google Sheets (runs periodically)

---

## 📋 Implementation Components

### 1. Database Trigger
**File:** `create_visitor_automation_trigger.sql`
**Name:** `after_resolution_log_insert_visitor_update`
**Type:** AFTER INSERT trigger on `superpixel_resolution_log`

#### Key Features:
- ✅ Creates new visitors for new UUIDs
- ✅ Updates existing visitors with new event data
- ✅ Preserves business emails over personal emails
- ✅ Only updates empty fields with new data
- ✅ Always updates "last" event fields (URL, element, percentage, etc.)
- ✅ Increments event count
- ✅ Updates last_seen_at timestamp

### 2. Deployment Script
**File:** `deploy_visitor_trigger_all.php`

#### Usage:
```bash
# Deploy to specific database
php deploy_visitor_trigger_all.php AcquireUp

# Deploy to all databases
php deploy_visitor_trigger_all.php --all
```

### 3. Test Script
**File:** `test_visitor_trigger.php`

Tests the trigger with synthetic data to verify:
- New visitor creation
- Existing visitor updates
- Business email preservation
- Event count tracking

### 4. Verification Script
**File:** `verify_visitor_automation.php`

#### Usage:
```bash
# Check all databases
php verify_visitor_automation.php

# Check specific database
php verify_visitor_automation.php AcquireUp
```

---

## 🚀 Deployment Steps

### Step 1: Test on Single Database
```bash
# Test on AcquireUp first
php test_visitor_trigger.php
```

### Step 2: Deploy to All Databases
```bash
# Deploy trigger to all client databases
php deploy_visitor_trigger_all.php --all
```

### Step 3: Verify Deployment
```bash
# Verify all triggers are active
php verify_visitor_automation.php
```

### Step 4: Backfill Historical Data (if needed)
```bash
# If you have events without visitors
php backfill_missing_visitors.php
```

---

## 📊 Column Behavior

### Always Updated Fields (Latest Event Data):
- `url` - Last visited URL
- `element` - Last clicked element
- `percentage` - Last scroll percentage
- `referrer` - Last referrer
- `event_timestamp` - Last event timestamp
- `event_type` - Last event type
- `event_count` - Incremented on each event
- `last_seen_at` - Current timestamp

### Preserved Fields (Not Overwritten if Exists):
- `business_email` - Business email always preserved over personal
- All other demographic and company fields - Only filled if empty

---

## 🔍 Monitoring & Troubleshooting

### Check Trigger Status:
```sql
-- Check if trigger exists
SHOW TRIGGERS LIKE 'after_resolution_log_insert_visitor_update';

-- View trigger definition
SHOW CREATE TRIGGER after_resolution_log_insert_visitor_update;
```

### Check Data Sync:
```sql
-- Find events without visitors
SELECT COUNT(*) FROM superpixel_resolution_log r
LEFT JOIN superpixel_visitors v ON r.uuid = v.uuid
WHERE r.uuid IS NOT NULL AND v.uuid IS NULL;
```

### Common Issues:

#### Trigger Not Firing:
- Check trigger exists: `php verify_visitor_automation.php`
- Check MySQL user has TRIGGER privilege
- Check for errors in MySQL error log

#### Visitors Not Created:
- Verify UUID is not null/empty in event
- Check for unique constraint violations
- Run backfill for historical data

#### Data Not Updating:
- Verify field names match between tables
- Check data types compatibility
- Review trigger logic for specific fields

---

## 🔧 Maintenance

### Disable Trigger (if needed):
```sql
DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update;
```

### Re-enable Trigger:
```bash
php deploy_visitor_trigger_all.php DATABASE_NAME
```

### Update Trigger Logic:
1. Edit `create_visitor_automation_trigger.sql`
2. Run deployment script to update all databases
3. Verify with test script

---

## 📈 Performance Considerations

### Current Implementation:
- Trigger fires synchronously on each INSERT
- Suitable for moderate traffic (< 100 events/second)
- No batching or queuing

### Future Optimizations (if needed):
- Queue-based processing for high-volume clients
- Batch visitor updates every N seconds
- Separate high/low volume client handling

---

## 🔒 Data Integrity Rules

1. **UUID Required**: Events without UUID don't create visitors
2. **Business Email Priority**: Business emails never overwritten by personal
3. **No Data Loss**: Empty values don't overwrite existing data
4. **Event Tracking**: Event count always increments
5. **Timestamp Accuracy**: last_seen_at always updates to current time

---

## 📝 Notes for Developers

### When Adding New Columns:
1. Add column to both tables (if applicable)
2. Update trigger to handle new column
3. Decide if it's a "preserve" or "update" field
4. Test with synthetic data
5. Deploy to all databases

### Integration with Other Systems:
- Email parsing triggers (Priority 2) will chain after this
- Google Sheets sync remains separate (cron-based)
- NPN/CRD lookup can be triggered or scheduled

---

## 🎯 Next Steps (Priority 2-5)

### Priority 2: Email Parsing Automation
- Trigger to parse emails from visitor data
- Automatic NPN/CRD lookup
- Update visitor record with matches

### Priority 3: Fix Column Mapping
- Update sync scripts for proper column mapping
- Fix "Last Seen" vs "Last Updated" confusion
- Ensure all "last" fields update correctly

### Priority 4: Optimize Column Selection
- Implement recommended column sets
- Update Google Sheets templates
- Document column purposes

### Priority 5: Performance Optimization
- Implement queue for high-volume clients
- Add monitoring and alerting
- Create performance dashboards

---

## 📞 Support

For issues or questions:
1. Check verification script first: `php verify_visitor_automation.php`
2. Review this documentation
3. Check MySQL error logs
4. Test with synthetic data before production changes

---

*Last Updated: [Current Date]*
*Version: 1.0* 