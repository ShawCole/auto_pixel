<?php
/**
 * backfill_last5_from_raw_events.php
 *
 * Pull the last N (default 5) raw_events for USA_Financial_NEW from pixel.raw_events
 * and insert them into USA_Financial_NEW.superpixel_resolution_log,
 * mapping fields like pixel_import.php.
 */

header('Content-Type: application/json');

// --- Config (same defaults as pixel_import.php) ---
$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';

// Source / target
$sourceDb = 'pixel';                 // where raw_events live
$clientDb = 'USA_Financial_NEW';     // where superpixel_resolution_log lives
$client    = 'USA_Financial_NEW';    // used only for logging/messages

// Limit (CLI arg or GET param; default 5)
$limit = 5;
if (php_sapi_name() === 'cli' && isset($argv[1]) && ctype_digit($argv[1])) {
  $limit = (int)$argv[1];
} elseif (isset($_GET['limit']) && ctype_digit($_GET['limit'])) {
  $limit = (int)$_GET['limit'];
}
if ($limit <= 0) { $limit = 5; }

// --- Connect to MySQL ---
$src = new mysqli($dbHost, $dbUser, $dbPass, $sourceDb);
if ($src->connect_error) {
  http_response_code(500);
  echo json_encode(['error' => 'Source DB connection failed: ' . $src->connect_error]);
  exit;
}
$dst = new mysqli($dbHost, $dbUser, $dbPass, $clientDb);
if ($dst->connect_error) {
  http_response_code(500);
  echo json_encode(['error' => 'Target DB connection failed: ' . $dst->connect_error]);
  exit;
}

// --- Helpers ---
function as_array($maybeJson) {
  if (is_array($maybeJson)) return $maybeJson;
  if (is_string($maybeJson)) {
    $tmp = json_decode($maybeJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) return $tmp;
  }
  return [];
}

function map_event_to_insert_data(array $event): array {
  // resolution may arrive as object OR JSON string
  $pixel_data = as_array($event['resolution'] ?? []);

  // event_data may arrive as object OR JSON string
  $event_data = as_array($event['event_data'] ?? []);

  $ins = [
    // Basic event fields
    "pixel_id"           => isset($event['pixel_id']) ? (string)$event['pixel_id'] : '',
    "hem_sha256"         => isset($event['hem_sha256']) ? (string)$event['hem_sha256'] : '',
    "event_timestamp"    => isset($event['event_timestamp']) ? (string)$event['event_timestamp'] : '',
    "event_type"         => isset($event['event_type']) ? (string)$event['event_type'] : '',
    "referrer_url"       => isset($event['referrer_url']) ? (string)$event['referrer_url'] : '',
    "ip_address"         => isset($event['ip_address']) ? (string)$event['ip_address'] : '',
    "activity_start_date"=> isset($event['activity_start_date']) ? (string)$event['activity_start_date'] : '',
    "activity_end_date"  => isset($event['activity_end_date']) ? (string)$event['activity_end_date'] : '',

    // Event data fields (flat)
    "title"      => isset($event_data['title']) ? (string)$event_data['title'] : '',
    "url"        => isset($event_data['url']) ? (string)$event_data['url'] : '',
    "referrer"   => isset($event_data['referrer']) ? (string)$event_data['referrer'] : '',
    "timestamp"  => isset($event_data['timestamp']) ? (string)$event_data['timestamp'] : '',
    "percentage" => array_key_exists('percentage', $event_data) ? (string)$event_data['percentage'] : '',
    "element"    => isset($event_data['element'])
                    ? (is_string($event_data['element']) ? $event_data['element'] : json_encode($event_data['element'], JSON_UNESCAPED_SLASHES))
                    : '',

    // Personal info from resolution.*
    "uuid"                 => isset($pixel_data['UUID']) ? (string)$pixel_data['UUID'] : '',
    "first_name"           => isset($pixel_data['FIRST_NAME']) ? (string)$pixel_data['FIRST_NAME'] : '',
    "last_name"            => isset($pixel_data['LAST_NAME']) ? (string)$pixel_data['LAST_NAME'] : '',
    "personal_address"     => isset($pixel_data['PERSONAL_ADDRESS']) ? (string)$pixel_data['PERSONAL_ADDRESS'] : '',
    "personal_city"        => isset($pixel_data['PERSONAL_CITY']) ? (string)$pixel_data['PERSONAL_CITY'] : '',
    "personal_state"       => isset($pixel_data['PERSONAL_STATE']) ? (string)$pixel_data['PERSONAL_STATE'] : '',
    "personal_zip"         => isset($pixel_data['PERSONAL_ZIP']) ? (string)$pixel_data['PERSONAL_ZIP'] : '',
    "personal_zip4"        => isset($pixel_data['PERSONAL_ZIP4']) ? (string)$pixel_data['PERSONAL_ZIP4'] : '',
    "age_range"            => isset($pixel_data['AGE_RANGE']) ? (string)$pixel_data['AGE_RANGE'] : '',
    "children"             => isset($pixel_data['CHILDREN']) ? (string)$pixel_data['CHILDREN'] : '',
    "gender"               => isset($pixel_data['GENDER']) ? (string)$pixel_data['GENDER'] : '',
    "homeowner"            => isset($pixel_data['HOMEOWNER']) ? (string)$pixel_data['HOMEOWNER'] : '',
    "married"              => isset($pixel_data['MARRIED']) ? (string)$pixel_data['MARRIED'] : '',
    "income_range"         => isset($pixel_data['INCOME_RANGE']) ? (string)$pixel_data['INCOME_RANGE'] : '',
    "net_worth"            => isset($pixel_data['NET_WORTH']) ? (string)$pixel_data['NET_WORTH'] : '',

    // Contact info
    "direct_number"          => isset($pixel_data['DIRECT_NUMBER']) ? (string)$pixel_data['DIRECT_NUMBER'] : '',
    "direct_number_dnc"      => isset($pixel_data['DIRECT_NUMBER_DNC']) ? (string)$pixel_data['DIRECT_NUMBER_DNC'] : '',
    "mobile_phone"           => isset($pixel_data['MOBILE_PHONE']) ? (string)$pixel_data['MOBILE_PHONE'] : '',
    "mobile_phone_dnc"       => isset($pixel_data['MOBILE_PHONE_DNC']) ? (string)$pixel_data['MOBILE_PHONE_DNC'] : '',
    "personal_phone"         => isset($pixel_data['PERSONAL_PHONE']) ? (string)$pixel_data['PERSONAL_PHONE'] : '',
    "personal_phone_dnc"     => isset($pixel_data['PERSONAL_PHONE_DNC']) ? (string)$pixel_data['PERSONAL_PHONE_DNC'] : '',
    "personal_emails"        => isset($pixel_data['PERSONAL_EMAILS']) ? (string)$pixel_data['PERSONAL_EMAILS'] : '',
    "business_email"         => isset($pixel_data['BUSINESS_EMAIL']) ? (string)$pixel_data['BUSINESS_EMAIL'] : '',
    "deep_verified_emails"   => isset($pixel_data['DEEP_VERIFIED_EMAILS']) ? (string)$pixel_data['DEEP_VERIFIED_EMAILS'] : '',
    "sha256_personal_email"  => isset($pixel_data['SHA256_PERSONAL_EMAIL']) ? (string)$pixel_data['SHA256_PERSONAL_EMAIL'] : '',
    "sha256_business_email"  => isset($pixel_data['SHA256_BUSINESS_EMAIL']) ? (string)$pixel_data['SHA256_BUSINESS_EMAIL'] : '',

    // Company info
    "company_address"        => isset($pixel_data['COMPANY_ADDRESS']) ? (string)$pixel_data['COMPANY_ADDRESS'] : '',
    "company_name"           => isset($pixel_data['COMPANY_NAME']) ? (string)$pixel_data['COMPANY_NAME'] : '',
    "company_city"           => isset($pixel_data['COMPANY_CITY']) ? (string)$pixel_data['COMPANY_CITY'] : '',
    "company_state"          => isset($pixel_data['COMPANY_STATE']) ? (string)$pixel_data['COMPANY_STATE'] : '',
    "company_zip"            => isset($pixel_data['COMPANY_ZIP']) ? (string)$pixel_data['COMPANY_ZIP'] : '',
    "company_description"    => isset($pixel_data['COMPANY_DESCRIPTION']) ? (string)$pixel_data['COMPANY_DESCRIPTION'] : '',
    "company_domain"         => isset($pixel_data['COMPANY_DOMAIN']) ? (string)$pixel_data['COMPANY_DOMAIN'] : '',
    "company_employee_count" => isset($pixel_data['COMPANY_EMPLOYEE_COUNT']) ? (string)$pixel_data['COMPANY_EMPLOYEE_COUNT'] : '',
    "company_industry"       => isset($pixel_data['COMPANY_INDUSTRY']) ? (string)$pixel_data['COMPANY_INDUSTRY'] : '',
    "company_phone"          => isset($pixel_data['COMPANY_PHONE']) ? (string)$pixel_data['COMPANY_PHONE'] : '',
    "company_revenue"        => isset($pixel_data['COMPANY_REVENUE']) ? (string)$pixel_data['COMPANY_REVENUE'] : '',
    "company_sic"            => isset($pixel_data['COMPANY_SIC']) ? (string)$pixel_data['COMPANY_SIC'] : '',
    "company_naics"          => isset($pixel_data['COMPANY_NAICS']) ? (string)$pixel_data['COMPANY_NAICS'] : '',
    "company_name_history"   => isset($pixel_data['COMPANY_NAME_HISTORY']) ? $pixel_data['COMPANY_NAME_HISTORY'] : '',

    // Professional info
    "job_title"              => isset($pixel_data['JOB_TITLE']) ? (string)$pixel_data['JOB_TITLE'] : '',
    "job_title_history"      => isset($pixel_data['JOB_TITLE_HISTORY']) ? $pixel_data['JOB_TITLE_HISTORY'] : '',
    "headline"               => isset($pixel_data['HEADLINE']) ? (string)$pixel_data['HEADLINE'] : '',
    "department"             => isset($pixel_data['DEPARTMENT']) ? (string)$pixel_data['DEPARTMENT'] : '',
    "seniority_level"        => isset($pixel_data['SENIORITY_LEVEL']) ? (string)$pixel_data['SENIORITY_LEVEL'] : '',
    "inferred_years_experience" => isset($pixel_data['INFERRED_YEARS_EXPERIENCE']) ? (string)$pixel_data['INFERRED_YEARS_EXPERIENCE'] : '',

    // Social
    "linkedin_url"        => isset($pixel_data['LINKEDIN_URL']) ? (string)$pixel_data['LINKEDIN_URL'] : '',
    "twitter_url"         => isset($pixel_data['TWITTER_URL']) ? (string)$pixel_data['TWITTER_URL'] : '',
    "facebook_url"        => isset($pixel_data['FACEBOOK_URL']) ? (string)$pixel_data['FACEBOOK_URL'] : '',
    "social_connections"  => isset($pixel_data['SOCIAL_CONNECTIONS']) ? (string)$pixel_data['SOCIAL_CONNECTIONS'] : '',
    "skills"              => isset($pixel_data['SKILLS']) ? (string)$pixel_data['SKILLS'] : '',
    "interests"           => isset($pixel_data['INTERESTS']) ? (string)$pixel_data['INTERESTS'] : '',

    // Skiptrace
    "skiptrace_match_score"     => isset($pixel_data['SKIPTRACE_MATCH_SCORE']) ? (string)$pixel_data['SKIPTRACE_MATCH_SCORE'] : '',
    "skiptrace_name"            => isset($pixel_data['SKIPTRACE_NAME']) ? (string)$pixel_data['SKIPTRACE_NAME'] : '',
    "skiptrace_address"         => isset($pixel_data['SKIPTRACE_ADDRESS']) ? (string)$pixel_data['SKIPTRACE_ADDRESS'] : '',
    "skiptrace_city"            => isset($pixel_data['SKIPTRACE_CITY']) ? (string)$pixel_data['SKIPTRACE_CITY'] : '',
    "skiptrace_state"           => isset($pixel_data['SKIPTRACE_STATE']) ? (string)$pixel_data['SKIPTRACE_STATE'] : '',
    "skiptrace_zip"             => isset($pixel_data['SKIPTRACE_ZIP']) ? (string)$pixel_data['SKIPTRACE_ZIP'] : '',
    "skiptrace_landline_numbers"=> isset($pixel_data['SKIPTRACE_LANDLINE_NUMBERS']) ? (string)$pixel_data['SKIPTRACE_LANDLINE_NUMBERS'] : '',
    "skiptrace_wireless_numbers"=> isset($pixel_data['SKIPTRACE_WIRELESS_NUMBERS']) ? (string)$pixel_data['SKIPTRACE_WIRELESS_NUMBERS'] : '',
    "skiptrace_credit_rating"   => isset($pixel_data['SKIPTRACE_CREDIT_RATING']) ? (string)$pixel_data['SKIPTRACE_CREDIT_RATING'] : '',
    "skiptrace_dnc"             => isset($pixel_data['SKIPTRACE_DNC']) ? (string)$pixel_data['SKIPTRACE_DNC'] : '',
    "skiptrace_exact_age"       => isset($pixel_data['SKIPTRACE_EXACT_AGE']) ? (string)$pixel_data['SKIPTRACE_EXACT_AGE'] : '',
    "skiptrace_ethnic_code"     => isset($pixel_data['SKIPTRACE_ETHNIC_CODE']) ? (string)$pixel_data['SKIPTRACE_ETHNIC_CODE'] : '',
    "skiptrace_language_code"   => isset($pixel_data['SKIPTRACE_LANGUAGE_CODE']) ? (string)$pixel_data['SKIPTRACE_LANGUAGE_CODE'] : '',
    "skiptrace_ip"              => isset($pixel_data['SKIPTRACE_IP']) ? (string)$pixel_data['SKIPTRACE_IP'] : '',
    "skiptrace_b2b_address"     => isset($pixel_data['SKIPTRACE_B2B_ADDRESS']) ? (string)$pixel_data['SKIPTRACE_B2B_ADDRESS'] : '',
    "skiptrace_b2b_phone"       => isset($pixel_data['SKIPTRACE_B2B_PHONE']) ? (string)$pixel_data['SKIPTRACE_B2B_PHONE'] : '',
    "skiptrace_b2b_source"      => isset($pixel_data['SKIPTRACE_B2B_SOURCE']) ? (string)$pixel_data['SKIPTRACE_B2B_SOURCE'] : '',
    "skiptrace_b2b_website"     => isset($pixel_data['SKIPTRACE_B2B_WEBSITE']) ? (string)$pixel_data['SKIPTRACE_B2B_WEBSITE'] : '',

    // Other
    "valid_phones"          => isset($pixel_data['VALID_PHONES']) ? (string)$pixel_data['VALID_PHONES'] : '',
  ];

  // Normalize COMPANY_NAME_HISTORY / JOB_TITLE_HISTORY if needed
  foreach (['company_name_history' => 'COMPANY_NAME_HISTORY', 'job_title_history' => 'JOB_TITLE_HISTORY'] as $dest => $srcKey) {
    if (array_key_exists($srcKey, $pixel_data)) {
      $raw = $pixel_data[$srcKey];
      if (is_array($raw) || is_object($raw)) {
        $ins[$dest] = json_encode($raw, JSON_UNESCAPED_SLASHES);
      } else {
        $s = (string)$raw;
        if ($s !== '' && $s !== 'Array') { $ins[$dest] = $s; }
      }
    }
  }

  return $ins;
}

// returns true if a matching row is already present in superpixel_resolution_log
function exists_in_log(mysqli $dst, string $uuid, string $evtTs, string $hem): bool {
  // hem can be empty; use OR to handle null/empty
  $sql = "SELECT id FROM superpixel_resolution_log
          WHERE uuid = ? AND event_timestamp = ?
            AND ( (hem_sha256 = ?) OR ( (hem_sha256 IS NULL OR hem_sha256='') AND ?='' ) )
          LIMIT 1";
  $stmt = $dst->prepare($sql);
  if (!$stmt) return false;
  $stmt->bind_param('ssss', $uuid, $evtTs, $hem, $hem);
  $stmt->execute();
  $stmt->store_result();
  $found = $stmt->num_rows > 0;
  $stmt->free_result();
  $stmt->close();
  return $found;
}

function insert_resolution_log(mysqli $dst, array $data): bool {
  // Build dynamic INSERT of only known columns mapped in pixel_import.php
  $cols = array_keys($data);
  $placeholders = array_fill(0, count($cols), '?');

  $sql = "INSERT INTO superpixel_resolution_log (`" . implode("`,`", $cols) . "`) VALUES (" . implode(",", $placeholders) . ")";
  $stmt = $dst->prepare($sql);
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $dst->error);
  }

  // All params as strings (the table stores varchars/text)
  $types = str_repeat('s', count($cols));
  $vals  = array_map(function($v) {
    if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
    if (is_bool($v)) return $v ? '1' : '0';
    return (string)($v ?? '');
  }, array_values($data));

  $stmt->bind_param($types, ...$vals);
  $ok = $stmt->execute();
  if (!$ok) {
    throw new Exception("Insert failed: " . $stmt->error);
  }
  $stmt->close();
  return true;
}

// --- Fetch last N raw_events for this client ---
$q = "SELECT id, payload
      FROM raw_events
      WHERE client_name = ?
      ORDER BY id DESC
      LIMIT ?";
$stmt = $src->prepare($q);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['error' => 'Query prepare failed: ' . $src->error]);
  exit;
}
$stmt->bind_param('si', $clientDb, $limit);
$stmt->execute();
$res = $stmt->get_result();

$processed = 0;
$skipped_exists = 0;
$skipped_badjson = 0;
$errors = [];

while ($row = $res->fetch_assoc()) {
  $payload = $row['payload'];
  $event = json_decode($payload, true);

  if (json_last_error() !== JSON_ERROR_NONE || !is_array($event)) {
    $skipped_badjson++;
    continue;
  }

  $ins = map_event_to_insert_data($event);

  // Basic dedupe guard for safety: uuid + event_timestamp + hem_sha256
  $uuid  = $ins['uuid'] ?? '';
  $evtTs = $ins['event_timestamp'] ?? '';
  $hem   = $ins['hem_sha256'] ?? '';

  if ($uuid !== '' && $evtTs !== '' && exists_in_log($dst, $uuid, $evtTs, $hem)) {
    $skipped_exists++;
    continue;
  }

  try {
    insert_resolution_log($dst, $ins);
    $processed++;
  } catch (Throwable $e) {
    $errors[] = "id {$row['id']}: " . $e->getMessage();
  }
}

$stmt->close();
$src->close();
$dst->close();

echo json_encode([
  'client' => $client,
  'limit_requested' => $limit,
  'inserted' => $processed,
  'skipped_existing' => $skipped_exists,
  'skipped_bad_json' => $skipped_badjson,
  'errors' => $errors
], JSON_PRETTY_PRINT);
