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

        // Define all possible visitor fields
        $possible_visitor_fields = [
            'uuid', 'first_name', 'last_name', 'company_name', 'job_title',
            'personal_emails', 'mobile_phone', 'personal_address', 'personal_city', 
            'personal_state', 'personal_zip', 'personal_zip4', 'age_range', 
            'children', 'gender', 'homeowner', 'married', 'net_worth', 
            'income_range', 'direct_number', 'direct_number_dnc', 
            'mobile_phone_dnc', 'hem_sha256', 'last_visited_url', 'last_element',
            'last_percentage', 'last_referrer', 'last_timestamp', 'last_event',
            'npn', 'crd'
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
        
        // Add last_seen_at update if the column exists
        if (in_array('last_seen_at', $existing_columns)) {
            $update_clauses[] = "last_seen_at = CURRENT_TIMESTAMP";
        } elseif (in_array('updated_at', $existing_columns)) {
            $update_clauses[] = "updated_at = CURRENT_TIMESTAMP";
        }
        
        // Add first_seen_at if it's an insert (only if column exists and not already set)
        if (in_array('first_seen_at', $existing_columns) && !isset($visitor_data['first_seen_at'])) {
            $visitor_columns[] = "`first_seen_at`";
            $visitor_values[] = "CURRENT_TIMESTAMP";
        } elseif (in_array('created_at', $existing_columns) && !isset($visitor_data['created_at'])) {
            $visitor_columns[] = "`created_at`";
            $visitor_values[] = "CURRENT_TIMESTAMP";
        }

        $visitor_sql = "INSERT INTO superpixel_visitors (" . implode(",", $visitor_columns) . ") 
                       VALUES (" . implode(",", $visitor_values) . ")
                       ON DUPLICATE KEY UPDATE " . implode(", ", $update_clauses);

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
    // Get list of missing UUIDs first, then get their latest event data
    $sql = "SELECT DISTINCT r.uuid
            FROM superpixel_resolution_log r 
            LEFT JOIN superpixel_visitors v ON r.uuid = v.uuid 
            WHERE v.uuid IS NULL 
              AND r.uuid IS NOT NULL 
              AND r.uuid != '' 
              AND r.uuid != 'null'
            LIMIT $limit";

    $uuidResult = $mysqli->query($sql);
    if (!$uuidResult) {
        debugLog("Error getting missing UUIDs for $debug_context: " . $mysqli->error);
        return ['backfilled_count' => 0, 'error_count' => 1];
    }

    $backfilled_count = 0;
    $error_count = 0;

    // For each missing UUID, get the latest event and create visitor
    while ($uuidRow = $uuidResult->fetch_assoc()) {
        $uuid = $uuidRow['uuid'];
        
        // Get the latest event for this UUID
        $eventSql = "SELECT " . implode(', ', $select_columns) . "
                     FROM superpixel_resolution_log 
                     WHERE uuid = ? 
                     ORDER BY timestamp DESC 
                     LIMIT 1";
        
        $stmt = $mysqli->prepare($eventSql);
        if (!$stmt) {
            debugLog("Failed to prepare event query for UUID $uuid: " . $mysqli->error);
            $error_count++;
            continue;
        }
        
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $eventResult = $stmt->get_result();
        
        if ($eventData = $eventResult->fetch_assoc()) {
            $event_context = "$debug_context-uuid_" . substr($uuid, 0, 8);
            
            if (upsertVisitorFromEvent($mysqli, $eventData, $event_context)) {
                $backfilled_count++;
            } else {
                $error_count++;
            }
        } else {
            $error_count++;
        }
        
        $stmt->close();
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
