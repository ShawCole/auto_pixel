<?php
// Standardized Visitor Upsert Functions
// Include this file in all scripts that modify superpixel_resolution_log

/**
 * Upserts visitor profile based on event data
 * 
 * @param mysqli $mysqli Database connection
 * @param array $event_data Event data from superpixel_resolution_log
 * @param string $debug_context Context for debugging (e.g., "event_123", "migration")
 * @return bool Success status
 */
function upsertVisitorFromEvent($mysqli, $event_data, $debug_context = "unknown") {
    if (empty($event_data['uuid'])) {
        debugLog("Warning: No UUID found for $debug_context - skipping visitor profile update");
        return true; // Not an error, just no visitor to create
    }

    try {
        // Define all possible visitor fields (schema-aware)
        $visitor_fields = [
            'uuid', 'first_name', 'last_name', 'company_name', 'job_title',
            'personal_emails', 'mobile_phone', 'personal_address', 'personal_city', 
            'personal_state', 'personal_zip', 'personal_zip4', 'age_range', 
            'children', 'gender', 'homeowner', 'married', 'net_worth', 
            'income_range', 'direct_number', 'direct_number_dnc', 
            'mobile_phone_dnc', 'hem_sha256', 'last_visited_url', 'last_element',
            'last_percentage', 'last_referrer', 'last_timestamp', 'last_event',
            'npn', 'crd'
        ];

        // Extract visitor data from event data
        $visitor_data = [];
        foreach ($visitor_fields as $field) {
            if (isset($event_data[$field])) {
                $visitor_data[$field] = $event_data[$field];
            }
        }

        // Add derived fields from event context
        if (isset($event_data['url'])) {
            $visitor_data['last_visited_url'] = $event_data['url'];
        }
        if (isset($event_data['element'])) {
            $visitor_data['last_element'] = $event_data['element'];
        }
        if (isset($event_data['percentage'])) {
            $visitor_data['last_percentage'] = $event_data['percentage'];
        }
        if (isset($event_data['referrer'])) {
            $visitor_data['last_referrer'] = $event_data['referrer'];
        }
        if (isset($event_data['timestamp'])) {
            $visitor_data['last_timestamp'] = $event_data['timestamp'];
        }
        if (isset($event_data['event_type'])) {
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
            $escaped_value = "'" . $mysqli->real_escape_string($value) . "'";

            $visitor_columns[] = $escaped_key;
            $visitor_values[] = $escaped_value;

            // Use COALESCE to not overwrite existing data with empty values
            if ($key !== 'uuid') { // Don't update UUID in UPDATE clause
                $update_parts[] = "$escaped_key = COALESCE(NULLIF($escaped_value, ''), $escaped_key, $escaped_value)";
            }
        }

        $visitor_sql = "INSERT INTO superpixel_visitors (" . implode(",", $visitor_columns) . ") 
                       VALUES (" . implode(",", $visitor_values) . ")
                       ON DUPLICATE KEY UPDATE " . implode(", ", $update_parts) . ",
                       event_count = event_count + 1,
                       last_seen_at = CURRENT_TIMESTAMP";

        debugLog("Executing visitor upsert for $debug_context");

        if (!$mysqli->query($visitor_sql)) {
            $error = "Visitor upsert failed for $debug_context: " . $mysqli->error;
            debugLog($error);
            return false;
        } else {
            debugLog("Successfully upserted visitor profile for $debug_context");
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
function batchUpsertVisitorsFromEvents($mysqli, $events_data, $debug_context = "batch") {
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
function backfillMissingVisitors($mysqli, $limit = 1000, $debug_context = "backfill") {
    debugLog("Starting backfill of missing visitors for $debug_context (limit: $limit)");

    // Get schema-aware column list for events table
    $columnsResult = $mysqli->query("SHOW COLUMNS FROM superpixel_resolution_log");
    $event_columns = [];
    while ($row = $columnsResult->fetch_assoc()) {
        $event_columns[] = $row['Field'];
    }

    // Build SELECT clause with only existing columns
    $select_columns = [];
    $required_columns = [
        'uuid', 'first_name', 'last_name', 'company_name', 'job_title',
        'personal_emails', 'mobile_phone', 'personal_address', 'personal_city',
        'personal_state', 'personal_zip', 'hem_sha256', 'url', 'element',
        'percentage', 'referrer', 'timestamp', 'event_type', 'npn', 'crd'
    ];

    foreach ($required_columns as $col) {
        if (in_array($col, $event_columns)) {
            $select_columns[] = $col;
        }
    }

    $column_list = implode(', ', $select_columns);

    // Find events with UUIDs that don't have visitor records
    $sql = "SELECT $column_list, 
                   COUNT(*) as event_count,
                   MAX(timestamp) as latest_timestamp
            FROM (
                SELECT DISTINCT r.uuid, $column_list
                FROM superpixel_resolution_log r 
                LEFT JOIN superpixel_visitors v ON r.uuid = v.uuid 
                WHERE v.uuid IS NULL 
                  AND r.uuid IS NOT NULL 
                  AND r.uuid != '' 
                  AND r.uuid != 'null'
                ORDER BY r.timestamp DESC
                LIMIT $limit
            ) as latest_events
            GROUP BY uuid";

    $result = $mysqli->query($sql);
    if (!$result) {
        debugLog("Error querying missing visitors for $debug_context: " . $mysqli->error);
        return ['backfilled_count' => 0, 'error_count' => 1];
    }

    $backfilled_count = 0;
    $error_count = 0;

    while ($row = $result->fetch_assoc()) {
        $event_context = "$debug_context-uuid_" . substr($row['uuid'], 0, 8);
        
        if (upsertVisitorFromEvent($mysqli, $row, $event_context)) {
            $backfilled_count++;
        } else {
            $error_count++;
        }
    }

    debugLog("Backfill complete for $debug_context: $backfilled_count backfilled, $error_count errors");
    
    return [
        'backfilled_count' => $backfilled_count,
        'error_count' => $error_count
    ];
}

/**
 * Debugging function - define if not exists
 * 
 * @param string $message Message to log
 */
if (!function_exists('debugLog')) {
    function debugLog($message) {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] $message");
        echo "[$timestamp] $message\n";
    }
}
?> 