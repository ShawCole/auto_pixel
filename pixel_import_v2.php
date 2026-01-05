<?php
/**
 * pixel_import_v2.php
 * 
 * ThynkData V2 Ingestion Engine
 * Location: /var/www/hook.thynkdata.com/pixel_import_v2.php
 * 
 * Role:
 * 1. Central Validation (pixel_sheets)
 * 2. Double-Write Logging (master_raw_events + raw_events)
 * 3. Immutable Event Insert (events)
 * 4. Golden Record Upsert (visitors) - via shared function
 * 5. Email Queuing (emails) - for background matching
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// --- DEPENDENCIES ---
// We use the shared function you just updated for consistent Visitor logic
require_once '/opt/auto-pixel/visitor_upsert_functions_v2.php';

// --- CONFIGURATION ---
$DB_HOST = getenv('DB_HOST') ?: '34.26.61.148';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: 'AccuPoint01!';
$CENTRAL_DB = 'pixel';
$LOG_FILE = '/var/www/hook.thynkdata.com/pixel_v2_debug.log';

// --- HELPERS ---
function v2Log($msg) {
    global $LOG_FILE;
    // Simple rotation check (if > 10MB, clear it)
    if (file_exists($LOG_FILE) && filesize($LOG_FILE) > 10485760) {
        file_put_contents($LOG_FILE, ""); 
    }
    file_put_contents($LOG_FILE, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function generateULID() {
    $t = microtime(true);
    $micro = sprintf("%06d", ($t - floor($t)) * 1000000);
    $now = new DateTime(date('Y-m-d H:i:s.' . $micro, $t));
    return $now->format('YmdHisu') . bin2hex(random_bytes(4));
}

// --- MAIN EXECUTION ---
try {
    // 1. Request Validation
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'ok', 'message' => 'V2 Endpoint Ready']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!$payload || !isset($payload['events'])) {
        throw new Exception("Invalid JSON payload");
    }

    $payloadHash = hash('sha256', $rawInput);
    $pairUlid = generateULID(); // Unique Batch ID
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // 2. Connect to Central Control
    $mysqliCentral = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $CENTRAL_DB);
    if ($mysqliCentral->connect_error) throw new Exception("Central DB Error");

    // 3. Validate Pixel & Client
    $firstEvent = $payload['events'][0] ?? [];
    $pixelId = $firstEvent['pixel_id'] ?? $_GET['pixel_id'] ?? null;
    
    if (!$pixelId) throw new Exception("Missing Pixel ID");

    $stmt = $mysqliCentral->prepare("SELECT client_name, client_slug, status, paused FROM pixel_sheets WHERE pixel_id = ? LIMIT 1");
    $stmt->bind_param("s", $pixelId);
    $stmt->execute();
    $res = $stmt->get_result();
    $clientConfig = $res->fetch_assoc();
    $stmt->close();

    if (!$clientConfig) throw new Exception("Invalid Pixel ID");
    if ($clientConfig['paused'] == 1 || $clientConfig['status'] !== 'active') {
        echo json_encode(['status' => 'ignored', 'reason' => 'paused']);
        exit;
    }

    // 4. Central Audit Log (Fail-Safe)
    $stmt = $mysqliCentral->prepare("INSERT IGNORE INTO master_raw_events (client_name, uuid, pixel_id, event_type, payload, payload_sha256, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $uuidRaw = $firstEvent['resolution']['UUID'] ?? null;
    $typeRaw = $firstEvent['event_type'] ?? 'batch';
    $stmt->bind_param("ssssssss", $clientConfig['client_name'], $uuidRaw, $pixelId, $typeRaw, $rawInput, $payloadHash, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();

    // 5. Connect to Client Database
    $mysqliClient = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $clientConfig['client_slug']);
    if ($mysqliClient->connect_error) throw new Exception("Client DB Connect Error");

    // 6. Client Audit Log (Redundancy)
    $stmt = $mysqliClient->prepare("INSERT IGNORE INTO raw_events (uuid, pixel_id, event_type, payload, payload_sha256, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $uuidRaw, $pixelId, $typeRaw, $rawInput, $payloadHash, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();

    // 7. PROCESS EVENTS LOOP
    foreach ($payload['events'] as $event) {
        
        // Normalize Resolution & Event Data
        $res = $event['resolution'] ?? [];
        if (is_string($res)) $res = json_decode($res, true) ?? [];
        
        $ed = $event['event_data'] ?? [];
        if (is_string($ed)) $ed = json_decode($ed, true) ?? [];

        $uuid = $res['UUID'] ?? null;
        if (!$uuid) continue; // Skip events without identity

        $ts = $event['event_timestamp'] ?? date('Y-m-d H:i:s.u');
        $eventType = $event['event_type'] ?? 'unknown';

	// Use payload pixel_id if available, otherwise central pixel_id
        $evtPixelId = $event['pixel_id'] ?? $pixelId; 
	
	// --- PREPARE DIMENSIONS ---
        // Extract integers
        $scrW = $ed['screen']['width'] ?? $ed['screen_width'] ?? null;
        $scrH = $ed['screen']['height'] ?? $ed['screen_height'] ?? null;
        $vpW  = $ed['viewport']['width'] ?? $ed['viewport_width'] ?? null;
        $vpH  = $ed['viewport']['height'] ?? $ed['viewport_height'] ?? null;

        // Create Strings (e.g., "1920x1080")
        $screenRes = ($scrW && $scrH) ? "{$scrW}x{$scrH}" : null;
        $viewportSize = ($vpW && $vpH) ? "{$vpW}x{$vpH}" : null;
        
        // --- PREPARE ATTRIBUTION (ADDED THIS BLOCK) ---
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $utmSource = $ed['utm_source'] ?? $ed['source'] ?? null;
        $utmMedium = $ed['utm_medium'] ?? $ed['medium'] ?? null;
        $utmCampaign = $ed['utm_campaign'] ?? $ed['campaign'] ?? null;
        $utmContent = $ed['utm_content'] ?? $ed['content'] ?? null;
        $utmTerm = $ed['utm_term'] ?? $ed['term'] ?? null;

        // Extract LinkedIn from resolution
        $linkedin = $res['LINKEDIN_URL'] ?? $res['linkedin_url'] ?? null;

        // A. INSERT IMMUTABLE EVENT
        // We include the new Verified Email fields in the event log
        $insEvent = "INSERT INTO events (
            uuid, pair_ulid, pixel_id, event_type, event_timestamp, ip_address,
            url, title, referrer,
            first_name, last_name,
            personal_emails, personal_verified_emails,
            business_email, business_verified_emails,
            deep_verified_emails, sha256_personal_email, sha256_business_email,
            mobile_phone, direct_number, personal_phone,
            personal_address, personal_city, personal_state, personal_zip,
            company_name, job_title, linkedin_url
            
            -- Dimensions
            screen_width, screen_height, screen_resolution,
            viewport_width, viewport_height, viewport_size,

	    -- Attribution
            user_agent, utm_source, utm_medium, utm_campaign, utm_content, utm_term,
            
            event_data_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; 

        $stmt = $mysqliClient->prepare($insEvent);
        
        $url = $ed['url'] ?? $res['URL'] ?? '';
        $title = $ed['title'] ?? '';
        $referrer = $ed['referrer'] ?? '';
        $jsonStr = json_encode($event);

        // Note: We added 6 types (iiiiii) for the dimensions logic, but here we pass strings for the resolution
        // So types: sssssssssssssssssssssssssss + iiiiss + s
        // Actually, simpler to just use 's' for everything in MySQLi, it handles casting.

        $stmt->bind_param("sssssssssssssssssssssssssssssiisiisssssss", 
            $uuid, $pairUlid, $evtPixelId, $eventType, $ts, $ipAddress,
            $url, $title, $referrer,
            $res['FIRST_NAME'], $res['LAST_NAME'],
            $res['PERSONAL_EMAILS'], $res['PERSONAL_VERIFIED_EMAILS'],
            $res['BUSINESS_EMAIL'], $res['BUSINESS_VERIFIED_EMAILS'],
            $res['DEEP_VERIFIED_EMAILS'], $res['SHA256_PERSONAL_EMAIL'], $res['SHA256_BUSINESS_EMAIL'],
            $res['MOBILE_PHONE'], $res['DIRECT_NUMBER'], $res['PERSONAL_PHONE'],
            $res['PERSONAL_ADDRESS'], $res['PERSONAL_CITY'], $res['PERSONAL_STATE'], $res['PERSONAL_ZIP'],
            $res['COMPANY_NAME'], $res['JOB_TITLE'], $linkedin,
            
            // Dimensions
            $scrW, $scrH, $screenRes,
            $vpW, $vpH, $viewportSize,

	    // Attribution
            $ua, $utmSource, $utmMedium, $utmCampaign, $utmContent, $utmTerm,
            
            $jsonStr
        );

        
        $stmt->execute();
        $stmt->close();

        // B. UPSERT VISITOR (Golden Record)
        // We merge $res, $ed, and our context. 
        // The updated shared function will perform the gap-fill logic.
        $visitorPayload = array_merge(
            $res, 
            $ed, 
            [
                'uuid' => $uuid,
                'pair_ulid' => $pairUlid,
                'pixel_id' => $pixelId,
                'ip_address' => $ipAddress,
                'event_timestamp' => $ts,
                'event_type' => $eventType,
                'user_agent' => $userAgent,
                // Calculate 'Best' Email for the summary column
                'email_best' => $res['BUSINESS_EMAIL'] ?: (
                    !empty($res['PERSONAL_EMAILS']) ? explode(',', $res['PERSONAL_EMAILS'])[0] : null
                )
            ]
        );
        
        // Call shared logic
        upsertVisitorV2($mysqliClient, $visitorPayload);

        // C. QUEUE EMAILS (For Background Matching)
        // We extract from all relevant fields, assign types, and insert into `emails` table.
        // The match_worker.php will scan this table or the visitors table.
        
        $emailsToQueue = [];

        // Helper to push emails with type
        $addEmail = function($str, $type) use (&$emailsToQueue) {
            if (!empty($str)) {
                // Handle commas or arrays if present
                $list = is_array($str) ? $str : explode(',', $str);
                foreach ($list as $e) {
                    $clean = trim($e);
                    if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
                        $emailsToQueue[$clean] = $type; // Key by email to dedupe, Value is type
                    }
                }
            }
        };

        $addEmail($res['BUSINESS_EMAIL'] ?? null, 'business');
        $addEmail($res['PERSONAL_EMAILS'] ?? null, 'personal');
        $addEmail($res['BUSINESS_VERIFIED_EMAILS'] ?? null, 'business_verified');
        $addEmail($res['PERSONAL_VERIFIED_EMAILS'] ?? null, 'personal_verified');
        $addEmail($res['DEEP_VERIFIED_EMAILS'] ?? null, 'deep_verified');

        if (!empty($emailsToQueue)) {
            $emailSql = "INSERT IGNORE INTO emails (uuid, email, email_type, source_table) VALUES (?, ?, ?, 'events')";
            $stmt = $mysqliClient->prepare($emailSql);
            
            foreach ($emailsToQueue as $email => $type) {
                $stmt->bind_param("sss", $uuid, $email, $type);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    // 8. Update Daily Stats (Central)
    $evtCount = count($payload['events']);
    $date = date('Y-m-d');
    $statsSql = "INSERT INTO pixel_daily_stats (pixel_id, client_name, day_local, events_count, visitors_count) 
                 VALUES (?, ?, ?, ?, 1) 
                 ON DUPLICATE KEY UPDATE 
                 events_count = events_count + VALUES(events_count), 
                 visitors_count = visitors_count + 1, 
                 last_aggregated_at = NOW()";
    
    $stmt = $mysqliCentral->prepare($statsSql);
    $stmt->bind_param("sssi", $pixelId, $clientConfig['client_name'], $date, $evtCount);
    $stmt->execute();
    $stmt->close();

    // Cleanup
    $mysqliCentral->close();
    $mysqliClient->close();

    // Success Response
    echo json_encode(['status' => 'success', 'ulid' => $pairUlid]);

} catch (Exception $e) {
    v2Log("Critical Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error']);
}
?>
