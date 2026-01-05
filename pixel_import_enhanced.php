<?php
// Enhanced pixel_import.php with automated email parsing and NPN/CRD lookup
// PRIORITY 2: Complete automation of visitor processing pipeline

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the email processing function
require_once __DIR__ . '/process_visitor_emails.php';

function debugLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    echo $logMessage;
    error_log($logMessage, 3, __DIR__ . '/pixel_import_debug.log');
}

function send_email($data, $subject) {
    // Email notification function (existing implementation)
    debugLog("Email notification: $subject");
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        debugLog("Raw input received: " . substr($rawInput, 0, 500) . "...");
        
        $requestData = json_decode($rawInput, true);
        if (!$requestData) {
            throw new Exception('Invalid JSON received');
        }

        $events = $requestData['events'] ?? [];
        $eventsCount = count($events);
        debugLog("Processing $eventsCount events");

        if ($eventsCount === 0) {
            echo json_encode(['status' => 'success', 'message' => 'No events to process']);
            exit;
        }

        // Get client from URL parameter
        $client = $_GET['client'] ?? '';
        if (empty($client)) {
            throw new Exception('Client parameter is required');
        }

        debugLog("Processing events for client: $client");

        // Database connection
        $host = '34.26.61.148';
        $user = 'root';
        $pass = 'AccuPoint01!';
        
        $mysqli = new mysqli($host, $user, $pass, $client);
        if ($mysqli->connect_error) {
            throw new Exception("Database connection failed: " . $mysqli->connect_error);
        }

        $processedUUIDs = []; // Track UUIDs for email processing
        
        foreach ($events as $eventIndex => $event) {
            debugLog("Processing event $eventIndex");
            
            // Prepare insert data
            $insert_data = [
                'created_at' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ];

            // Map event fields to database columns
            $field_mapping = [
                'uuid' => 'uuid',
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'event_timestamp' => 'event_timestamp',
                'event_type' => 'event_type',
                'hem_sha256' => 'hem_sha256',
                'pixel_id' => 'pixel_id',
                'activity_start_date' => 'activity_start_date',
                'activity_end_date' => 'activity_end_date',
                'referrer_url' => 'referrer_url',
                'personal_address' => 'personal_address',
                'personal_city' => 'personal_city',
                'personal_state' => 'personal_state',
                'personal_zip' => 'personal_zip',
                'personal_zip4' => 'personal_zip4',
                'age_range' => 'age_range',
                'children' => 'children',
                'gender' => 'gender',
                'homeowner' => 'homeowner',
                'married' => 'married',
                'net_worth' => 'net_worth',
                'income_range' => 'income_range',
                'direct_number' => 'direct_number',
                'direct_number_dnc' => 'direct_number_dnc',
                'mobile_phone' => 'mobile_phone',
                'mobile_phone_dnc' => 'mobile_phone_dnc',
                'personal_phone' => 'personal_phone',
                'personal_phone_dnc' => 'personal_phone_dnc',
                'business_email' => 'business_email',
                'personal_emails' => 'personal_emails',
                'deep_verified_emails' => 'deep_verified_emails',
                'sha256_personal_email' => 'sha256_personal_email',
                'sha256_business_email' => 'sha256_business_email',
                'job_title' => 'job_title',
                'headline' => 'headline',
                'department' => 'department',
                'seniority_level' => 'seniority_level',
                'inferred_years_experience' => 'inferred_years_experience',
                'company_name_history' => 'company_name_history',
                'job_title_history' => 'job_title_history',
                'education_history' => 'education_history',
                'company_address' => 'company_address',
                'company_description' => 'company_description',
                'company_domain' => 'company_domain',
                'company_employee_count' => 'company_employee_count',
                'company_linkedin_url' => 'company_linkedin_url',
                'company_name' => 'company_name',
                'company_phone' => 'company_phone',
                'company_revenue' => 'company_revenue',
                'company_sic' => 'company_sic',
                'company_naics' => 'company_naics',
                'company_city' => 'company_city',
                'company_state' => 'company_state',
                'company_zip' => 'company_zip',
                'company_industry' => 'company_industry',
                'linkedin_url' => 'linkedin_url',
                'twitter_url' => 'twitter_url',
                'facebook_url' => 'facebook_url',
                'social_connections' => 'social_connections',
                'skills' => 'skills',
                'interests' => 'interests',
                'skiptrace_match_score' => 'skiptrace_match_score',
                'skiptrace_name' => 'skiptrace_name',
                'skiptrace_address' => 'skiptrace_address',
                'skiptrace_city' => 'skiptrace_city',
                'skiptrace_state' => 'skiptrace_state',
                'skiptrace_zip' => 'skiptrace_zip',
                'skiptrace_landline_numbers' => 'skiptrace_landline_numbers',
                'skiptrace_wireless_numbers' => 'skiptrace_wireless_numbers',
                'skiptrace_credit_rating' => 'skiptrace_credit_rating',
                'skiptrace_dnc' => 'skiptrace_dnc',
                'skiptrace_exact_age' => 'skiptrace_exact_age',
                'skiptrace_ethnic_code' => 'skiptrace_ethnic_code',
                'skiptrace_language_code' => 'skiptrace_language_code',
                'skiptrace_ip' => 'skiptrace_ip',
                'skiptrace_b2b_address' => 'skiptrace_b2b_address',
                'skiptrace_b2b_phone' => 'skiptrace_b2b_phone',
                'skiptrace_b2b_source' => 'skiptrace_b2b_source',
                'skiptrace_b2b_website' => 'skiptrace_b2b_website',
                'element' => 'element',
                'percentage' => 'percentage',
                'referrer' => 'referrer',
                'timestamp' => 'timestamp',
                'title' => 'title',
                'url' => 'url',
                'valid_phones' => 'valid_phones'
            ];

            // Process each field
            foreach ($field_mapping as $event_key => $db_column) {
                if (isset($event[$event_key])) {
                    $insert_data[$db_column] = $event[$event_key];
                }
            }

            // Build SQL
            $columns = [];
            $values = [];

            foreach ($insert_data as $key => $value) {
                $columns[] = "`" . $mysqli->real_escape_string($key) . "`";
                $values[] = "'" . $mysqli->real_escape_string($value) . "'";
            }
            
            // Step 1: Insert raw event into superpixel_resolution_log
            $sql = "INSERT INTO superpixel_resolution_log (" . implode(",", $columns) . ") VALUES (" . implode(",", $values) . ")";
            debugLog("Executing event SQL for event $eventIndex");

            if (!$mysqli->query($sql)) {
                $error = "Event insert failed for event $eventIndex: " . $mysqli->error;
                debugLog($error);
                throw new Exception($error);
            }
            
            debugLog("Successfully inserted event $eventIndex to superpixel_resolution_log");
            
            // Step 2: Visitor creation/update is handled automatically by database trigger
            // The trigger 'after_resolution_log_insert_visitor_update' will:
            // - Create new visitors for new UUIDs
            // - Update existing visitors with new event data
            // - Preserve business emails over personal emails
            // - Track event counts and last seen timestamps
            
            // Step 3: PRIORITY 2 - Automated Email Processing & NPN/CRD Lookup
            if (!empty($insert_data['uuid'])) {
                $uuid = $insert_data['uuid'];
                debugLog("Starting automated email processing for UUID: $uuid");
                
                try {
                    // Process emails and perform NPN/CRD lookup
                    $email_results = processVisitorEmails($client, $uuid, true, false);
                    
                    debugLog("Email processing results for $uuid: " . json_encode([
                        'emails_found' => $email_results['emails_found'],
                        'emails_parsed' => $email_results['emails_parsed'],
                        'npn_found' => $email_results['npn_found'],
                        'crd_found' => $email_results['crd_found'],
                        'npn' => $email_results['npn'],
                        'crd' => $email_results['crd']
                    ]));
                    
                    if ($email_results['npn_found'] || $email_results['crd_found']) {
                        debugLog("✅ NPN/CRD lookup successful for $uuid - NPN: " . 
                                ($email_results['npn'] ?? 'null') . ", CRD: " . 
                                ($email_results['crd'] ?? 'null'));
                    } else {
                        debugLog("⚠️ No NPN/CRD match found for $uuid");
                    }
                    
                } catch (Exception $e) {
                    debugLog("❌ Email processing failed for $uuid: " . $e->getMessage());
                    // Don't fail the entire webhook on email processing errors
                }
            } else {
                debugLog("⚠️ No UUID provided for event $eventIndex - skipping email processing");
            }
        }

        debugLog("Successfully processed all $eventsCount events");
        echo json_encode(['status' => 'success', 'processed' => $eventsCount]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    debugLog("❌ Fatal error: " . $e->getMessage());
    send_email(array('Database Connection Error' => $e->getMessage()), "Fatal Error in Enhanced Hook Handler");
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
?> 