#!/usr/bin/env python3
"""
Script to apply import_hash fix to pixel_import.php
This adds support for the new import_hash field and removes the conflicted dedupe_uuid handling
"""

import re
from datetime import datetime

import os
TARGET_FILE = os.path.join(os.path.dirname(__file__), "pixel-bandaid", "pixel_import.php")

# Read the current file
with open(TARGET_FILE, 'r') as f:
    content = f.read()

# Create backup
backup_file = f"{TARGET_FILE}.backup_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
with open(backup_file, 'w') as f:
    f.write(content)
print(f"Backup created: {backup_file}")

# Fix 1: Add import_hash handling before "Persist full raw event JSON"
# Find the line with "Persist full raw event JSON" and add our code before it
import_hash_code = '''
                // CRITICAL FIX: Handle import_hash and skip conflicted dedupe_uuid
                if (isset($event['import_hash'])) {
                    $insert_data['import_hash'] = (string) $event['import_hash'];
                    debugLog("Event $eventIndex has import_hash: " . substr($insert_data['import_hash'], 0, 16) . "...");
                }
                
                // Explicitly UNSET dedupe_uuid to avoid "Generated column not allowed" errors
                unset($insert_data['dedupe_uuid']);

'''

content = content.replace(
    "                // Persist full raw event JSON for audit/replay (central table), dedup by hash",
    import_hash_code + "                // Persist full raw event JSON for audit/replay (central table), dedup by hash"
)

# Fix 2: Remove the old DEDUPE CHECK block (between "Build SQL" and "Step 1: Insert")
# Pattern to match the dedupe check block
dedupe_check_pattern = r'(\s+)// DEDUPE CHECK: Check if this event already exists in the database.*?(?=\s+// Step 1: Insert raw event into superpixel_resolution_log)'

content = re.sub(dedupe_check_pattern, '', content, flags=re.DOTALL)

# Fix 3: Update the Step 1 comment
content = content.replace(
    "                // Step 1: Insert raw event into superpixel_resolution_log\n                $sql = \"INSERT IGNORE",
    "                // Step 1: Insert raw event into superpixel_resolution_log\n                // We use INSERT IGNORE and rely on the unique index on import_hash for robust deduplication.\n                $sql = \"INSERT IGNORE"
)

# Write the updated content
with open(TARGET_FILE, 'w') as f:
    f.write(content)

print(f"✅ Successfully applied import_hash fix to {TARGET_FILE}")
print("Changes made:")
print("  1. Added import_hash field handling")
print("  2. Added explicit unset of dedupe_uuid to avoid conflicts")
print("  3. Removed slow per-event SELECT deduplication check")
print("  4. Now relies on database-level unique constraint for performance")
