#!/bin/bash
# migrate_to_import_hash.sh
# SQL migration script to add import_hash column and backfill existing data

# Database credentials
DB_HOST="34.26.61.148"
DB_USER="root"
DB_PASS="AccuPoint01!"

# Get list of all client databases
echo "=== Import Hash Migration Script ==="
echo "Fetching list of client databases..."

DATABASES=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -N -e "SELECT client_name FROM pixel.pixel_sheets;")

for DB_NAME in $DATABASES; do
    echo ""
    echo "========================================="
    echo "Processing database: $DB_NAME"
    echo "========================================="
    
    # Check if superpixel_resolution_log table exists
    TABLE_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME' AND table_name = 'superpixel_resolution_log';")
    
    if [ "$TABLE_EXISTS" -eq "0" ]; then
        echo "⚠️  Table superpixel_resolution_log does not exist in $DB_NAME, skipping..."
        continue
    fi
    
    # Step 1: Check if dedupe_uuid exists and is NOT a generated column
    echo "Step 1: Checking for non-generated dedupe_uuid column..."
    DEDUPE_UUID_INFO=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -N -e "SELECT EXTRA FROM information_schema.columns WHERE table_schema = '$DB_NAME' AND table_name = 'superpixel_resolution_log' AND column_name = 'dedupe_uuid';")
    
    if [ ! -z "$DEDUPE_UUID_INFO" ]; then
        if [[ "$DEDUPE_UUID_INFO" != *"GENERATED"* ]]; then
            echo "   Found non-generated dedupe_uuid column, dropping it..."
            mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "ALTER TABLE superpixel_resolution_log DROP COLUMN dedupe_uuid;"
            echo "   ✅ Dropped dedupe_uuid column"
        else
            echo "   ℹ️  dedupe_uuid is a GENERATED column, leaving it as-is"
        fi
    else
        echo "   ✅ No dedupe_uuid column found"
    fi
    
    # Step 2: Add import_hash column if it doesn't exist
    echo "Step 2: Adding import_hash column..."
    IMPORT_HASH_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -N -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$DB_NAME' AND table_name = 'superpixel_resolution_log' AND column_name = 'import_hash';")
    
    if [ "$IMPORT_HASH_EXISTS" -eq "0" ]; then
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "ALTER TABLE superpixel_resolution_log ADD COLUMN import_hash VARCHAR(64) AFTER uuid;"
        echo "   ✅ Added import_hash column"
    else
        echo "   ℹ️  import_hash column already exists"
    fi
    
    # Step 3: Backfill import_hash for past 30 days
    echo "Step 3: Backfilling import_hash for past 30 days..."
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'EOF'
UPDATE superpixel_resolution_log
SET import_hash = SHA2(CONCAT(
    COALESCE(uuid, ''), '|',
    COALESCE(event_type, ''), '|',
    COALESCE(event_timestamp, '')
), 256)
WHERE import_hash IS NULL
  AND event_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  AND uuid IS NOT NULL
  AND event_type IS NOT NULL
  AND event_timestamp IS NOT NULL;
EOF
    
    ROWS_UPDATED=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -N -e "SELECT ROW_COUNT();")
    echo "   ✅ Backfilled $ROWS_UPDATED rows"
    
    # Step 4: Add unique index on import_hash
    echo "Step 4: Adding unique index on import_hash..."
    INDEX_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -N -e "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = '$DB_NAME' AND table_name = 'superpixel_resolution_log' AND index_name = 'idx_import_hash';")
    
    if [ "$INDEX_EXISTS" -eq "0" ]; then
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "CREATE UNIQUE INDEX idx_import_hash ON superpixel_resolution_log (import_hash);" 2>&1 | grep -v "Duplicate entry" || true
        echo "   ✅ Added unique index on import_hash"
    else
        echo "   ℹ️  Unique index already exists"
    fi
    
    echo "✅ Completed migration for $DB_NAME"
done

echo ""
echo "========================================="
echo "✅ Migration complete for all databases!"
echo "========================================="
