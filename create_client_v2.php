<?php
/**
 * create_client_v2.php
 * Location: /opt/auto-pixel/create_client_v2.php
 * 
 * Role: All-in-One Provisioner (Schema Matched to V2 Definition)
 * 1. Creates Client Database
 * 2. Creates V2 Tables (Exact Schema Match)
 * 3. Creates Google Sheet
 * 4. Registers in Central DB
 */

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Drive;
use Google\Service\Sheets\ValueRange;

// --- CONFIG ---
$DB_HOST = '34.26.61.148';
$DB_USER = 'root';
$DB_PASS = 'AccuPoint01!';
$CENTRAL_DB = 'pixel_v2';
$CREDS_FILE = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

if ($argc < 3) {
    die("Usage: php create_client_v2.php <ClientName> <PixelID>\n");
}

$clientName = $argv[1];
$pixelId = $argv[2];
$clientSlug = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $clientName));

echo "🚀 Provisioning V2 Client: $clientName ($clientSlug)...\n";

// --- 1. DATABASE & TABLES ---
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
if ($mysqli->connect_error) die("DB Connect Error: " . $mysqli->connect_error . "\n");

echo "1. Creating Database...\n";
$mysqli->query("CREATE DATABASE IF NOT EXISTS `$clientSlug`");
$mysqli->select_db($clientSlug);

$tables = [
    // EVENTS (Matched to Schema)
    "CREATE TABLE IF NOT EXISTS `events` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `uuid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `pair_ulid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `pixel_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `event_timestamp` datetime(3) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `hem_sha256` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `title` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `referrer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `referrer_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `utm_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `utm_medium` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `utm_campaign` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `utm_content` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `time_on_page` int DEFAULT NULL,
      `idle_time` int DEFAULT NULL,
      `percentage` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `threshold` int DEFAULT NULL,
      `scroll_percentage` int DEFAULT NULL,
      `element_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `element_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `element_selector` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `link_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `click_x` int DEFAULT NULL,
      `click_y` int DEFAULT NULL,
      `video_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `video_src` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `video_current_time` float DEFAULT NULL,
      `video_duration` float DEFAULT NULL,
      `video_percent` int DEFAULT NULL,
      `form_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `form_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `form_action` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `form_method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `form_data` json DEFAULT NULL,
      `file_name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `file_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `first_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_emails` text COLLATE utf8mb4_unicode_ci,
      `personal_verified_emails` text COLLATE utf8mb4_unicode_ci,
      `business_email` text COLLATE utf8mb4_unicode_ci,
      `business_verified_emails` text COLLATE utf8mb4_unicode_ci,
      `deep_verified_emails` text COLLATE utf8mb4_unicode_ci,
      `sha256_personal_email` longtext COLLATE utf8mb4_unicode_ci,
      `sha256_business_email` longtext COLLATE utf8mb4_unicode_ci,
      `direct_number` text COLLATE utf8mb4_unicode_ci,
      `direct_number_dnc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `mobile_phone` text COLLATE utf8mb4_unicode_ci,
      `mobile_phone_dnc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_phone` text COLLATE utf8mb4_unicode_ci,
      `personal_phone_dnc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `valid_phones` text COLLATE utf8mb4_unicode_ci,
      `personal_address` text COLLATE utf8mb4_unicode_ci,
      `personal_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_state` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_zip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_zip4` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `age_range` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `net_worth` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `income_range` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `homeowner` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `married` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `children` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `job_title` text COLLATE utf8mb4_unicode_ci,
      `headline` text COLLATE utf8mb4_unicode_ci,
      `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `seniority_level` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `inferred_years_experience` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `education_history` text COLLATE utf8mb4_unicode_ci,
      `company_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_domain` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_address` text COLLATE utf8mb4_unicode_ci,
      `company_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_state` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_zip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_industry` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_employee_count` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_revenue` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_linkedin_url` text COLLATE utf8mb4_unicode_ci,
      `company_name_history` text COLLATE utf8mb4_unicode_ci,
      `job_title_history` text COLLATE utf8mb4_unicode_ci,
      `company_sic` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_naics` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_description` text COLLATE utf8mb4_unicode_ci,
      `linkedin_url` text COLLATE utf8mb4_unicode_ci,
      `twitter_url` text COLLATE utf8mb4_unicode_ci,
      `facebook_url` text COLLATE utf8mb4_unicode_ci,
      `social_connections` text COLLATE utf8mb4_unicode_ci,
      `skills` text COLLATE utf8mb4_unicode_ci,
      `interests` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_match_score` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_address` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_state` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_zip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_landline_numbers` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_wireless_numbers` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_credit_rating` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_dnc` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_exact_age` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_ethnic_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_language_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_b2b_address` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_b2b_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_b2b_source` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_b2b_website` text COLLATE utf8mb4_unicode_ci,
      `npn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `crd` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `npn_active` tinyint(1) DEFAULT NULL,
      `crd_active` tinyint(1) DEFAULT NULL,
      `APS_confidence_score` tinyint unsigned DEFAULT NULL,
      `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `activity_start_date` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `activity_end_date` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `timestamp` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `element` text COLLATE utf8mb4_unicode_ci,
      `event_data_json` json DEFAULT NULL,
      `screen_width` int DEFAULT NULL,
      `screen_height` int DEFAULT NULL,
      `screen_resolution` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `viewport_width` int DEFAULT NULL,
      `viewport_height` int DEFAULT NULL,
      `viewport_size` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      PRIMARY KEY (`id`),
      KEY `idx_uuid` (`uuid`),
      KEY `idx_event_time` (`pixel_id`,`event_timestamp`),
      KEY `idx_pair_ulid` (`pair_ulid`),
      KEY `idx_event_type` (`event_type`),
      KEY `idx_email_hash` (`sha256_personal_email`(64))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // VISITORS (Matched to Schema)
    "CREATE TABLE IF NOT EXISTS `visitors` (
      `uuid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `first_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `email_best` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_emails` text COLLATE utf8mb4_unicode_ci,
      `personal_verified_emails` text COLLATE utf8mb4_unicode_ci,
      `business_email` text COLLATE utf8mb4_unicode_ci,
      `business_verified_emails` text COLLATE utf8mb4_unicode_ci,
      `deep_verified_emails` text COLLATE utf8mb4_unicode_ci,
      `sha256_personal_email` longtext COLLATE utf8mb4_unicode_ci,
      `sha256_business_email` longtext COLLATE utf8mb4_unicode_ci,
      `hem_sha256` text COLLATE utf8mb4_unicode_ci,
      `direct_number` text COLLATE utf8mb4_unicode_ci,
      `direct_number_dnc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `mobile_phone` text COLLATE utf8mb4_unicode_ci,
      `mobile_phone_dnc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_phone` text COLLATE utf8mb4_unicode_ci,
      `personal_phone_dnc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `valid_phones` text COLLATE utf8mb4_unicode_ci,
      `personal_address` text COLLATE utf8mb4_unicode_ci,
      `personal_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_state` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_zip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `personal_zip4` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `age_range` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `married` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `children` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `homeowner` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `net_worth` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `income_range` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `job_title` text COLLATE utf8mb4_unicode_ci,
      `headline` text COLLATE utf8mb4_unicode_ci,
      `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `seniority_level` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `inferred_years_experience` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_domain` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_address` text COLLATE utf8mb4_unicode_ci,
      `company_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_state` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_zip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_industry` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_employee_count` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_revenue` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_sic` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_naics` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `company_description` text COLLATE utf8mb4_unicode_ci,
      `company_linkedin_url` text COLLATE utf8mb4_unicode_ci,
      `company_name_history` text COLLATE utf8mb4_unicode_ci,
      `job_title_history` text COLLATE utf8mb4_unicode_ci,
      `education_history` text COLLATE utf8mb4_unicode_ci,
      `linkedin_url` text COLLATE utf8mb4_unicode_ci,
      `twitter_url` text COLLATE utf8mb4_unicode_ci,
      `facebook_url` text COLLATE utf8mb4_unicode_ci,
      `social_connections` text COLLATE utf8mb4_unicode_ci,
      `skills` text COLLATE utf8mb4_unicode_ci,
      `interests` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_match_score` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_address` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_state` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_zip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_landline_numbers` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_wireless_numbers` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_credit_rating` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_dnc` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_exact_age` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_ethnic_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_language_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_b2b_address` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_b2b_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `skiptrace_b2b_source` text COLLATE utf8mb4_unicode_ci,
      `skiptrace_b2b_website` text COLLATE utf8mb4_unicode_ci,
      `first_seen_at` datetime(3) DEFAULT NULL,
      `last_seen_at` datetime(3) DEFAULT NULL,
      `event_count` int unsigned DEFAULT '0',
      `total_sessions` int unsigned DEFAULT '0',
      `total_time_on_site` int unsigned DEFAULT '0',
      `average_scroll_depth` int unsigned DEFAULT '0',
      `last_url` text COLLATE utf8mb4_unicode_ci,
      `last_title` text COLLATE utf8mb4_unicode_ci,
      `last_referrer` text COLLATE utf8mb4_unicode_ci,
      `last_event_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_pixel_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_user_agent` text COLLATE utf8mb4_unicode_ci,
      `first_utm_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `first_utm_medium` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `first_utm_campaign` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_utm_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_utm_medium` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_utm_campaign` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `npn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `crd` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `npn_active` tinyint(1) DEFAULT NULL,
      `crd_active` tinyint(1) DEFAULT NULL,
      `APS_confidence_score` tinyint unsigned DEFAULT NULL,
      `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `pair_ulid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      PRIMARY KEY (`uuid`),
      KEY `idx_email` (`sha256_personal_email`(64)),
      KEY `idx_last_seen` (`last_seen_at`),
      KEY `idx_company` (`company_domain`),
      KEY `idx_compliance` (`npn`,`crd`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // EMAILS (Matched to Schema)
    "CREATE TABLE IF NOT EXISTS `emails` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `uuid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `email_type` enum('personal','business','deep_verified','personal_verified','business_verified') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `source_column` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `source_table` enum('events','visitors') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'events',
      `matched_contact_id` bigint DEFAULT NULL,
      `matched_first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `matched_last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `confidence_score` tinyint unsigned DEFAULT '0',
      `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `npn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `crd` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `npn_active` tinyint(1) DEFAULT '0',
      `crd_active` tinyint(1) DEFAULT '0',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_uuid_email` (`uuid`,`email`),
      KEY `idx_email` (`email`),
      KEY `idx_uuid` (`uuid`),
      KEY `idx_email_type` (`email_type`),
      KEY `idx_contact_link` (`matched_contact_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // MATCHED PROFESSIONALS (Matched to Schema)
    "CREATE TABLE IF NOT EXISTS `matched_professionals` (
      `visitor_uuid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `contact_id` bigint NOT  NULL,
      `contact_uuid` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `crd` int DEFAULT NULL,
      `npn` int DEFAULT NULL,
      `agent_id` int DEFAULT NULL,
      `firm_id` bigint DEFAULT NULL,
      `firm_crd` int DEFAULT NULL,
      `firm_external_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `is_active` tinyint(1) DEFAULT NULL,
      `crd_active` tinyint(1) DEFAULT NULL,
      `npn_active` tinyint(1) DEFAULT NULL,
      `is_professional` tinyint(1) NOT NULL DEFAULT '0',
      `is_bd_exec` tinyint(1) NOT NULL DEFAULT '0',
      `is_ia_exec` tinyint(1) NOT NULL DEFAULT '0',
      `is_agency_exec` tinyint(1) NOT NULL DEFAULT '0',
      `is_rr` tinyint(1) NOT NULL DEFAULT '0',
      `is_ia` tinyint(1) NOT NULL DEFAULT '0',
      `is_agent` tinyint(1) NOT NULL DEFAULT '0',
      `is_bd_member` tinyint(1) NOT NULL DEFAULT '0',
      `is_ria_member` tinyint(1) NOT NULL DEFAULT '0',
      `is_agency_member` tinyint(1) NOT NULL DEFAULT '0',
      `firm_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `is_primary` tinyint(1) DEFAULT NULL,
      `is_secondary` tinyint(1) DEFAULT NULL,
      `is_alternate` tinyint(1) DEFAULT NULL,
      `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `dataset_version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `confidence_score` tinyint DEFAULT NULL,
      `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `sa_data_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `ap_data_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `matched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`visitor_uuid`),
      UNIQUE KEY `idx_contact_link` (`contact_id`),
      KEY `idx_crd` (`crd`),
      KEY `idx_npn` (`npn`),
      KEY `idx_firm` (`firm_id`),
      KEY `idx_roles` (`is_ia`,`is_rr`,`is_agent`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // RAW EVENTS (Matched to Schema)
    "CREATE TABLE IF NOT EXISTS `raw_events` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `uuid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `pixel_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `event_timestamp` datetime(3) DEFAULT NULL,
      `received_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `payload` json NOT NULL,
      `payload_sha256` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_uuid` (`uuid`),
      KEY `idx_timestamp` (`event_timestamp`),
      KEY `idx_hash` (`payload_sha256`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
];

echo "2. Creating V2 Tables...\n";
foreach ($tables as $sql) {
    if (!$mysqli->query($sql)) die("SQL Error: " . $mysqli->error . "\n");
}

// --- 2. GOOGLE SHEET ---
echo "3. Creating Google Sheet...\n";
try {
    $client = new Client();
    $client->setAuthConfig($CREDS_FILE);
    $client->setScopes([Sheets::SPREADSHEETS, Drive::DRIVE]);
    // $client->setSubject('scole@thynkdata.com');

    $service = new Sheets($client);
    $drive = new Drive($client);

    $spreadsheet = new Google\Service\Sheets\Spreadsheet([
        'properties' => ['title' => $clientName . '_V2_Data']
    ]);

    $ss = $service->spreadsheets->create($spreadsheet);
    $sheetId = $ss->spreadsheetId;
    $sheetUrl = $ss->spreadsheetUrl;

    // --- FETCH DYNAMIC DEFAULTS FROM CENTRAL DB ---
    // Switch to Central to read available_columns
    $mysqli->select_db($CENTRAL_DB);
    
    $visCols = [];
    $evtCols = [];
    
    // Order by ID ensures consistent column ordering based on how you inserted them
    $res = $mysqli->query("SELECT sheet_header, table_source FROM available_columns WHERE is_default = 1 ORDER BY id ASC");
    
    while ($row = $res->fetch_assoc()) {
        if ($row['table_source'] === 'visitors') $visCols[] = $row['sheet_header'];
        if ($row['table_source'] === 'events') $evtCols[] = $row['sheet_header'];
        // If you enable matched_professionals default cols later, add logic here
    }
    
    // Safety Fallback (if DB is empty)
    if (empty($visCols)) $visCols = ['UUID', 'First Name', 'Last Name', 'Best Email', 'Event Count'];
    if (empty($evtCols)) $evtCols = ['Timestamp', 'Event Type', 'URL', 'UUID', 'Best Email'];

    // Format for Google Sheets ([[Headers]])
    $v2Visitors = [$visCols];
    $v2Events = [$evtCols];
    
    echo "   - Headers Loaded: " . count($visCols) . " Visitors, " . count($evtCols) . " Events\n";

    // Setup Visitors Tab
    $service->spreadsheets_values->update($sheetId, 'Sheet1!A1', 
        new ValueRange(['values' => $v2Visitors]), ['valueInputOption' => 'RAW']);
    
    $requests = [
        new Google\Service\Sheets\Request([
            'updateSheetProperties' => [
                'properties' => ['sheetId' => 0, 'title' => 'Visitors'],
                'fields' => 'title'
            ]
        ]),
        new Google\Service\Sheets\Request([
            'addSheet' => [
                'properties' => ['title' => 'Events']
            ]
        ])
    ];
    $service->spreadsheets->batchUpdate($sheetId, new Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $requests]));

    // Setup Events Tab
    $service->spreadsheets_values->update($sheetId, 'Events!A1', 
        new ValueRange(['values' => $v2Events]), ['valueInputOption' => 'RAW']);

    // Public Permission
    $drive->permissions->create($sheetId, new Google\Service\Drive\Permission(['type' => 'anyone', 'role' => 'reader']));

    echo "   - Sheet Created: $sheetUrl\n";

} catch (Exception $e) {
    die("Google API Error: " . $e->getMessage() . "\n");
}

// --- 3. REGISTER CENTRAL ---
echo "4. Registering in Central DB (pixel_v2)...\n";
$mysqli->select_db($CENTRAL_DB);

// Define Default Headers
$defaultHeaders = json_encode([
    'visitors' => $v2Visitors[0],
    'events' => $v2Events[0]
]);

$stmt = $mysqli->prepare("INSERT INTO pixel_sheets (
    client_name, client_slug, pixel_id, sheet_id, sheet_url, 
    status, paused, display_timezone, enabled_headers
) VALUES (?, ?, ?, ?, ?, 'active', 0, 'America/New_York', ?) 
ON DUPLICATE KEY UPDATE 
    sheet_id=VALUES(sheet_id), 
    sheet_url=VALUES(sheet_url), 
    client_slug=VALUES(client_slug),
    enabled_headers=VALUES(enabled_headers)");

// Bind Params
$stmt->bind_param("ssssss", $clientName, $clientSlug, $pixelId, $sheetId, $sheetUrl, $defaultHeaders);
$stmt->execute();

echo "✅ Success! Client '$clientName' is ready for V2.\n";
$mysqli->close();
?>
