<?php
// pixel_import.php for ThynkData - PATCHED LOCAL VERSION
// Removes absolute paths, headers, and fixes logging permissions

// Include standardized visitor functions (Relative path)
require_once __DIR__ . '/visitor_upsert_functions.php';

// Removed header('Content-Type: application/json'); // Not needed for CLI/subprocess context

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';
$client = isset($_GET['client']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['client']) : (getenv('CLIENT_NAME') ?: null);
$dbName = $client ?: (getenv('DB_NAME') ?: 'pixel');

// Use LOCAL log file in current directory to avoid permission issues
$logFile = __DIR__ . '/pixel_import_debug.log';

// UUIDs that should never dedupe (test personas used across all DBs)
$NON_DEDUPE_UUIDS = [
    'dc0016d3803db4912441edb1b0', // Margaret Faz
];

// Logging function
function debugLog($message)
{
    global $logFile;
    file_put_contents($logFile, "[" . date('c') . "] " . $message . "\n", FILE_APPEND);
}

// For GET requests (webhook tests)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    debugLog("GET webhook test received for client: " . ($client ?: 'none'));
    echo json_encode([
        'status' => 'success',
        'message' => 'Webhook endpoint is reachable',
        'client' => $client,
        'mode' => 'webhook-test'
    ]);
    exit;
}

// For POST requests, connect to specific database
try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }
} catch (Throwable $e) {
    debugLog("POST request - Database connection failed: " . $e->getMessage());
    echo json_encode(['error' => 'DB connection failed', 'details' => $e->getMessage()]);
    exit(1);
}

// Removed send_email function to prevent spam during bulk operations
function send_email($data, $subject_text)
{
    // No-op
}

try {
    // Check typical CLI/Env indicators for POST method simulation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || getenv('REQUEST_METHOD') === 'POST') {
        debugLog("Processing POST request for client: " . ($client ?: 'none'));

        // Check for CLI argument (file path) or use Stdin
        global $argv;
        $cliFile = isset($argv[1]) ? $argv[1] : null;

        if ($cliFile && file_exists($cliFile)) {
            $rawData = file_get_contents($cliFile);
            debugLog("Reading payload from file: $cliFile");
        } else {
            $rawData = file_get_contents('php://input');
            debugLog("Reading payload from stdin");
        }
        // debugLog("=== New webhook request ==="); // Reduced noise
        // debugLog("Raw input received (first 500 chars): " . substr($rawData, 0, 500));
        $decoded = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $snippet = substr($rawData, 0, 1000);
            throw new Exception("Invalid JSON: " . json_last_error_msg() . ". Input snippet: " . $snippet);
        }

        debugLog("Decoded payload event count: " . (isset($decoded['events']) ? count($decoded['events']) : 0));

        if (isset($decoded['events']) && is_array($decoded['events'])) {
            $events = $decoded['events'];

            foreach ($events as $eventIndex => $event) {

                $pixel_data = isset($event['resolution']) ? $event['resolution'] : [];
                if (is_string($pixel_data)) {
                    $tmp = json_decode($pixel_data, true);
                    if (is_array($tmp)) {
                        $pixel_data = $tmp;
                    }
                }

                // FIXED: Map SimpleAudience UPPERCASE fields to lowercase database columns
                $insert_data = array(
                    // Basic event fields
                    "pixel_id" => isset($event['pixel_id']) ? strval($event['pixel_id']) : '',
                    "hem_sha256" => isset($event['hem_sha256']) ? strval($event['hem_sha256']) : '',
                    "event_timestamp" => isset($event['event_timestamp']) ? strval($event['event_timestamp']) : '',
                    "event_type" => isset($event['event_type']) ? strval($event['event_type']) : '',
                    "referrer_url" => isset($event_data['referrer_url']) ? strval($event_data['referrer_url']) : '',
                    "ip_address" => isset($event['ip_address']) ? strval($event['ip_address']) : '',
                    "activity_start_date" => isset($event['activity_start_date']) ? strval($event['activity_start_date']) : '',
                    "activity_start_date" => isset($event['activity_start_date']) ? strval($event['activity_start_date']) : '',
                    "activity_end_date" => isset($event['activity_end_date']) ? strval($event['activity_end_date']) : '',
                    "dedupe_uuid" => isset($event['dedupe_uuid']) ? strval($event['dedupe_uuid']) : '',

                    // Event data fields
                    "title" => isset($event_data['title']) ? strval($event_data['title']) : '',
                    "url" => isset($event_data['url']) ? strval($event_data['url']) : '',
                    "referrer" => isset($event_data['referrer']) ? strval($event_data['referrer']) : '',
                    "timestamp" => isset($event_data['timestamp']) ? strval($event_data['timestamp']) : '',
                    "percentage" => isset($event_data['percentage']) ? strval($event_data['percentage']) : '',
                    "element" => isset($event_data['element']) ? json_encode($event_data['element']) : '',

                    // Personal info - map UPPERCASE SimpleAudience fields to lowercase DB columns
                    "uuid" => isset($pixel_data['UUID']) ? strval($pixel_data['UUID']) : '',
                    "first_name" => isset($pixel_data['FIRST_NAME']) ? strval($pixel_data['FIRST_NAME']) : '',
                    "last_name" => isset($pixel_data['LAST_NAME']) ? strval($pixel_data['LAST_NAME']) : '',
                    "personal_address" => isset($pixel_data['PERSONAL_ADDRESS']) ? strval($pixel_data['PERSONAL_ADDRESS']) : '',
                    "personal_city" => isset($pixel_data['PERSONAL_CITY']) ? strval($pixel_data['PERSONAL_CITY']) : '',
                    "personal_state" => isset($pixel_data['PERSONAL_STATE']) ? strval($pixel_data['PERSONAL_STATE']) : '',
                    "personal_zip" => isset($pixel_data['PERSONAL_ZIP']) ? strval($pixel_data['PERSONAL_ZIP']) : '',
                    "personal_zip4" => isset($pixel_data['PERSONAL_ZIP4']) ? strval($pixel_data['PERSONAL_ZIP4']) : '',
                    "age_range" => isset($pixel_data['AGE_RANGE']) ? strval($pixel_data['AGE_RANGE']) : '',
                    "children" => isset($pixel_data['CHILDREN']) ? strval($pixel_data['CHILDREN']) : '',
                    "gender" => isset($pixel_data['GENDER']) ? strval($pixel_data['GENDER']) : '',
                    "homeowner" => isset($pixel_data['HOMEOWNER']) ? strval($pixel_data['HOMEOWNER']) : '',
                    "married" => isset($pixel_data['MARRIED']) ? strval($pixel_data['MARRIED']) : '',
                    "income_range" => isset($pixel_data['INCOME_RANGE']) ? strval($pixel_data['INCOME_RANGE']) : '',
                    "net_worth" => isset($pixel_data['NET_WORTH']) ? strval($pixel_data['NET_WORTH']) : '',

                    // Contact info
                    "direct_number" => isset($pixel_data['DIRECT_NUMBER']) ? strval($pixel_data['DIRECT_NUMBER']) : '',
                    "direct_number_dnc" => isset($pixel_data['DIRECT_NUMBER_DNC']) ? strval($pixel_data['DIRECT_NUMBER_DNC']) : '',
                    "mobile_phone" => isset($pixel_data['MOBILE_PHONE']) ? strval($pixel_data['MOBILE_PHONE']) : '',
                    "mobile_phone_dnc" => isset($pixel_data['MOBILE_PHONE_DNC']) ? strval($pixel_data['MOBILE_PHONE_DNC']) : '',
                    "personal_phone" => isset($pixel_data['PERSONAL_PHONE']) ? strval($pixel_data['PERSONAL_PHONE']) : '',
                    "personal_phone_dnc" => isset($pixel_data['PERSONAL_PHONE_DNC']) ? strval($pixel_data['PERSONAL_PHONE_DNC']) : '',
                    "personal_emails" => isset($pixel_data['PERSONAL_EMAILS']) ? strval($pixel_data['PERSONAL_EMAILS']) : '',
                    "business_email" => isset($pixel_data['BUSINESS_EMAIL']) ? strval($pixel_data['BUSINESS_EMAIL']) : '',
                    "deep_verified_emails" => isset($pixel_data['DEEP_VERIFIED_EMAILS']) ? strval($pixel_data['DEEP_VERIFIED_EMAILS']) : '',
                    "sha256_personal_email" => isset($pixel_data['SHA256_PERSONAL_EMAIL']) ? strval($pixel_data['SHA256_PERSONAL_EMAIL']) : '',
                    "sha256_business_email" => isset($pixel_data['SHA256_BUSINESS_EMAIL']) ? strval($pixel_data['SHA256_BUSINESS_EMAIL']) : '',

                    // Company info
                    "company_address" => isset($pixel_data['COMPANY_ADDRESS']) ? strval($pixel_data['COMPANY_ADDRESS']) : '',
                    "company_name" => isset($pixel_data['COMPANY_NAME']) ? strval($pixel_data['COMPANY_NAME']) : '',
                    "company_city" => isset($pixel_data['COMPANY_CITY']) ? strval($pixel_data['COMPANY_CITY']) : '',
                    "company_state" => isset($pixel_data['COMPANY_STATE']) ? strval($pixel_data['COMPANY_STATE']) : '',
                    "company_zip" => isset($pixel_data['COMPANY_ZIP']) ? strval($pixel_data['COMPANY_ZIP']) : '',
                    "company_description" => isset($pixel_data['COMPANY_DESCRIPTION']) ? strval($pixel_data['COMPANY_DESCRIPTION']) : '',
                    "company_domain" => isset($pixel_data['COMPANY_DOMAIN']) ? strval($pixel_data['COMPANY_DOMAIN']) : '',
                    "company_employee_count" => isset($pixel_data['COMPANY_EMPLOYEE_COUNT']) ? strval($pixel_data['COMPANY_EMPLOYEE_COUNT']) : '',
                    "company_industry" => isset($pixel_data['COMPANY_INDUSTRY']) ? strval($pixel_data['COMPANY_INDUSTRY']) : '',
                    "company_phone" => isset($pixel_data['COMPANY_PHONE']) ? strval($pixel_data['COMPANY_PHONE']) : '',
                    "company_revenue" => isset($pixel_data['COMPANY_REVENUE']) ? strval($pixel_data['COMPANY_REVENUE']) : '',
                    "company_sic" => isset($pixel_data['COMPANY_SIC']) ? strval($pixel_data['COMPANY_SIC']) : '',
                    "company_naics" => isset($pixel_data['COMPANY_NAICS']) ? strval($pixel_data['COMPANY_NAICS']) : '',
                    "company_name_history" => isset($pixel_data['COMPANY_NAME_HISTORY']) ? strval($pixel_data['COMPANY_NAME_HISTORY']) : '',

                    // Professional info
                    "job_title" => isset($pixel_data['JOB_TITLE']) ? strval($pixel_data['JOB_TITLE']) : '',
                    "job_title_history" => isset($pixel_data['JOB_TITLE_HISTORY']) ? strval($pixel_data['JOB_TITLE_HISTORY']) : '',
                    "headline" => isset($pixel_data['HEADLINE']) ? strval($pixel_data['HEADLINE']) : '',
                    "department" => isset($pixel_data['DEPARTMENT']) ? strval($pixel_data['DEPARTMENT']) : '',
                    "seniority_level" => isset($pixel_data['SENIORITY_LEVEL']) ? strval($pixel_data['SENIORITY_LEVEL']) : '',
                    "inferred_years_experience" => isset($pixel_data['INFERRED_YEARS_EXPERIENCE']) ? strval($pixel_data['INFERRED_YEARS_EXPERIENCE']) : '',
                    "education_history" => isset($pixel_data['EDUCATION_HISTORY']) ? strval($pixel_data['EDUCATION_HISTORY']) : '',

                    // Social
                    "linkedin_url" => isset($pixel_data['LINKEDIN_URL']) ? strval($pixel_data['LINKEDIN_URL']) : '',
                    "twitter_url" => isset($pixel_data['TWITTER_URL']) ? strval($pixel_data['TWITTER_URL']) : '',
                    "facebook_url" => isset($pixel_data['FACEBOOK_URL']) ? strval($pixel_data['FACEBOOK_URL']) : '',
                    "social_connections" => isset($pixel_data['SOCIAL_CONNECTIONS']) ? strval($pixel_data['SOCIAL_CONNECTIONS']) : '',
                    "skills" => isset($pixel_data['SKILLS']) ? strval($pixel_data['SKILLS']) : '',
                    "interests" => isset($pixel_data['INTERESTS']) ? strval($pixel_data['INTERESTS']) : '',

                    // Skiptrace data
                    "skiptrace_match_score" => isset($pixel_data['SKIPTRACE_MATCH_SCORE']) ? strval($pixel_data['SKIPTRACE_MATCH_SCORE']) : '',
                    "skiptrace_name" => isset($pixel_data['SKIPTRACE_NAME']) ? strval($pixel_data['SKIPTRACE_NAME']) : '',
                    "skiptrace_address" => isset($pixel_data['SKIPTRACE_ADDRESS']) ? strval($pixel_data['SKIPTRACE_ADDRESS']) : '',
                    "skiptrace_city" => isset($pixel_data['SKIPTRACE_CITY']) ? strval($pixel_data['SKIPTRACE_CITY']) : '',
                    "skiptrace_state" => isset($pixel_data['SKIPTRACE_STATE']) ? strval($pixel_data['SKIPTRACE_STATE']) : '',
                    "skiptrace_zip" => isset($pixel_data['SKIPTRACE_ZIP']) ? strval($pixel_data['SKIPTRACE_ZIP']) : '',
                    "skiptrace_landline_numbers" => isset($pixel_data['SKIPTRACE_LANDLINE_NUMBERS']) ? strval($pixel_data['SKIPTRACE_LANDLINE_NUMBERS']) : '',
                    "skiptrace_wireless_numbers" => isset($pixel_data['SKIPTRACE_WIRELESS_NUMBERS']) ? strval($pixel_data['SKIPTRACE_WIRELESS_NUMBERS']) : '',
                    "skiptrace_credit_rating" => isset($pixel_data['SKIPTRACE_CREDIT_RATING']) ? strval($pixel_data['SKIPTRACE_CREDIT_RATING']) : '',
                    "skiptrace_dnc" => isset($pixel_data['SKIPTRACE_DNC']) ? strval($pixel_data['SKIPTRACE_DNC']) : '',
                    "skiptrace_exact_age" => isset($pixel_data['SKIPTRACE_EXACT_AGE']) ? strval($pixel_data['SKIPTRACE_EXACT_AGE']) : '',
                    "skiptrace_ethnic_code" => isset($pixel_data['SKIPTRACE_ETHNIC_CODE']) ? strval($pixel_data['SKIPTRACE_ETHNIC_CODE']) : '',
                    "skiptrace_language_code" => isset($pixel_data['SKIPTRACE_LANGUAGE_CODE']) ? strval($pixel_data['SKIPTRACE_LANGUAGE_CODE']) : '',
                    "skiptrace_ip" => isset($pixel_data['SKIPTRACE_IP']) ? strval($pixel_data['SKIPTRACE_IP']) : '',
                    "skiptrace_b2b_address" => isset($pixel_data['SKIPTRACE_B2B_ADDRESS']) ? strval($pixel_data['SKIPTRACE_B2B_ADDRESS']) : '',
                    "skiptrace_b2b_phone" => isset($pixel_data['SKIPTRACE_B2B_PHONE']) ? strval($pixel_data['SKIPTRACE_B2B_PHONE']) : '',
                    "skiptrace_b2b_source" => isset($pixel_data['SKIPTRACE_B2B_SOURCE']) ? strval($pixel_data['SKIPTRACE_B2B_SOURCE']) : '',
                    "skiptrace_b2b_website" => isset($pixel_data['SKIPTRACE_B2B_WEBSITE']) ? strval($pixel_data['SKIPTRACE_B2B_WEBSITE']) : '',

                    // Other fields
                    "valid_phones" => isset($pixel_data['VALID_PHONES']) ? strval($pixel_data['VALID_PHONES']) : ''
                );
                /* Map nested event_data fields into insert_data before INSERT */
                if (isset($event["event_data"])) {
                    $ed = is_string($event["event_data"]) ? (json_decode($event["event_data"], true) ?? []) : $event["event_data"];
                    if (isset($ed["url"])) {
                        $insert_data["url"] = (string) $ed["url"];
                    }
                    if (isset($ed["referrer"])) {
                        $insert_data["referrer"] = (string) $ed["referrer"];
                    }
                    if (isset($ed["title"])) {
                        $insert_data["title"] = (string) $ed["title"];
                    }
                    if (isset($ed["timestamp"])) {
                        $insert_data["timestamp"] = (string) $ed["timestamp"];
                    }
                    if (array_key_exists("percentage", $ed)) {
                        $insert_data["percentage"] = (string) $ed["percentage"];
                    }
                    if (isset($ed["element"])) {
                        $insert_data["element"] = is_string($ed["element"]) ? $ed["element"] : json_encode($ed["element"]);
                    }
                }

                // Normalize array fields to JSON if needed
                if (isset($pixel_data["COMPANY_NAME_HISTORY"]) && (is_array($pixel_data["COMPANY_NAME_HISTORY"]) || is_object($pixel_data["COMPANY_NAME_HISTORY"]))) {
                    $insert_data["company_name_history"] = json_encode($pixel_data["COMPANY_NAME_HISTORY"]);
                }
                if (isset($pixel_data["JOB_TITLE_HISTORY"]) && (is_array($pixel_data["JOB_TITLE_HISTORY"]) || is_object($pixel_data["JOB_TITLE_HISTORY"]))) {
                    $insert_data["job_title_history"] = json_encode($pixel_data["JOB_TITLE_HISTORY"]);
                }

                // ROBUST history normalization (resolution.*)
                {
                    $rawCnh = $pixel_data["COMPANY_NAME_HISTORY"] ?? null;
                    if ($rawCnh !== null) {
                        if (is_array($rawCnh) || is_object($rawCnh)) {
                            $insert_data["company_name_history"] = json_encode($rawCnh, JSON_UNESCAPED_SLASHES);
                        } else {
                            $s = (string) $rawCnh;
                            if ($s !== "" && $s !== "Array") {
                                $insert_data["company_name_history"] = $s;
                            }
                        }
                    }
                    $rawJth = $pixel_data["JOB_TITLE_HISTORY"] ?? null;
                    if ($rawJth !== null) {
                        if (is_array($rawJth) || is_object($rawJth)) {
                            $insert_data["job_title_history"] = json_encode($rawJth, JSON_UNESCAPED_SLASHES);
                        } else {
                            $s = (string) $rawJth;
                            if ($s !== "" && $s !== "Array") {
                                $insert_data["job_title_history"] = $s;
                            }
                        }
                    }
                }

                /* Dedupe bypass for whitelisted test personas */
                if (empty($insert_data['uuid']) && isset($pixel_data['UUID'])) {
                    $insert_data['uuid'] = (string) $pixel_data['UUID'];
                }
                if (!empty($insert_data['uuid']) && in_array($insert_data['uuid'], $NON_DEDUPE_UUIDS, true)) {
                    $token = 'wldup=' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                    $url = isset($insert_data['url']) ? (string) $insert_data['url'] : '';
                    if ($url === '' || $url === null) {
                        $insert_data['url'] = 'about:blank?' . $token;
                    } else {
                        $qPos = strpos($url, '?');
                        if ($qPos === false) {
                            $insert_data['url'] = $url . '?' . $token;
                        } else {
                            $insert_data['url'] = substr($url, 0, $qPos + 1) . $token . '&' . substr($url, $qPos + 1);
                        }
                        if (strlen($insert_data['url']) > 1024) {
                            $insert_data['url'] = substr($insert_data['url'], 0, 1024);
                        }
                    }
                    debugLog("Whitelist dedupe bypass applied for UUID {$insert_data['uuid']}");
                }


                // Persist full raw event JSON for audit/replay (central table), dedup by hash
                try {
                    $rawJson = json_encode($event, JSON_UNESCAPED_SLASHES);
                    $payloadHash = hash("sha256", $rawJson);
                    $uuidRaw = $insert_data["uuid"] ?? "";
                    $evtTsRaw = isset($insert_data["event_timestamp"]) ? (string) $insert_data["event_timestamp"] : "";
                    $c = $mysqli->real_escape_string($client);
                    $u = $mysqli->real_escape_string($uuidRaw);
                    $t = $mysqli->real_escape_string($evtTsRaw);
                    $p = $mysqli->real_escape_string($rawJson);
                    $h = $mysqli->real_escape_string($payloadHash);
                    $mysqli->query("INSERT IGNORE INTO pixel.raw_events (client_name, uuid, event_timestamp, payload, payload_sha256)
                  VALUES (\"$c\", \"$u\", \"$t\", \"$p\", \"$h\")");
                } catch (Throwable $e) {
                    debugLog("Raw event persist error: " . $e->getMessage());
                }

                // CRITICAL: Skip events without a valid UUID - these cannot be resolved to visitors
                $eventUuid = $insert_data['uuid'] ?? '';
                if (empty($eventUuid) || trim($eventUuid) === '') {
                    debugLog("WARNING: Skipping event $eventIndex - no UUID present (unresolved visitor)");
                    continue;
                }

                // Build SQL with safe escaping
                $columns = [];
                $values = [];
                foreach ($insert_data as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value);
                    }
                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    }
                    $columns[] = "`" . $mysqli->real_escape_string($key) . "`";
                    $values[] = "'" . $mysqli->real_escape_string($value ?? '') . "'";
                }

                // DEDUPE CHECK: Check if this event already exists in the database
                // Deduping by UUID + Event Type + Exact Timestamp
                $chkUuid = $mysqli->real_escape_string($insert_data['uuid'] ?? '');
                $chkType = $mysqli->real_escape_string($insert_data['event_type'] ?? '');
                $chkTime = $mysqli->real_escape_string($insert_data['event_timestamp'] ?? '');

                if ($chkUuid !== '' && $chkTime !== '') {
                    $checkSql = "SELECT 1 FROM superpixel_resolution_log 
                                 WHERE uuid = '$chkUuid' 
                                 AND event_type = '$chkType' 
                                 AND event_timestamp = '$chkTime' 
                                 LIMIT 1";

                    $checkResult = $mysqli->query($checkSql);
                    if ($checkResult && $checkResult->num_rows > 0) {
                        debugLog("Skipping duplicate event (already in DB): $chkUuid / $chkType / $chkTime");
                        continue;
                    }
                }

                // Step 1: Insert raw event into superpixel_resolution_log
                $sql = "INSERT IGNORE INTO superpixel_resolution_log (" . implode(",", $columns) . ") VALUES (" . implode(",", $values) . ")";
                debugLog("Executing event SQL for event $eventIndex");
                if (!$mysqli->query($sql)) {
                    $error = "Event insert failed for event $eventIndex: " . $mysqli->error;
                    debugLog($error);
                    throw new Exception($error);
                }
                if ($mysqli->affected_rows === 0) {
                    debugLog("Duplicate event skipped (no-op) for event $eventIndex");
                    continue;
                }

                debugLog("Successfully inserted event $eventIndex to superpixel_resolution_log");

                // Parse emails using included functions (fallback logic inside upsertVisitorFromEvent)
                $uuid = $insert_data["uuid"] ?? "";

                // Use the standardized visitor upsert function
                $event_context = "cli-import-$eventIndex";
                upsertVisitorFromEvent($mysqli, $insert_data, $event_context);

            }
        }

        debugLog("All events processed successfully");
        echo json_encode(['status' => 'success']);

    } else {
        // http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    $errorMsg = "Fatal error: " . $e->getMessage();
    debugLog($errorMsg);
    // http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
    exit(1);
}

$mysqli->close();
?>