#!/bin/bash
# Script to apply import_hash fix to pixel_import.php

TARGET_FILE="/Users/ShawCole/Auto_Pixel/pixel-bandaid/pixel_import.php"

echo "Applying import_hash fix to pixel_import.php..."

# Create a backup
cp "$TARGET_FILE" "${TARGET_FILE}.backup_$(date +%Y%m%d_%H%M%S)"

# Apply the fixes using sed
# 1. Add import_hash handling after line 275 (before "Persist full raw event JSON")
# 2. Remove the old DEDUPE CHECK block (lines 304-341)

# Use perl for multi-line replacements
perl -i -pe '
# Add import_hash handling and unset dedupe_uuid before the raw event persist block
s/([ \t]+)\/\/ Persist full raw event JSON for audit\/replay \(central table\), dedup by hash/$1\/\/ CRITICAL FIX: Handle import_hash and skip conflicted dedupe_uuid\n$1if (isset(\$event[\x27import_hash\x27])) {\n$1    \$insert_data[\x27import_hash\x27] = (string) \$event[\x27import_hash\x27];\n$1    debugLog("Event \$eventIndex has import_hash: " . substr(\$insert_data[\x27import_hash\x27], 0, 16) . "...");\n$1}\n$1\n$1\/\/ Explicitly UNSET dedupe_uuid to avoid \"Generated column not allowed\" errors\n$1unset(\$insert_data[\x27dedupe_uuid\x27]);\n\n$1\/\/ Persist full raw event JSON for audit\/replay (central table)/;

# Remove the old DEDUPE CHECK block and update the INSERT comment
s/                \/\/ DEDUPE CHECK: Check if this event already exists in the database.*?(?=                \/\/ Step 1: Insert raw event into superpixel_resolution_log)//s;

# Update the Step 1 comment to mention the unique index
s/([ \t]+)\/\/ Step 1: Insert raw event into superpixel_resolution_log\n([ \t]+)\$sql = "INSERT IGNORE/$1\/\/ Step 1: Insert raw event into superpixel_resolution_log\n$1\/\/ We use INSERT IGNORE and rely on the unique index on import_hash for robust deduplication.\n$2\$sql = "INSERT IGNORE/;
' "$TARGET_FILE"

echo "Fix applied successfully!"
echo "Backup saved to: ${TARGET_FILE}.backup_$(date +%Y%m%d_%H%M%S)"
