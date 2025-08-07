<?php
// Fixed pixel import that properly maps AudienceLab's new nested structure
// Maps resolution.UUID -> uuid, resolution.FIRST_NAME -> first_name, etc.

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Include email processing if available
if (file_exists(__DIR__ . '/process_visitor_emails.php')) {
    require_once __DIR__ . '/process_visitor_emails.php';
}

function debugLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    error_log($logMessage, 3, __DIR__ . '/pixel_import_debug.log');
}

// Log every inbound request so Test clicks are visible regardless of method
debugLog(
    'Inbound request: method=' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') .
    ', client=' . (isset($_GET['client']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['client']) : 'none') .
    ', uri=' . ($_SERVER['REQUEST_URI'] ?? '') .
    ', content-type=' . ($_SERVER['CONTENT_TYPE'] ?? '') .
    ', content-length=' . ($_SERVER['CONTENT_LENGTH'] ?? '') .
    ', ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') .
    ', ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
);

// Map AudienceLab's new UPPERCASE fields to our lowercase columns
$FIELD_MAPPING = [
    // Resolution fields (nested visitor data)
    'UUID' => 'uuid',
    'FIRST_NAME' => 'first_name',
    'LAST_NAME' => 'last_name',
    'BUSINESS_EMAIL' => 'business_email',
    'PERSONAL_EMAILS' => 'personal_emails',
    'PERSONAL_ADDRESS' => 'personal_address',
    'PERSONAL_CITY' => 'personal_city',
    'PERSONAL_STATE' => 'personal_state',
    'PERSONAL_ZIP' => 'personal_zip',
    'PERSONAL_ZIP4' => 'personal_zip4',
    'AGE_RANGE' => 'age_range',
    'CHILDREN' => 'children',
    'GENDER' => 'gender',
    'HOMEOWNER' => 'homeowner',
    'MARRIED' => 'married',
    'NET_WORTH' => 'net_worth',
    'INCOME_RANGE' => 'income_range',
    'DIRECT_NUMBER' => 'direct_number',
    'DIRECT_NUMBER_DNC' => 'direct_number_dnc',
    'MOBILE_PHONE' => 'mobile_phone',
    'MOBILE_PHONE_DNC' => 'mobile_phone_dnc',
    'PERSONAL_PHONE' => 'personal_phone',
    'PERSONAL_PHONE_DNC' => 'personal_phone_dnc',
    'DEEP_VERIFIED_EMAILS' => 'deep_verified_emails',
    'SHA256_PERSONAL_EMAIL' => 'sha256_personal_email',
    'SHA256_BUSINESS_EMAIL' => 'sha256_business_email',
    'JOB_TITLE' => 'job_title',
    'HEADLINE' => 'headline',
    'DEPARTMENT' => 'department',
    'SENIORITY_LEVEL' => 'seniority_level',
    'INFERRED_YEARS_EXPERIENCE' => 'inferred_years_experience',
    'COMPANY_NAME_HISTORY' => 'company_name_history',
    'JOB_TITLE_HISTORY' => 'job_title_history',
    'EDUCATION_HISTORY' => 'education_history',
    'COMPANY_ADDRESS' => 'company_address',
    'COMPANY_DESCRIPTION' => 'company_description',
    'COMPANY_DOMAIN' => 'company_domain',
    'COMPANY_EMPLOYEE_COUNT' => 'company_employee_count',
    'COMPANY_LINKEDIN_URL' => 'company_linkedin_url',
    'COMPANY_NAME' => 'company_name',
    'COMPANY_PHONE' => 'company_phone',
    'COMPANY_REVENUE' => 'company_revenue',
    'COMPANY_SIC' => 'company_sic',
    'COMPANY_NAICS' => 'company_naics',
    'COMPANY_CITY' => 'company_city',
    'COMPANY_STATE' => 'company_state',
    'COMPANY_ZIP' => 'company_zip',
    'COMPANY_INDUSTRY' => 'company_industry',
    'LINKEDIN_URL' => 'linkedin_url',
    'TWITTER_URL' => 'twitter_url',
    'FACEBOOK_URL' => 'facebook_url',
    'SOCIAL_CONNECTIONS' => 'social_connections',
    'SKILLS' => 'skills',
    'INTERESTS' => 'interests',
    'SKIPTRACE_MATCH_SCORE' => 'skiptrace_match_score',
    'SKIPTRACE_NAME' => 'skiptrace_name',
    'SKIPTRACE_ADDRESS' => 'skiptrace_address',
    'SKIPTRACE_CITY' => 'skiptrace_city',
    'SKIPTRACE_STATE' => 'skiptrace_state',
    'SKIPTRACE_ZIP' => 'skiptrace_zip',
    'SKIPTRACE_LANDLINE_NUMBERS' => 'skiptrace_landline_numbers',
    'SKIPTRACE_WIRELESS_NUMBERS' => 'skiptrace_wireless_numbers',
    'SKIPTRACE_CREDIT_RATING' => 'skiptrace_credit_rating',
    'SKIPTRACE_DNC' => 'skiptrace_dnc',
    'SKIPTRACE_EXACT_AGE' => 'skiptrace_exact_age',
    'SKIPTRACE_ETHNIC_CODE' => 'skiptrace_ethnic_code',
    'SKIPTRACE_LANGUAGE_CODE' => 'skiptrace_language_code',
    'SKIPTRACE_IP' => 'skiptrace_ip',
    'SKIPTRACE_B2B_ADDRESS' => 'skiptrace_b2b_address',
    'SKIPTRACE_B2B_PHONE' => 'skiptrace_b2b_phone',
    'SKIPTRACE_B2B_SOURCE' => 'skiptrace_b2b_source',
    'SKIPTRACE_B2B_WEBSITE' => 'skiptrace_b2b_website',
];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    debugLog('Raw input received (first 500 chars): ' . substr($rawInput, 0, 500));

    // Persist last body for troubleshooting
    file_put_contents(__DIR__ . '/last_webhook_raw.json', $rawInput);

    $requestData = json_decode($rawInput, true);
    if (!is_array($requestData)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON received']);
        exit;
    }

    $events = $requestData['events'] ?? [];
    $eventsCount = is_array($events) ? count($events) : 0;
    debugLog('Processing ' . $eventsCount . ' events');

    if ($eventsCount === 0) {
        echo json_encode(['status' => 'success', 'message' => 'No events to process']);
        exit;
    }

    $client = isset($_GET['client']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['client']) : '';
    if ($client === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Client parameter is required']);
        exit;
    }

    $host = '34.31.66.104';
    $user = 'root';
    $pass = 'AccuPoint01!';

    $mysqli = new mysqli($host, $user, $pass, $client);
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }

    $processedEvents = 0;

    foreach ($events as $eventIndex => $event) {
        debugLog('Processing event ' . $eventIndex);

        // Initialize insert data with defaults
        $insert_data = [
            'created_at' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];

        // Process top-level fields
        $topLevelFields = [
            'pixel_id', 'hem_sha256', 'event_timestamp', 'event_type',
            'ip_address', 'activity_start_date', 'activity_end_date',
            'referrer_url'
        ];
        foreach ($topLevelFields as $field) {
            if (isset($event[$field])) {
                $insert_data[$field] = $event[$field];
            }
        }

        // Process event_data nested object
        if (isset($event['event_data']) && is_array($event['event_data'])) {
            $eventData = $event['event_data'];
            if (isset($eventData['referrer'])) { $insert_data['referrer'] = $eventData['referrer']; }
            if (isset($eventData['timestamp'])) { $insert_data['timestamp'] = $eventData['timestamp']; }
            if (isset($eventData['title'])) { $insert_data['title'] = $eventData['title']; }
            if (isset($eventData['url'])) { $insert_data['url'] = $eventData['url']; }
            if (isset($eventData['percentage'])) { $insert_data['percentage'] = $eventData['percentage']; }
            if (isset($eventData['element'])) {
                $insert_data['element'] = is_array($eventData['element'])
                    ? json_encode($eventData['element'])
                    : $eventData['element'];
            }
        }

        // Process resolution nested object (visitor data)
        if (isset($event['resolution']) && is_array($event['resolution'])) {
            $resolution = $event['resolution'];
            foreach ($resolution as $key => $value) {
                if (isset($FIELD_MAPPING[$key])) {
                    $mappedField = $FIELD_MAPPING[$key];
                    $insert_data[$mappedField] = $value;
                } else {
                    debugLog('Unmapped resolution field: ' . $key);
                }
            }
        }

        // Build SQL
        $columns = [];
        $values = [];
        foreach ($insert_data as $key => $value) {
            $columns[] = '`' . $mysqli->real_escape_string($key) . '`';
            $values[] = "'" . $mysqli->real_escape_string($value ?? '') . "'";
        }

        // Insert into database
        $sql = 'INSERT INTO superpixel_resolution_log (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')';
        if (!$mysqli->query($sql)) {
            $error = 'Insert failed for event ' . $eventIndex . ': ' . $mysqli->error;
            debugLog($error);
            throw new Exception($error);
        }

        debugLog('✅ Successfully inserted event ' . $eventIndex);

        // Process emails and NPN/CRD if UUID exists
        if (!empty($insert_data['uuid']) && function_exists('processVisitorEmails')) {
            $uuid = $insert_data['uuid'];
            debugLog('Processing emails for UUID: ' . $uuid);
            try {
                $emailResults = processVisitorEmails($client, $uuid, true, false);
                if (!empty($emailResults['npn_found']) || !empty($emailResults['crd_found'])) {
                    debugLog('✅ NPN/CRD found for ' . $uuid . ' - NPN: ' . ($emailResults['npn'] ?? 'null') . ', CRD: ' . ($emailResults['crd'] ?? 'null'));
                }
            } catch (Exception $e) {
                debugLog('⚠️ Email processing failed: ' . $e->getMessage());
            }
        }

        $processedEvents++;
    }

    debugLog('Successfully processed all ' . $eventsCount . ' events');
    echo json_encode(['status' => 'success', 'processed' => $processedEvents]);

} catch (Throwable $e) {
    debugLog('❌ Fatal error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'message' => $e->getMessage()]);
}
