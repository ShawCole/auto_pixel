# Email-Based NPN/CRD Lookup System

## Overview

The Email-Based NPN/CRD Lookup System automatically extracts individual emails from visitor and event data, stores them in a dedicated table, and uses them to look up financial advisor credentials (NPN/CRD) from the CPACFANoIntent_CENTRAL reference table.

## System Architecture

### Tables Involved

1. **`superpixel_resolution_log`** - Stores all visitor events with email data
2. **`superpixel_visitors`** - Stores deduplicated visitor information  
3. **`superpixel_emails`** - NEW table storing individual parsed emails
4. **`pixel.CPACFANoIntent_CENTRAL`** - Reference table containing email-to-NPN/CRD mappings

### Data Flow

```
Event Arrives → superpixel_resolution_log
                     ↓ (trigger)
              Parse emails → superpixel_emails
                                    ↓ (trigger)
                            Lookup NPN/CRD from CPACFANoIntent_CENTRAL
                                    ↓
                            Update both superpixel_resolution_log 
                            and superpixel_visitors with NPN/CRD
```

## Implementation Details

### 1. superpixel_emails Table Structure

```sql
CREATE TABLE superpixel_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    email_type ENUM('personal', 'business', 'deep_verified') NOT NULL,
    source_column VARCHAR(50),
    source_table ENUM('resolution_log', 'visitors') DEFAULT 'resolution_log',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_uuid_email (uuid, email),
    INDEX idx_email (email),
    INDEX idx_uuid (uuid),
    INDEX idx_email_type (email_type)
)
```

### 2. Email Parsing Procedure

The `parse_visitor_emails` stored procedure:
- Takes comma-separated email strings
- Splits them into individual emails
- Validates email format
- Inserts unique email-UUID pairs into superpixel_emails

### 3. Trigger Flow

#### A. After INSERT on superpixel_resolution_log
- Extracts emails from business_email, personal_emails, deep_verified_emails
- Calls parse_visitor_emails for each non-empty email field
- Stores parsed emails in superpixel_emails table

#### B. After INSERT on superpixel_visitors
- Same process as above for visitor records

#### C. After UPDATE on superpixel_visitors
- Checks if email fields changed
- If yes, deletes old emails and re-parses new ones

#### D. After INSERT on superpixel_emails
- Looks up email in CPACFANoIntent_CENTRAL table
- Checks multiple columns: business_email, personal_email_1 through personal_email_5
- If match found, updates BOTH:
  - superpixel_visitors (by UUID)
  - superpixel_resolution_log (all records with same UUID)

## Usage

### For New Clients

When creating a new pixel through the API, the system automatically:
1. Creates the superpixel_emails table
2. Creates the parse_visitor_emails procedure
3. Creates all necessary triggers
4. Ready to process emails immediately

### For Existing Clients

To backfill emails from existing data:

```bash
php backfill_emails_from_events.php <database_name>
```

This script will:
1. Parse all emails from existing events
2. Populate the superpixel_emails table
3. Trigger NPN/CRD lookups automatically

## Benefits

1. **Accurate Matching**: Individual emails are matched precisely against the reference table
2. **Automatic Updates**: NPN/CRD values populate automatically when matches are found
3. **Comprehensive Coverage**: Both event records and visitor records get updated
4. **Historical Data**: Works with both new and existing data
5. **Performance**: Indexed email lookups are fast and efficient

## Troubleshooting

### Check if email table exists:
```sql
SHOW TABLES LIKE 'superpixel_emails';
```

### Check email parsing status:
```sql
SELECT COUNT(*) FROM superpixel_emails;
SELECT email_type, COUNT(*) FROM superpixel_emails GROUP BY email_type;
```

### Check NPN/CRD match rate:
```sql
SELECT 
    COUNT(DISTINCT uuid) as total_visitors,
    COUNT(DISTINCT CASE WHEN npn IS NOT NULL THEN uuid END) as with_npn,
    COUNT(DISTINCT CASE WHEN crd IS NOT NULL THEN uuid END) as with_crd
FROM superpixel_visitors;
```

### Manually trigger email parsing for a specific UUID:
```sql
CALL parse_visitor_emails('uuid-here', 'email1@test.com,email2@test.com', 'personal', 'manual');
```

## Important Notes

1. The system uses `COALESCE` when updating NPN/CRD to preserve existing values
2. Email validation uses a regex pattern to ensure only valid emails are stored
3. The UNIQUE constraint on (uuid, email) prevents duplicate entries
4. Triggers run automatically - no manual intervention needed for new data 