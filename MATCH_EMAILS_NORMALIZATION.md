# Match Emails Table Normalization

## Overview

The `match_emails` table contains advisor email-to-credential mappings, but not all rows have NPNs even when they share the same CRD. The normalization process propagates NPNs to all rows with the same CRD when it's safe to do so.

## Key Insight

If a CRD has at least one NPN associated with it, and **all rows with that CRD have the same NPN** (when not NULL), then we can safely propagate that NPN to all rows with that CRD.

Example:
```
Before normalization:
CRD    | NPN    | Email
24504  | 610844 | james.bockenek@wachoviasec.com
24504  | 610844 | james.bockenek@wellsfargoadvisors.com  
24504  | NULL   | james.bockenek@wfadvisors.com         ← Missing NPN

After normalization:
CRD    | NPN    | Email
24504  | 610844 | james.bockenek@wachoviasec.com
24504  | 610844 | james.bockenek@wellsfargoadvisors.com
24504  | 610844 | james.bockenek@wfadvisors.com         ← NPN filled in
```

## Running the Normalization

### 1. Analyze First (Dry Run)

Always analyze before updating to see what will be changed:

```bash
php normalize_match_emails.php analyze
```

This will show:
- How many CRDs have consistent NPNs that can be propagated
- How many rows will be updated
- Any CRDs with conflicting NPNs (these won't be touched)
- Sample updates that would be made

### 2. Apply Updates

Once you're satisfied with the analysis:

```bash
php normalize_match_emails.php update
```

This will:
- Update all rows in batches of 1000 for performance
- Show progress as it runs
- Report total rows updated

## Expected Results

Based on the analysis:
- **340,900 CRDs** have at least one known NPN
- **381,091 additional NPNs** can be filled in via CRD lookup
- This increases NPN coverage significantly

## Safety Features

The script will **NOT** update rows where:
- A CRD has multiple different NPNs (conflicts)
- The CRD is NULL
- The row already has an NPN

## Impact on Lookup Logic

After normalization:
1. **Direct email lookups** are more likely to return NPNs immediately
2. **CRD-based lookups** (Step 2 in our logic) become less necessary
3. Overall NPN match rate increases significantly

## Monitoring

After normalization, you can verify the improvements:

```sql
-- Check NPN coverage
SELECT 
    COUNT(*) as total_rows,
    COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) as rows_with_npn,
    ROUND(COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as npn_coverage_pct
FROM accupoint_solutions.match_emails
WHERE CRD IS NOT NULL;

-- Check if any CRDs still have mixed NULL/non-NULL NPNs
SELECT CRD, 
       COUNT(*) as total_rows,
       COUNT(CASE WHEN NPN IS NULL THEN 1 END) as null_npns,
       COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) as non_null_npns
FROM accupoint_solutions.match_emails
WHERE CRD IS NOT NULL
GROUP BY CRD
HAVING null_npns > 0 AND non_null_npns > 0
LIMIT 10;
```

## Best Practices

1. **Run periodically**: As new data is added to match_emails, run normalization monthly or quarterly
2. **Monitor conflicts**: Keep an eye on CRDs with multiple NPNs - these may need manual review
3. **Backup first**: Consider backing up the table before running large updates

## Future Enhancements

Consider creating triggers on the `match_emails` table to automatically propagate NPNs when new rows are inserted with a CRD that already has a known NPN. 