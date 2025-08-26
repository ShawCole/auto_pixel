<?php
require_once __DIR__ . '/ingest_emails_from_event.php';
require_once '/opt/auto-pixel/process_visitor_emails.php';
// pixel_import.php for ThynkData - FINAL FIXED VERSION WITH PROPER FIELD MAPPING
// Include standardized visitor functions
require_once __DIR__ . '/visitor_upsert_functions.php';

header('Content-Type: application/json');

$dbHost = getenv('DB_HOST') ?: '34.31.66.104';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';
$client = isset($_GET['client']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['client']) : null;
$dbName = $client ?: (getenv('DB_NAME') ?: 'pixel');

$logFile = '/var/www/hook.thynkdata.com/pixel_import_debug.log';

// UUIDs that should never dedupe (test personas used across all DBs)
$NON_DEDUPE_UUIDS = [
  'dc0016d3803db4912441edb1b0', // Margaret Faz
];

// Logging function
function debugLog($message) {
    global $logFile;
    file_put_contents($logFile, "[" . date('c') . "] " . $message . "\n", FILE_APPEND);
}

// For GET requests (webhook tests)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $testConn = new mysqli($dbHost, $dbUser, $dbPass);
    if ($testConn->connect_error) {
        debugLog("GET request - MySQL connection failed: " . $testConn->connect_error);
        http_response_code(500);
        echo json_encode(['error' => 'MySQL connection failed']);
        exit;
    }
    $testConn->close();
    
    debugLog("GET request successful for client: " . ($client ?: 'none'));
    echo json_encode(['status' => 'success', 'message' => 'Webhook endpoint is reachable']);
    exit;
}

// For POST requests, connect to specific database
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    $err = "Connection failed: " . $mysqli->connect_error;
    debugLog("POST request - Database connection failed: " . $err);
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// FIXED Email function - properly handle all data types
function send_email($data, $subject_text) {
    $sendgridApiKey = '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';
    $fromEmail = 'noreply@accupointsolutions.email';
    $subject = '[THYNKDATA] ' . $subject_text;
    $toEmails = ['joseabreu@accupointsolutions.com', 'shaw@accupointsolutions.com'];

    $postBody = '';
    foreach ($data as $key => $value) {
        // Handle all possible data types safely
        if (is_array($value)) {
            $postBody .= $key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
        } elseif (is_object($value)) {
            $postBody .= $key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
        } elseif (is_null($value)) {
            $postBody .= $key . ': NULL' . "\n";
        } elseif (is_bool($value)) {
            $postBody .= $key . ': ' . ($value ? 'TRUE' : 'FALSE') . "\n";
        } else {
            $postBody .= $key . ': ' . strval($value) . "\n";
        }
    }

    $emailData = array(
        'from' => $fromEmail,
        'fromname' => "AccuPoint Solutions",
        'subject' => $subject,
        'html' => $postBody
    );

    foreach ($toEmails as $to) {
        $emailData['to'][] = $to;
    }

    $session = curl_init('https://api.sendgrid.com/api/mail.send.json');
    curl_setopt($session, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $sendgridApiKey));
    curl_setopt($session, CURLOPT_POST, true);
    curl_setopt($session, CURLOPT_POSTFIELDS, $emailData);
    curl_setopt($session, CURLOPT_HEADER, false);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($session);
    curl_close($session);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        debugLog("Processing POST request for client: " . ($client ?: 'none'));
        
        $rawData = file_get_contents('php://input');
	debugLog("=== New webhook request for client: " . ($client ?: 'none') . " ===");
	debugLog("Raw input received (first 500 chars): " . substr($rawData, 0, 500));
        $decoded = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON: " . json_last_error_msg());
        }

        debugLog("Decoded payload size: " . count($decoded));

	if(isset($decoded['events']) && is_array($decoded['events'])) {
            $events = $decoded['events'];
            debugLog("Processing " . count($events) . " events");
    
            foreach ($events as $eventIndex => $event) {
                debugLog("Processing event $eventIndex");
                
		$pixel_data = isset($event['resolution']) ? $event['resolution'] : [];
		if (is_string($pixel_data)) {
		  $tmp = json_decode($pixel_data, true);
		  if (is_array($tmp)) { $pixel_data = $tmp; }
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
                    "activity_end_date" => isset($event['activity_end_date']) ? strval($event['activity_end_date']) : '', 
                    
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
  if (isset($ed["url"]))        { $insert_data["url"]        = (string)$ed["url"]; }
  if (isset($ed["referrer"]))   { $insert_data["referrer"]   = (string)$ed["referrer"]; }
  if (isset($ed["title"]))      { $insert_data["title"]      = (string)$ed["title"]; }
  if (isset($ed["timestamp"]))  { $insert_data["timestamp"]  = (string)$ed["timestamp"]; }
  if (array_key_exists("percentage", $ed)) { $insert_data["percentage"] = (string)$ed["percentage"]; }
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
      $s = (string)$rawCnh;
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
      $s = (string)$rawJth;
      if ($s !== "" && $s !== "Array") {
        $insert_data["job_title_history"] = $s;
      }
    }
  }
}

		/* Dedupe bypass for whitelisted test personas */
		if (empty($insert_data['uuid']) && isset($pixel_data['UUID'])) { $insert_data['uuid'] = (string)$pixel_data['UUID']; }
		if (!empty($insert_data['uuid']) && in_array($insert_data['uuid'], $NON_DEDUPE_UUIDS, true)) {
		  $token = 'wldup=' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
		  $url = isset($insert_data['url']) ? (string)$insert_data['url'] : '';
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
  $evtTsRaw = isset($insert_data["event_timestamp"]) ? (string)$insert_data["event_timestamp"] : "";
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
 
		// Build SQL with safe escaping
		$columns = [];
		$values  = [];
		foreach ($insert_data as $key => $value) {
		  if (is_array($value) || is_object($value)) { $value = json_encode($value); }
		  if (is_bool($value)) { $value = $value ? '1' : '0'; }
		  $columns[] = "`" . $mysqli->real_escape_string($key) . "`";
		  $values[]  = "'" . $mysqli->real_escape_string($value ?? '') . "'";
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
        // Parse emails now and populate NPN/CRD from match_emails for this UUID
        $uuid = $insert_data["uuid"] ?? "";
        if ($uuid !== "") {
          try {
            $emailResults = processVisitorEmails($client, $uuid, true, false);
    ingestEmailsFromEvent($mysqli, $client, $uuid);
          } catch (Throwable $e) {
            debugLog("Email parse/NPN-CRD step error: " . $e->getMessage());
          }
        }

		// fallback when trigger is missing
		$triggerExists = false;
		$chk = $mysqli->query("SELECT COUNT(*) c FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='after_resolution_log_insert_visitor_update'");
		if ($chk && ($row = $chk->fetch_assoc())) { $triggerExists = ((int)$row['c'] > 0); }
		if (!$triggerExists && !empty($insert_data['uuid'])) {
		  $uuid = $mysqli->real_escape_string($insert_data['uuid']);
		  $url = $mysqli->real_escape_string($insert_data['url'] ?? '');
		  $element = $mysqli->real_escape_string($insert_data['element'] ?? '');
		  $percentage = isset($insert_data['percentage']) && preg_match('/^\d+$/', (string)$insert_data['percentage'])
		                ? (int)$insert_data['percentage'] : 'NULL';
		  $referrer = $mysqli->real_escape_string($insert_data['referrer'] ?? '');
		  $evt_ts = $mysqli->real_escape_string($insert_data['event_timestamp'] ?? '');
		  $evt_type = $mysqli->real_escape_string($insert_data['event_type'] ?? '');
		
		  $fallback = "
		    INSERT INTO superpixel_visitors (uuid, url, element, percentage, referrer, event_timestamp, event_type, event_count, first_seen_at, last_seen_at)
		    VALUES ('$uuid', '$url', '$element', $percentage, '$referrer', '$evt_ts', '$evt_type', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		    ON DUPLICATE KEY UPDATE
		      url = IF('$url'<>'', '$url', url),
		      element = IF('$element'<>'', '$element', element),
		      percentage = IF($percentage IS NOT NULL, $percentage, percentage),
		      referrer = IF('$referrer'<>'', '$referrer', referrer),
		      event_timestamp = IF('$evt_ts'<>'', '$evt_ts', event_timestamp),
		      event_type = IF('$evt_type'<>'', '$evt_type', event_type),
		      event_count = event_count + 1,
		      last_seen_at = CURRENT_TIMESTAMP
		  ";
		  if (!$mysqli->query($fallback)) {
		    debugLog('Fallback visitor upsert failed: ' . $mysqli->error);
		  } else {
		    debugLog('Fallback visitor upsert applied (trigger missing)');
		  }
		}


                // Visitor creation/update is now handled automatically by database trigger
                // The trigger 'after_resolution_log_insert_visitor_update' will:
                // - Create new visitors for new UUIDs
                // - Update existing visitors with new event data
                // - Preserve business emails over personal emails
                // - Track event counts and last seen timestamps
                
                // Skip the old inline visitor logic
                if (false && !empty($insert_data['uuid'])) {
                    // Extract only visitor profile fields (exclude event-specific fields)
                    $visitor_fields = [
                        'uuid', 'first_name', 'last_name', 'personal_address', 'personal_city', 
                        'personal_state', 'personal_zip', 'personal_zip4', 'age_range', 'children', 
                        'gender', 'homeowner', 'married', 'net_worth', 'income_range', 'direct_number', 
                        'direct_number_dnc', 'mobile_phone', 'mobile_phone_dnc', 'personal_phone', 
                        'personal_phone_dnc', 'business_email', 'personal_emails', 'deep_verified_emails', 
                        'sha256_personal_email', 'sha256_business_email', 'job_title', 'headline', 
                        'department', 'seniority_level', 'inferred_years_experience', 'company_name_history', 
                        'job_title_history', 'education_history', 'company_address', 'company_description', 
                        'company_domain', 'company_employee_count', 'company_linkedin_url', 'company_name', 
                        'company_phone', 'company_revenue', 'company_sic', 'company_naics', 'company_city', 
                        'company_state', 'company_zip', 'company_industry', 'linkedin_url', 'twitter_url', 
                        'facebook_url', 'social_connections', 'skills', 'interests', 'skiptrace_match_score', 
                        'skiptrace_name', 'skiptrace_address', 'skiptrace_city', 'skiptrace_state', 
                        'skiptrace_zip', 'skiptrace_landline_numbers', 'skiptrace_wireless_numbers', 
                        'skiptrace_credit_rating', 'skiptrace_dnc', 'skiptrace_exact_age', 'skiptrace_ethnic_code', 
                        'skiptrace_language_code', 'skiptrace_ip', 'skiptrace_b2b_address', 'skiptrace_b2b_phone', 
                        'skiptrace_b2b_source', 'skiptrace_b2b_website', 'valid_phones'
                    ];
                    
                    $visitor_data = [];
                    foreach ($visitor_fields as $field) {
                        if (isset($insert_data[$field])) {
                            $visitor_data[$field] = $insert_data[$field];
                        }
                    }
                    
                    // Add event fields (matching resolution log naming for consistency)
                    $visitor_data['hem_sha256'] = $insert_data['hem_sha256'] ?? null;
                    $visitor_data['url'] = $insert_data['url'] ?? null;
                    $visitor_data['element'] = $insert_data['element'] ?? null;
                    $visitor_data['percentage'] = (!empty($insert_data['percentage']) && $insert_data['percentage'] !== '') ? (int)$insert_data['percentage'] : null;
                    $visitor_data['referrer'] = $insert_data['referrer'] ?? null;
                    $visitor_data['event_timestamp'] = $insert_data['timestamp'] ?? null;
                    $visitor_data['event_type'] = $insert_data['event_type'] ?? null;
                    
                    if (!empty($visitor_data)) {
                        // Build INSERT ... ON DUPLICATE KEY UPDATE query
                        $visitor_columns = [];
                        $visitor_values = [];
                        $update_parts = [];
                        
                        foreach ($visitor_data as $key => $value) {
                            $escaped_key = "`" . $mysqli->real_escape_string($key) . "`";
                            $escaped_value = ($value === null || $value === '') ? "NULL" : "'" . $mysqli->real_escape_string($value) . "'";
                            
                            $visitor_columns[] = $escaped_key;
                            $visitor_values[] = $escaped_value;
                            
                            // Use COALESCE to not overwrite existing data with empty values
                            if ($key !== 'uuid') { // Don't update UUID in UPDATE clause
                                // Always update event-related fields (latest visit data)
                                if (in_array($key, ['url', 'element', 'percentage', 'referrer', 'event_timestamp', 'event_type', 'hem_sha256'])) {
                                    $update_parts[] = "$escaped_key = $escaped_value";
                                } else {
                                    $update_parts[] = "$escaped_key = COALESCE(NULLIF($escaped_value, ''), $escaped_key, $escaped_value)";
                                }
                            }
                        }
                        
                        $visitor_sql = "INSERT INTO superpixel_visitors (" . implode(",", $visitor_columns) . ") 
                                       VALUES (" . implode(",", $visitor_values) . ")
                                       ON DUPLICATE KEY UPDATE " . implode(", ", $update_parts) . ",
                                       event_count = event_count + 1,
                                       last_seen_at = CURRENT_TIMESTAMP";
                        
                        debugLog("Executing visitor upsert for event $eventIndex");
                        
                        if (!$mysqli->query($visitor_sql)) {
                            $error = "Visitor upsert failed for event $eventIndex: " . $mysqli->error;
                            debugLog($error);
                            // Don't throw exception here - event was already saved successfully
                            debugLog("Warning: Event saved but visitor profile update failed");
                        } else {
                            debugLog("Successfully upserted visitor profile for event $eventIndex");
            }
        }
                } else {
                    debugLog("Warning: No UUID found for event $eventIndex - skipping visitor profile update");
                }
            }
        }

        debugLog("All events processed successfully");
        echo json_encode(['status' => 'success']);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    $errorMsg = "Fatal error: " . $e->getMessage();
    debugLog($errorMsg);
    send_email(['Error' => $errorMsg], "Fatal Error in Hook Handler");
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}

$mysqli->close();
?>
