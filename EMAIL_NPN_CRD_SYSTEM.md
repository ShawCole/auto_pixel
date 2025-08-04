# Email-Based NPN/CRD Lookup System (Hybrid Approach)

## Overview

This system uses a hybrid approach to automatically process visitor emails and lookup NPN/CRD identifiers:
- **Database triggers** parse comma-separated emails and store them in `superpixel_emails` table
- **PHP scripts** perform the complex multi-step NPN/CRD lookup from `accupoint_solutions.match_emails`

## System Architecture

### 1. Database Components

#### Per-Client Tables:
- **superpixel_resolution_log**: Events table with email fields (business_email, personal_emails, deep_verified_emails)
- **superpixel_visitors**: Visitors table with same email fields
- **superpixel_emails**: Stores individual parsed emails

#### Database Triggers (Email Parsing Only):
- **after_resolution_log_insert**: Parses emails when new events arrive
- **after_visitors_insert**: Parses emails when new visitors are created
- **after_visitors_update**: Re-parses emails when visitor email fields change

#### Stored Procedure:
- **parse_visitor_emails**: Splits comma-separated emails and validates them

### 2. PHP Components

#### process_visitor_emails.php
- Performs multi-step NPN/CRD lookup
- Can parse emails as fallback if triggers fail
- Updates both visitors and resolution_log tables with NPN/CRD

#### Reference Table:
- **accupoint_solutions.match_emails**: Contains 3.9M+ email-to-advisor mappings
  - Columns: Email, CRD, NPN, AgentID
  - Multiple emails can map to same CRD (one advisor, multiple emails)
  - NPN may not be present on all rows for the same advisor

### 3. Data Flow

```
1. Event arrives → INSERT into superpixel_resolution_log
   ↓
2. Database trigger fires → Calls parse_visitor_emails procedure
   ↓
3. Individual emails → INSERT into superpixel_emails
   ↓
4. Webhook PHP script → Calls upsertVisitorFromEvent()
   ↓
5. upsertVisitorFromEvent → Calls processVisitorEmails()
   ↓
6. processVisitorEmails → Multi-step NPN/CRD lookup:
   - Step 1: Direct email match
   - Step 2: If CRD found but no NPN, search by CRD
   - Step 3: If still no NPN, search by AgentID
   ↓
7. Updates → Both superpixel_visitors and superpixel_resolution_log
```

### 4. Multi-Step NPN/CRD Lookup Strategy

The PHP script uses a sophisticated approach to maximize NPN matches:

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

## Implementation

### For New Clients

When creating a new client via `/generate` endpoint:
1. `superpixel_emails` table is created
2. `parse_visitor_emails` procedure is installed
3. Email parsing triggers are set up
4. PHP scripts handle NPN/CRD lookup

### For Existing Clients

Run the backfill script to process existing data:

```bash
php backfill_emails_from_events.php [client_name]
```

### Manual Testing

Test individual UUID processing:

```bash
# Process emails and lookup NPN/CRD
php process_visitor_emails.php [database] [uuid] debug

# Only do NPN/CRD lookup (skip email parsing)
php process_visitor_emails.php [database] [uuid] lookup-only
```

## Benefits of Hybrid Approach

1. **Reliability**: Database triggers ensure emails are parsed immediately on insert
2. **Flexibility**: PHP can handle complex multi-step lookups and fallback parsing
3. **Performance**: Email parsing happens at database level, lookup happens in PHP
4. **Debugging**: Easier to debug PHP logic than complex database triggers
5. **Maintainability**: Business logic in PHP is easier to update than database procedures

## Troubleshooting

### Check if Triggers are Working
```sql
-- Check if emails are being parsed
SELECT COUNT(*) FROM superpixel_emails WHERE uuid = '[uuid]';

-- Check trigger status
SHOW TRIGGERS LIKE 'after_%';
```

### Check NPN/CRD Coverage
```sql
SELECT 
    COUNT(DISTINCT uuid) as total_visitors,
    COUNT(DISTINCT CASE WHEN npn IS NOT NULL THEN uuid END) as with_npn,
    COUNT(DISTINCT CASE WHEN crd IS NOT NULL THEN uuid END) as with_crd
FROM superpixel_visitors;
```

### Debug Specific Email
```sql
-- Check what emails were parsed
SELECT * FROM superpixel_emails WHERE uuid = '[uuid]';

-- Check match_emails for specific email
SELECT * FROM accupoint_solutions.match_emails WHERE Email = '[email]';

-- Check all emails for a CRD
SELECT * FROM accupoint_solutions.match_emails WHERE CRD = '[crd]';
```

### If Triggers Fail

The PHP script will automatically parse emails if none are found in `superpixel_emails`. Check logs:

```bash
tail -f /var/www/hook.thynkdata.com/pixel_import_debug.log
```

Look for: "No emails found in superpixel_emails, parsing from source tables..." 