# Visitor Consistency - Quick Reference 🚀

## **Most Common Commands**

### 🔍 **Check All Databases (Safe)**
```bash
php ensure_visitor_consistency.php --dry-run
```

### 🛠️ **Fix All Issues (Live)**
```bash
php ensure_visitor_consistency.php
```

### 🎯 **Check Specific Client (Safe)**
```bash
php ensure_visitor_consistency.php AcquireUp --dry-run
```

### ⚡ **Fix Specific Client (Live)**
```bash
php ensure_visitor_consistency.php AcquireUp
```

### 📊 **Original Backfill Script**
```bash
php backfill_missing_visitors.php AcquireUp
```

---

## **Status Indicators**
- ✅ **GOOD**: 100% coverage - All visitors created
- ⚠️ **WARNING**: 90-99% coverage - Minor gaps  
- ❌ **CRITICAL**: <90% coverage - Major issues

---

## **New Script Requirements**
When creating scripts that insert events:

```php
require_once __DIR__ . '/visitor_upsert_functions.php';

// After inserting event:
upsertVisitorFromEvent($mysqli, $event_data, "context");
```

---

## **Files Created/Updated** ✅
- ✅ `visitor_upsert_functions.php` - Standardized functions
- ✅ `ensure_visitor_consistency.php` - Audit & fix tool
- ✅ `pixel_import_re.php` - Fixed visitor logic  
- ✅ `backfill_missing_visitors.php` - Updated
- ✅ `VISITOR_CONSISTENCY_GUIDE.md` - Full documentation

---

**Next Steps:**
1. Run audit: `php ensure_visitor_consistency.php --dry-run` 
2. If issues found, fix: `php ensure_visitor_consistency.php`
3. All future scripts will automatically maintain consistency! 🎉 