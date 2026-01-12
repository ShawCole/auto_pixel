<?php
// Standardized Visitor Upsert Functions
// Include this file in all scripts that modify superpixel_resolution_log

/**
 * Normalizes ISO8601 timestamps to MySQL Datetime format
 */
function normalizeTimestamp($ts)
{
    if (empty($ts))
        return 'CURRENT_TIMESTAMP';
    // Remove trailing Z and replace T with space
    $clean = str_replace(['Z', 'T'], ['', ' '], $ts);
    // Ensure it looks like YYYY-MM-DD HH:MM:SS
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $clean)) {
        return "'" . $clean . "'";
    }
    return "'$ts'"; // Fallback to raw if not matching standard cleaning
}

/**
 * Upserts visitor profile based on event data
 * 
 * @param mysqli $mysqli Database connection
 * @param array $event_data Event data from superpixel_resolution_log
 * @param string $debug_context Context for debugging (e.g., "event_123", "migration")
 * @return bool Success status
 */
function upsertVisitorFromEvent($mysqli, $event_data, $debug_context = "unknown")
{
    if (empty($event_data['uuid'])) {
        debugLog("Warning: No UUID found for $debug_context - skipping visitor profile update");
        return true; // Not an error, just no visitor to create
    }

    try {
        // Get actual columns that exist in the superpixel_visitors table
        $columnsQuery = "SHOW COLUMNS FROM superpixel_visitors";
        $columnsResult = $mysqli->query($columnsQuery);

        if (!$columnsResult) {
            debugLog("Failed to get table schema for $debug_context: " . $mysqli->error);
            return false;
        }

        $existing_columns = [];
        while ($row = $columnsResult->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }

        // Define all possible visitor fields (Sync with superpixel_visitors schema)
        $possible_visitor_fields = [
            'uuid',
            'first_name',
            'last_name',
            'personal_address',
            'personal_city',
            'personal_state',
            'personal_zip',
            'personal_zip4',
            'age_range',
            'children',
            'gender',
            'homeowner',
            'married',
            'net_worth',
            'income_range',
            'direct_number',
            'direct_number_dnc',
            'mobile_phone',
            'mobile_phone_dnc',
            'personal_phone',
            'personal_phone_dnc',
            'business_email',
            'personal_emails',
            'deep_verified_emails',
            'sha256_personal_email',
            'sha256_business_email',
            'hem_sha256',
            'job_title',
            'headline',
            'department',
            'seniority_level',
            'inferred_years_experience',
            'company_name_history',
            'job_title_history',
            'education_history',
            'company_address',
            'company_description',
            'company_domain',
            'company_employee_count',
            'company_linkedin_url',
            'company_name',
            'company_phone',
            'company_revenue',
            'company_sic',
            'company_naics',
            'company_city',
            'company_state',
            'company_zip',
            'company_industry',
            'linkedin_url',
            'twitter_url',
            'facebook_url',
            'social_connections',
            'skills',
            'interests',
            'skiptrace_match_score',
            'skiptrace_name',
            'skiptrace_address',
            'skiptrace_city',
            'skiptrace_state',
            'skiptrace_zip',
            'skiptrace_landline_numbers',
            'skiptrace_wireless_numbers',
            'skiptrace_credit_rating',
            'skiptrace_dnc',
            'skiptrace_exact_age',
            'skiptrace_ethnic_code',
            'skiptrace_language_code',
            'skiptrace_ip',
            'skiptrace_b2b_address',
            'skiptrace_b2b_phone',
            'skiptrace_b2b_source',
            'skiptrace_b2b_website',
            'valid_phones',
            'url',
            'element',
            'percentage',
            'referrer',
            'event_timestamp',
            'event_type',
            'npn',
            'crd',
            'ip_address',
            'pixel_id',
            'activity_start_date',
            'activity_end_date',
            'referrer_url',
            'timestamp',
            'title'
        ];

        // Only use fields that actually exist in the table
        $visitor_fields = array_intersect($possible_visitor_fields, $existing_columns);

        // Extract visitor data from event data (only for existing columns)
        $visitor_data = [];
        foreach ($visitor_fields as $field) {
            if (isset($event_data[$field])) {
                $visitor_data[$field] = $event_data[$field];
            }
        }

        // Add derived fields from event context (only if columns exist)
        if (isset($event_data['url']) && in_array('last_visited_url', $existing_columns)) {
            $visitor_data['last_visited_url'] = $event_data['url'];
        }
        if (isset($event_data['element']) && in_array('last_element', $existing_columns)) {
            $visitor_data['last_element'] = $event_data['element'];
        }
        if (isset($event_data['percentage']) && in_array('last_percentage', $existing_columns)) {
            $visitor_data['last_percentage'] = $event_data['percentage'];
        }
        if (isset($event_data['referrer']) && in_array('last_referrer', $existing_columns)) {
            $visitor_data['last_referrer'] = $event_data['referrer'];
        }
        if (isset($event_data['timestamp']) && in_array('last_timestamp', $existing_columns)) {
            $visitor_data['last_timestamp'] = $event_data['timestamp'];
        }
        if (isset($event_data['event_type']) && in_array('last_event', $existing_columns)) {
            $visitor_data['last_event'] = $event_data['event_type'];
        }

        if (empty($visitor_data)) {
            debugLog("Warning: No visitor data to process for $debug_context");
            return true;
        }

        // Build INSERT ... ON DUPLICATE KEY UPDATE query
        $visitor_columns = [];
        $visitor_values = [];
        $update_parts = [];

        foreach ($visitor_data as $key => $value) {
            $escaped_key = "`" . $mysqli->real_escape_string($key) . "`";

            // Handle data type compatibility for integer/numeric columns
            if (in_array($key, ['last_percentage', 'event_count', 'age_range']) && ($value === '' || $value === null)) {
                $escaped_value = "NULL";
            } else {
                $escaped_value = "'" . $mysqli->real_escape_string($value) . "'";
            }

            $visitor_columns[] = $escaped_key;
            $visitor_values[] = $escaped_value;

            // Use COALESCE to not overwrite existing data with empty values
            if ($key !== 'uuid') { // Don't update UUID in UPDATE clause
                if ($escaped_value === "NULL") {
                    // For NULL values, don't overwrite existing data, but allow NULL if no existing data
                    $update_parts[] = "$escaped_key = COALESCE($escaped_key, $escaped_value)";
                } else {
                    $update_parts[] = "$escaped_key = COALESCE(NULLIF($escaped_value, ''), $escaped_key, $escaped_value)";
                }
            }
        }

        // Build the ON DUPLICATE KEY UPDATE clause, checking for schema-specific fields
        $update_clauses = $update_parts;

        // Add event_count increment if the column exists
        if (in_array('event_count', $existing_columns)) {
            $update_clauses[] = "event_count = event_count + 1";
        }

        // Determine the event timestamp to use for first/last seen
        $active_timestamp = 'CURRENT_TIMESTAMP';
        if (!empty($event_data['event_timestamp'])) {
            $active_timestamp = normalizeTimestamp($event_data['event_timestamp']);
        } elseif (!empty($event_data['timestamp'])) {
            $active_timestamp = normalizeTimestamp($event_data['timestamp']);
        }

        // Add last_seen_at update if the column exists
        if (in_array('last_seen_at', $existing_columns)) {
            // Only update if new timestamp is newer than existing
            $update_clauses[] = "last_seen_at = GREATEST(last_seen_at, $active_timestamp)";
        } elseif (in_array('updated_at', $existing_columns)) {
            $update_clauses[] = "updated_at = GREATEST(updated_at, $active_timestamp)";
        }

        // Add first_seen_at if it's an insert (only if column exists and not already set)
        if (in_array('first_seen_at', $existing_columns) && !isset($visitor_data['first_seen_at'])) {
            $visitor_columns[] = "`first_seen_at`";
            $visitor_values[] = $active_timestamp;
        } elseif (in_array('created_at', $existing_columns) && !isset($visitor_data['created_at'])) {
            $visitor_columns[] = "`created_at`";
            $visitor_values[] = $active_timestamp;
        }

        // Also ensure last_seen_at is set on creation
        if (in_array('last_seen_at', $existing_columns) && !isset($visitor_data['last_seen_at'])) {
            $visitor_columns[] = "`last_seen_at`";
            $visitor_values[] = $active_timestamp;
        }

        $visitor_sql = "INSERT INTO superpixel_visitors (" . implode(",", $visitor_columns) . ") 
                       VALUES (" . implode(",", $visitor_values) . ")
                       ON DUPLICATE KEY UPDATE " . implode(", ", $update_clauses);

        debugLog("Visitor fields for $debug_context: " . implode(",", $visitor_fields));
        debugLog("Executing visitor upsert for $debug_context");

        if (!$mysqli->query($visitor_sql)) {
            $error = "Visitor upsert failed for $debug_context: " . $mysqli->error;
            debugLog($error);
            return false;
        } else {
            debugLog("$debug_context - Successfully upserted visitor record");

            // Process NPN/CRD lookup using PHP function
            // In the hybrid approach, emails should already be parsed by database triggers
            // But we'll allow parsing as a fallback if triggers failed
            if (isset($visitor_data['uuid'])) {
                $uuid = $visitor_data['uuid'];

                // Include the email processing function
                if (file_exists(__DIR__ . '/process_visitor_emails.php')) {
                    require_once __DIR__ . '/process_visitor_emails.php';

                    // Get the database name from the connection
                    $db_name_query = $mysqli->query("SELECT DATABASE()");
                    if ($db_name_query && $row = $db_name_query->fetch_row()) {
                        $db_name = $row[0];

                        debugLog("$debug_context - Processing NPN/CRD lookup for UUID: $uuid in database: $db_name");

                        // Process emails and lookup NPN/CRD
                        // Set parse_emails=true to handle cases where triggers might have failed
                        $email_results = processVisitorEmails($db_name, $uuid, true, false);

                        debugLog("$debug_context - Email processing results: " . json_encode($email_results));
                    }
                } else {
                    debugLog("$debug_context - process_visitor_emails.php not found, skipping NPN/CRD lookup");
                }
            }

            return true;
        }

    } catch (Exception $e) {
        debugLog("Exception during visitor upsert for $debug_context: " . $e->getMessage());
        return false;
    }
}

/**
 * Batch upsert visitors from multiple events
 * 
 * @param mysqli $mysqli Database connection
 * @param array $events_data Array of event data
 * @param string $debug_context Context for debugging
 * @return array ['success_count' => int, 'error_count' => int]
 */
function batchUpsertVisitorsFromEvents($mysqli, $events_data, $debug_context = "batch")
{
    $success_count = 0;
    $error_count = 0;

    foreach ($events_data as $index => $event_data) {
        $event_context = "$debug_context-event_$index";

        if (upsertVisitorFromEvent($mysqli, $event_data, $event_context)) {
            $success_count++;
        } else {
            $error_count++;
        }
    }

    debugLog("Batch visitor upsert complete for $debug_context: $success_count success, $error_count errors");

    return [
        'success_count' => $success_count,
        'error_count' => $error_count
    ];
}

/**
 * Backfill missing visitors for events that have UUIDs but no visitor records
 * 
 * @param mysqli $mysqli Database connection
 * @param int $limit Maximum number of visitors to backfill (default: 1000)
 * @param string $debug_context Context for debugging
 * @return array ['backfilled_count' => int, 'error_count' => int]
 */
function backfillMissingVisitors($mysqli, $limit = 1000, $debug_context = "backfill")
{
    debugLog("Starting Robust Visitor Sync (4-Phase) for $debug_context");
    $stats = ['phase1_deleted' => 0, 'phase2_inserted' => 0, 'phase3_updated' => 0];

    try {
        // --- PHASE 1: GARBAGE COLLECTION ---
        // Remove invisible UUIDs that break joins
        $sql_clean = "DELETE FROM `superpixel_visitors` WHERE uuid IS NULL OR TRIM(uuid) = '' OR LENGTH(TRIM(uuid)) < 20";
        if ($mysqli->query($sql_clean)) {
            $stats['phase1_deleted'] = $mysqli->affected_rows;
            if ($stats['phase1_deleted'] > 0)
                debugLog("Phase 1: Deleted {$stats['phase1_deleted']} ghost rows");
        } else {
            debugLog("Phase 1 Error: " . $mysqli->error);
        }

        // --- PHASE 2: UPSERT (BACKFILL ENRICHED) ---
        // Insert new users found in the log who aren't in visitors yet
        // Dynamically pull all shared columns between log and visitors

        // Get columns from visitors table to know what we can insert into
        $existing_columns = [];
        $res = $mysqli->query("SHOW COLUMNS FROM `superpixel_visitors`");
        while ($row = $res->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }

        $core_visitor_fields = [
            'first_name',
            'last_name',
            'personal_address',
            'personal_city',
            'personal_state',
            'personal_zip',
            'personal_zip4',
            'age_range',
            'children',
            'gender',
            'homeowner',
            'married',
            'net_worth',
            'income_range',
            'direct_number',
            'direct_number_dnc',
            'mobile_phone',
            'mobile_phone_dnc',
            'personal_phone',
            'personal_phone_dnc',
            'business_email',
            'personal_emails',
            'deep_verified_emails',
            'sha256_personal_email',
            'sha256_business_email',
            'hem_sha256',
            'job_title',
            'headline',
            'department',
            'seniority_level',
            'inferred_years_experience',
            'company_name_history',
            'job_title_history',
            'education_history',
            'company_address',
            'company_description',
            'company_domain',
            'company_employee_count',
            'company_linkedin_url',
            'company_name',
            'company_phone',
            'company_revenue',
            'company_sic',
            'company_naics',
            'company_city',
            'company_state',
            'company_zip',
            'company_industry',
            'linkedin_url',
            'twitter_url',
            'facebook_url',
            'social_connections',
            'skills',
            'interests',
            'skiptrace_match_score',
            'skiptrace_name',
            'skiptrace_address',
            'skiptrace_city',
            'skiptrace_state',
            'skiptrace_zip',
            'skiptrace_landline_numbers',
            'skiptrace_wireless_numbers',
            'skiptrace_credit_rating',
            'skiptrace_dnc',
            'skiptrace_exact_age',
            'skiptrace_ethnic_code',
            'skiptrace_language_code',
            'skiptrace_ip',
            'skiptrace_b2b_address',
            'skiptrace_b2b_phone',
            'skiptrace_b2b_source',
            'skiptrace_b2b_website',
            'valid_phones',
            'url',
            'element',
            'percentage',
            'referrer',
            'event_timestamp',
            'event_type',
            'npn',
            'crd',
            'ip_address',
            'pixel_id',
            'activity_start_date',
            'activity_end_date',
            'referrer_url',
            'timestamp',
            'title'
        ];

        $insert_cols = ['uuid', 'first_seen_at', 'last_seen_at'];
        $select_vals = [
            'uuid',
            "DATE_FORMAT(MIN(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s')",
            "DATE_FORMAT(MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s')"
        ];
        $update_clauses = [
            "first_seen_at = LEAST(first_seen_at, VALUES(first_seen_at))",
            "last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at))"
        ];

        foreach ($core_visitor_fields as $field) {
            if (in_array($field, $existing_columns)) {
                $insert_cols[] = "`$field`";
                $select_vals[] = "MAX(NULLIF(`$field`, ''))";
                // Only update if current is empty or new is better
                $update_clauses[] = "`$field` = COALESCE(NULLIF(VALUES(`$field`), ''), `$field`)";
            }
        }

        $sql_upsert = "
            INSERT INTO `superpixel_visitors` (" . implode(', ', $insert_cols) . ")
            SELECT " . implode(', ', $select_vals) . "
            FROM `superpixel_resolution_log`
            WHERE uuid IS NOT NULL AND LENGTH(uuid) > 20
            GROUP BY uuid
            HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
            ON DUPLICATE KEY UPDATE " . implode(', ', $update_clauses);

        if ($mysqli->query($sql_upsert)) {
            $stats['phase2_inserted'] = $mysqli->affected_rows;
            debugLog("Phase 2: Upserted affected rows: " . $stats['phase2_inserted']);
        } else {
            debugLog("Phase 2 Error: " . $mysqli->error);
        }

        // --- PHASE 3: FORCE SYNC (SAFETY NET) ---
        // Catch-all for any existing rows that drifted or were just inserted with CURRENT_TIMESTAMP by generic import
        $sql_force = "
            UPDATE `superpixel_visitors` v
            JOIN (
              SELECT
                uuid,
                DATE_FORMAT(MIN(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s') as true_first_seen_str,
                DATE_FORMAT(MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)), '%Y-%m-%d %H:%i:%s') as true_last_seen_str
              FROM `superpixel_resolution_log`
              WHERE uuid IS NOT NULL AND LENGTH(uuid) > 20
              GROUP BY uuid
              HAVING MAX(CAST(REPLACE(REPLACE(event_timestamp, 'Z', ''), 'T', ' ') AS DATETIME)) >= NOW() - INTERVAL 45 DAY
            ) stats ON v.uuid = stats.uuid
            SET
              v.first_seen_at = stats.true_first_seen_str,
              v.last_seen_at  = stats.true_last_seen_str
            WHERE DATE_FORMAT(v.last_seen_at,  '%Y-%m-%d %H:%i:%s') != stats.true_last_seen_str
               OR DATE_FORMAT(v.first_seen_at, '%Y-%m-%d %H:%i:%s') != stats.true_first_seen_str
        ";

        if ($mysqli->query($sql_force)) {
            $stats['phase3_updated'] = $mysqli->affected_rows;
            if ($stats['phase3_updated'] > 0)
                debugLog("Phase 3: Corrected {$stats['phase3_updated']} rows with timestamp drift");
        } else {
            debugLog("Phase 3 Error: " . $mysqli->error);
        }

    } catch (Exception $e) {
        debugLog("Backfill Exception: " . $e->getMessage());
        return ['backfilled_count' => 0, 'error_count' => 1];
    }

    // Return format expected by caller (aggregating inserts/updates)
    return [
        'backfilled_count' => $stats['phase2_inserted'] + $stats['phase3_updated'],
        'error_count' => 0,
        'details' => $stats
    ];
}

/**
 * Ensures required unique constraints exist for idempotency
 * @param mysqli $mysqli
 */
function ensureSchema($mysqli)
{
    // 1. superpixel_visitors: Unique UUID
    $check = $mysqli->query("SHOW INDEX FROM superpixel_visitors WHERE Key_name = 'idx_uuid'");
    if ($check && $check->num_rows == 0) {
        debugLog("Adding unique index idx_uuid to superpixel_visitors...");
        $mysqli->query("CREATE UNIQUE INDEX idx_uuid ON superpixel_visitors (uuid)");
        // If duplicates exist, this might fail. We suppress error or let it crash to alert user?
        // Better to try IGNORE? MySQL doesn't support CREATE UNIQUE INDEX IGNORE easily.
        // If it fails, we catch exception.
    }

    // 2. superpixel_resolution_log: Robust Dedupe via import_hash
    // First, ensure the column exists
    $checkCol = $mysqli->query("SHOW COLUMNS FROM superpixel_resolution_log LIKE 'import_hash'");
    if ($checkCol && $checkCol->num_rows == 0) {
        debugLog("Adding import_hash column to superpixel_resolution_log...");
        try {
            $mysqli->query("ALTER TABLE superpixel_resolution_log ADD COLUMN import_hash VARCHAR(64) AFTER uuid");
        } catch (Throwable $e) {
            debugLog("Warning: Could not add import_hash column: " . $e->getMessage());
        }
    }

    // Now ensure unique index on import_hash
    $check2 = $mysqli->query("SHOW INDEX FROM superpixel_resolution_log WHERE Key_name = 'idx_import_hash'");
    if ($check2 && $check2->num_rows == 0) {
        debugLog("Adding unique index idx_import_hash to superpixel_resolution_log...");
        try {
            $mysqli->query("CREATE UNIQUE INDEX idx_import_hash ON superpixel_resolution_log (import_hash)");
        } catch (Throwable $e) {
            debugLog("Warning: Could not add unique import_hash index: " . $e->getMessage());
        }
    }
}



/**
 * Debugging function - define if not exists
 * 
 * @param string $message Message to log
 */
if (!function_exists('debugLog')) {
    function debugLog($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] $message");
        // echo "[$timestamp] $message\n"; // Removed echo to prevent contamination of JSON output
    }
}
?>