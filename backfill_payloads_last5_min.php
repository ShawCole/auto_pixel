<?php
/**
 * backfill_payloads_last5_min.php
 * Minimal: copy last 5 raw payloads into USA_Financial_NEW.superpixel_resolution_log
 */

ini_set('display_errors', '0');
header('Content-Type: application/json');

$DB_HOST = getenv('DB_HOST') ?: '34.31.66.104';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: 'AccuPoint01!';

$CLIENT  = 'USA_Financial_NEW'; // target DB & client_name
$SRC_DB  = 'pixel';
$LIMIT   = 5;

function as_array($v) {
  if (is_array($v)) return $v;
  if (is_string($v)) {
    $t = json_decode($v, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($t)) return $t;
  }
  return [];
}

function map_event(array $event): array {
  $res = as_array($event['resolution'] ?? []);
  $ed  = as_array($event['event_data'] ?? []);

  $ins = [
    // basic event fields
    'pixel_id'            => (string)($event['pixel_id'] ?? ''),
    'hem_sha256'          => (string)($event['hem_sha256'] ?? ''),
    'event_timestamp'     => (string)($event['event_timestamp'] ?? ''),
    'event_type'          => (string)($event['event_type'] ?? ''),
    'ip_address'          => (string)($event['ip_address'] ?? ''),
    'activity_start_date' => (string)($event['activity_start_date'] ?? ''),
    'activity_end_date'   => (string)($event['activity_end_date'] ?? ''),
    'referrer_url'        => (string)($event['referrer_url'] ?? ''),

    // event_data flatten
    'title'      => (string)($ed['title'] ?? ''),
    'url'        => (string)($ed['url'] ?? ''),
    'referrer'   => (string)($ed['referrer'] ?? ''),
    'timestamp'  => (string)($ed['timestamp'] ?? ''),
    'percentage' => (string)(array_key_exists('percentage',$ed) ? $ed['percentage'] : ''),
    'element'    => isset($ed['element'])
                    ? (is_string($ed['element']) ? $ed['element'] : json_encode($ed['element'], JSON_UNESCAPED_SLASHES))
                    : '',

    // resolution → columns (UPPERCASE → lowercase)
    'uuid'                 => (string)($res['UUID'] ?? ''),
    'first_name'           => (string)($res['FIRST_NAME'] ?? ''),
    'last_name'            => (string)($res['LAST_NAME'] ?? ''),
    'personal_address'     => (string)($res['PERSONAL_ADDRESS'] ?? ''),
    'personal_city'        => (string)($res['PERSONAL_CITY'] ?? ''),
    'personal_state'       => (string)($res['PERSONAL_STATE'] ?? ''),
    'personal_zip'         => (string)($res['PERSONAL_ZIP'] ?? ''),
    'personal_zip4'        => (string)($res['PERSONAL_ZIP4'] ?? ''),
    'age_range'            => (string)($res['AGE_RANGE'] ?? ''),
    'children'             => (string)($res['CHILDREN'] ?? ''),
    'gender'               => (string)($res['GENDER'] ?? ''),
    'homeowner'            => (string)($res['HOMEOWNER'] ?? ''),
    'married'              => (string)($res['MARRIED'] ?? ''),
    'net_worth'            => (string)($res['NET_WORTH'] ?? ''),
    'income_range'         => (string)($res['INCOME_RANGE'] ?? ''),

    'direct_number'        => (string)($res['DIRECT_NUMBER'] ?? ''),
    'direct_number_dnc'    => (string)($res['DIRECT_NUMBER_DNC'] ?? ''),
    'mobile_phone'         => (string)($res['MOBILE_PHONE'] ?? ''),
    'mobile_phone_dnc'     => (string)($res['MOBILE_PHONE_DNC'] ?? ''),
    'personal_phone'       => (string)($res['PERSONAL_PHONE'] ?? ''),
    'personal_phone_dnc'   => (string)($res['PERSONAL_PHONE_DNC'] ?? ''),
    'business_email'       => (string)($res['BUSINESS_EMAIL'] ?? ''),
    'personal_emails'      => (string)($res['PERSONAL_EMAILS'] ?? ''),
    'deep_verified_emails' => (string)($res['DEEP_VERIFIED_EMAILS'] ?? ''),
    'sha256_personal_email'=> (string)($res['SHA256_PERSONAL_EMAIL'] ?? ''),
    'sha256_business_email'=> (string)($res['SHA256_BUSINESS_EMAIL'] ?? ''),

    'company_address'        => (string)($res['COMPANY_ADDRESS'] ?? ''),
    'company_name'           => (string)($res['COMPANY_NAME'] ?? ''),
    'company_city'           => (string)($res['COMPANY_CITY'] ?? ''),
    'company_state'          => (string)($res['COMPANY_STATE'] ?? ''),
    'company_zip'            => (string)($res['COMPANY_ZIP'] ?? ''),
    'company_description'    => (string)($res['COMPANY_DESCRIPTION'] ?? ''),
    'company_domain'         => (string)($res['COMPANY_DOMAIN'] ?? ''),
    'company_employee_count' => (string)($res['COMPANY_EMPLOYEE_COUNT'] ?? ''),
    'company_industry'       => (string)($res['COMPANY_INDUSTRY'] ?? ''),
    'company_phone'          => (string)($res['COMPANY_PHONE'] ?? ''),
    'company_revenue'        => (string)($res['COMPANY_REVENUE'] ?? ''),
    'company_sic'            => (string)($res['COMPANY_SIC'] ?? ''),
    'company_naics'          => (string)($res['COMPANY_NAICS'] ?? ''),

    'job_title'              => (string)($res['JOB_TITLE'] ?? ''),
    'job_title_history'      => isset($res['JOB_TITLE_HISTORY']) ? (is_array($res['JOB_TITLE_HISTORY']) || is_object($res['JOB_TITLE_HISTORY']) ? json_encode($res['JOB_TITLE_HISTORY'], JSON_UNESCAPED_SLASHES) : (string)$res['JOB_TITLE_HISTORY']) : '',
    'headline'               => (string)($res['HEADLINE'] ?? ''),
    'department'             => (string)($res['DEPARTMENT'] ?? ''),
    'seniority_level'        => (string)($res['SENIORITY_LEVEL'] ?? ''),
    'inferred_years_experience' => (string)($res['INFERRED_YEARS_EXPERIENCE'] ?? ''),

    'linkedin_url'        => (string)($res['LINKEDIN_URL'] ?? ''),
    'twitter_url'         => (string)($res['TWITTER_URL'] ?? ''),
    'facebook_url'        => (string)($res['FACEBOOK_URL'] ?? ''),
    'social_connections'  => (string)($res['SOCIAL_CONNECTIONS'] ?? ''),
    'skills'              => (string)($res['SKILLS'] ?? ''),
    'interests'           => (string)($res['INTERESTS'] ?? ''),

    'skiptrace_match_score'     => (string)($res['SKIPTRACE_MATCH_SCORE'] ?? ''),
    'skiptrace_name'            => (string)($res['SKIPTRACE_NAME'] ?? ''),
    'skiptrace_address'         => (string)($res['SKIPTRACE_ADDRESS'] ?? ''),
    'skiptrace_city'            => (string)($res['SKIPTRACE_CITY'] ?? ''),
    'skiptrace_state'           => (string)($res['SKIPTRACE_STATE'] ?? ''),
    'skiptrace_zip'             => (string)($res['SKIPTRACE_ZIP'] ?? ''),
    'skiptrace_landline_numbers'=> (string)($res['SKIPTRACE_LANDLINE_NUMBERS'] ?? ''),
    'skiptrace_wireless_numbers'=> (string)($res['SKIPTRACE_WIRELESS_NUMBERS'] ?? ''),
    'skiptrace_credit_rating'   => (string)($res['SKIPTRACE_CREDIT_RATING'] ?? ''),
    'skiptrace_dnc'             => (string)($res['SKIPTRACE_DNC'] ?? ''),
    'skiptrace_exact_age'       => (string)($res['SKIPTRACE_EXACT_AGE'] ?? ''),
    'skiptrace_ethnic_code'     => (string)($res['SKIPTRACE_ETHNIC_CODE'] ?? ''),
    'skiptrace_language_code'   => (string)($res['SKIPTRACE_LANGUAGE_CODE'] ?? ''),
    'skiptrace_ip'              => (string)($res['SKIPTRACE_IP'] ?? ''),
    'skiptrace_b2b_address'     => (string)($res['SKIPTRACE_B2B_ADDRESS'] ?? ''),
    'skiptrace_b2b_phone'       => (string)($res['SKIPTRACE_B2B_PHONE'] ?? ''),
    'skiptrace_b2b_source'      => (string)($res['SKIPTRACE_B2B_SOURCE'] ?? ''),
    'skiptrace_b2b_website'     => (string)($res['SKIPTRACE_B2B_WEBSITE'] ?? ''),

    'valid_phones'         => (string)($res['VALID_PHONES'] ?? ''),
  ];

  // normalize company_name_history if present
  if (array_key_exists('COMPANY_NAME_HISTORY', $res)) {
    $raw = $res['COMPANY_NAME_HISTORY'];
    $ins['company_name_history'] = (is_array($raw) || is_object($raw)) ? json_encode($raw, JSON_UNESCAPED_SLASHES) : ((string)$raw === 'Array' ? '' : (string)$raw);
  }

  return $ins;
}

// connections
$src = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $SRC_DB);
if ($src->connect_error) { echo json_encode(['error'=>'src connect failed']); exit(1); }
$dst = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $CLIENT);
if ($dst->connect_error) { echo json_encode(['error'=>'dst connect failed']); exit(1); }

// ask triggers (if guarded) to skip side-effects
$dst->query("SET @TD_BACKFILL = 1");

// fetch last 5 payloads for this client
$stmt = $src->prepare("SELECT id, payload FROM raw_events WHERE client_name=? ORDER BY id DESC LIMIT ?");
$stmt->bind_param('si', $CLIENT, $LIMIT);
$stmt->execute();
$res = $stmt->get_result();

$ok=0; $err=[];
while ($row = $res->fetch_assoc()) {
  $event = json_decode($row['payload'], true);
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($event)) {
    $err[] = "id {$row['id']}: bad JSON";
    continue;
  }
  $data = map_event($event);

  // uuid is NOT NULL in superpixel_resolution_log
  if (($data['uuid'] ?? '') === '') { $err[] = "id {$row['id']}: missing uuid"; continue; }

  // build insert
  $cols = array_keys($data);
  $place = implode(',', array_fill(0, count($cols), '?'));
  $sql = "INSERT INTO superpixel_resolution_log (`".implode('`,`',$cols)."`) VALUES ($place)";
  $ins = $dst->prepare($sql);
  if (!$ins) { $err[] = "id {$row['id']}: prep failed ".$dst->error; continue; }
  $types = str_repeat('s', count($cols));
  $vals  = array_map(function($v){
    if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
    return (string)($v ?? '');
  }, array_values($data));
  $ins->bind_param($types, ...$vals);

  if (!$ins->execute()) {
    $err[] = "id {$row['id']}: insert failed ".$ins->error;
  } else {
    $ok++;
  }
  $ins->close();
}

$stmt->close();
$src->close();
$dst->close();

echo json_encode(['inserted'=>$ok,'errors'=>$err], JSON_PRETTY_PRINT), "\n";
