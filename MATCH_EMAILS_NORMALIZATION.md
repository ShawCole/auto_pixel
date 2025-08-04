# Match Emails Table Normalization

## Overview

The `match_emails` table contains advisor email-to-credential mappings. Complete normalization involves **bidirectional propagation** of CRD, NPN, and AgentID values to maximize data completeness.

## Complete Normalization Logic

### Bidirectional Propagation Rules:

1. **CRD → NPN**: If rows share the same CRD and one has NPN, propagate to all
2. **CRD → AgentID**: If rows share the same CRD and one has AgentID, propagate to all
3. **NPN → CRD**: If rows share the same NPN and one has CRD, propagate to all
4. **NPN → AgentID**: If rows share the same NPN and one has AgentID, propagate to all
5. **AgentID → CRD**: If rows share the same AgentID and one has CRD, propagate to all
6. **AgentID → NPN**: If rows share the same AgentID and one has NPN, propagate to all

### Safety Rules:
- Only propagate when there's exactly ONE unique value (no conflicts)
- Skip rows that already have values
- Never overwrite existing data

## Running the Normalization

### Initial One-Direction Normalization (Already Done)

This normalized CRD → NPN only:
```bash
php normalize_match_emails.php analyze  # Already run
php normalize_match_emails.php update   # Already run - added 379,755 NPNs
```

### Complete Bidirectional Normalization (NEW)

This normalizes in ALL directions:
```bash
# First analyze to see what will be updated
php normalize_match_emails_complete.php analyze

# Then apply all updates
php normalize_match_emails_complete.php update
```

## Expected Impact

Based on the data structure, complete normalization should:

1. **Fill missing CRDs** where we have NPNs
2. **Fill missing NPNs** where we have CRDs (already done)
3. **Fill missing AgentIDs** using CRD/NPN relationships
4. **Maximize coverage** for all three identifiers

## Example Scenarios

### Scenario 1: NPN → CRD Propagation
```
Before:
Email                          | CRD  | NPN    | AgentID
john.doe@advisor1.com         | 123  | 45678  | NULL
john.doe@advisor2.com         | NULL | 45678  | NULL  ← Missing CRD

After normalization:
john.doe@advisor1.com         | 123  | 45678  | NULL
john.doe@advisor2.com         | 123  | 45678  | NULL  ← CRD filled
```

### Scenario 2: AgentID Bridge
```
Before:
advisor@firm1.com             | 123  | NULL   | 789
advisor@firm2.com             | NULL | NULL   | 789   ← Missing both
different@email.com           | NULL | 45678  | 789   ← Has NPN

After normalization:
advisor@firm1.com             | 123  | 45678  | 789   ← NPN filled via AgentID
advisor@firm2.com             | 123  | 45678  | 789   ← Both filled via AgentID
different@email.com           | 123  | 45678  | 789   ← CRD filled via AgentID
```

## Monitoring Progress

Check coverage improvements:
```sql
-- Overall coverage stats
SELECT 
    COUNT(*) as total,
    ROUND(COUNT(CASE WHEN CRD IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as crd_pct,
    ROUND(COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as npn_pct,
    ROUND(COUNT(CASE WHEN AgentID IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as agentid_pct,
    ROUND(COUNT(CASE WHEN CRD IS NOT NULL AND NPN IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as both_pct
FROM accupoint_solutions.match_emails;
```

## Best Practices

1. **Run Complete Normalization First**: Before any other data processing
2. **Re-run Periodically**: As new data is added
3. **Monitor Conflicts**: Review cases with multiple values for manual resolution
4. **Leverage in Lookups**: The lookup logic now checks all relationships

## Integration with Lookup System

After complete normalization, the `process_visitor_emails.php` lookup is more powerful:

1. **Email match** → Get CRD/NPN/AgentID
2. **If missing NPN** → Check via CRD or AgentID  
3. **If missing CRD** → Check via NPN or AgentID
4. **Maximum match rate** due to normalized data 