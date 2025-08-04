# Email-Based NPN/CRD Lookup System

## Overview

This system automatically extracts individual emails from comma-separated lists and uses them to lookup NPN (National Producer Number) and CRD (Central Registration Depository) identifiers for financial advisors using the `accupoint_solutions.match_emails` table.

## System Architecture

### 1. Database Tables

#### Per-Client Tables:
- **superpixel_resolution_log**: Events table with email fields (business_email, personal_emails, deep_verified_emails)
- **superpixel_visitors**: Visitors table with same email fields
- **superpixel_emails**: NEW table storing individual parsed emails

#### Reference Table:
- **accupoint_solutions.match_emails**: Contains 3.9M+ email-to-advisor mappings
  - Columns: Email, CRD, NPN, AgentID
  - Multiple emails can map to same CRD (one advisor, multiple emails)
  - NPN may not be present on all rows for the same advisor

### 2. Email Parsing Flow

1. **Event Insertion** → `superpixel_resolution_log`
2. **Trigger Fires** → `after_resolution_log_insert`
3. **Procedure Called** → `parse_visitor_emails` splits comma-separated emails
4. **Emails Stored** → Individual emails saved to `superpixel_emails`
5. **Lookup Trigger** → `after_email_insert` performs multi-step NPN/CRD lookup
6. **Updates Applied** → Both visitors and resolution_log tables updated

### 3. Multi-Step NPN/CRD Lookup Strategy

The lookup trigger uses a sophisticated 3-step approach to maximize NPN matches:

```sql
-- STEP 1: Direct email match
SELECT CRD, NPN, AgentID FROM match_emails WHERE Email = [email]

-- STEP 2: If CRD found but no NPN, search by CRD
IF vCRD IS NOT NULL AND vNPN IS NULL THEN
    SELECT NPN FROM match_emails WHERE CRD = vCRD AND NPN IS NOT NULL

-- STEP 3: If still no NPN but AgentID exists, search by AgentID  
IF vNPN IS NULL AND vAgentID IS NOT NULL THEN
    SELECT NPN FROM match_emails WHERE AgentID = vAgentID AND NPN IS NOT NULL
```

This approach handles cases where:
- An advisor has multiple emails but NPN is only on some records
- The matched email doesn't have NPN but another email for the same CRD does
- AgentID can be used as an additional linking field

## Implementation

### For New Clients

When creating a new client via `/generate` endpoint:
1. All tables are created automatically
2. Email parsing procedure is installed
3. All triggers are set up
4. System is ready to process emails immediately

### For Existing Clients

Run the backfill script to:
1. Parse all existing emails from events
2. Populate the superpixel_emails table
3. Trigger NPN/CRD lookups for all emails

```bash
php backfill_emails_from_events.php [client_name]
```

## Benefits

1. **Higher Match Rate**: 3.9M+ emails vs previous 133K in old system
2. **Intelligent Matching**: Multi-step lookup maximizes NPN discovery
3. **Automatic Processing**: No manual intervention needed
4. **Historical Data**: Can backfill existing clients
5. **Performance**: Indexed lookups ensure fast processing

## Troubleshooting

### Check Email Parsing
```sql
SELECT COUNT(*) FROM superpixel_emails WHERE uuid = '[uuid]';
```

### Check NPN/CRD Matches
```sql
SELECT uuid, npn, crd FROM superpixel_visitors WHERE npn IS NOT NULL OR crd IS NOT NULL;
```

### Verify Reference Table
```sql
SELECT COUNT(*) FROM accupoint_solutions.match_emails;
-- Should return 3.9M+ rows
```

### Debug Specific Email
```sql
-- Check all records for an advisor by CRD
SELECT * FROM accupoint_solutions.match_emails WHERE CRD = '24504';

-- Check what emails we parsed for a visitor
SELECT * FROM superpixel_emails WHERE uuid = '[uuid]';
``` 