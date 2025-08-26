<?php
/**
 * backfill_last5_from_raw_events.php (v2 with diagnostics & logging)
 *
 * Pull the last N raw_events for USA_Financial_NEW from pixel.raw_events
 * and insert into USA_Financial_NEW.superpixel_resolution_log.
 *
 * Flags:
 *   --limit=N        default 5
 *   --client=NAME    default USA_Financial_NEW
 *   --dry-run        parse & map only, no inserts
 *   --verbose        echo progress to stdout too
 *   --logfile=/path  default /var/www/hook.thynkdata.com/backfill_last5_debug.log
 */

ini_set('display_errors', '0');
date_default_timezone_set('UTC');

function argval($name, $default=null) {
  foreach ($GLOBALS['argv'] ?? [] as $a) {
    if (preg_match('/^--'.preg_quote($name,'/').'(=(.*))?$/', $a, $m)) {
      return isset($m[2]) ? $m[2] : true;
    }
  }
  return $default;
}

$DB_HOST = getenv('DB_HOST') ?: '34.31.66.104';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: 'AccuPoint01!';

$CLIENT   = preg_replace('/[^a-zA-Z0-9_]/', '', (string)argval('client','USA_Financial_NEW'));
$SRC_DB   = 'pixel';
$DEST_DB  = $CLIENT;

$LIMIT    = (int) (argval('limit', 5));
if ($LIMIT <= 0) { $LIMIT = 5; }

$DRY_RUN  = (bool) argval('dry-run', false);
$VERBOSE  = (bool) argval('verbose', false);
$LOGFILE  = (string) argval('logfile', '/var/www/hook.thynkdata.com/backfill_last5_debug.log');

function logline($msg, $echo=false) {
  global $LOGFILE, $VERBOSE;
  $line = '['.gmdate('c').'] '.$msg."\n";
  file_put_contents($LOGFILE, $line, FILE_APPEND);
  if ($echo || $VERBOSE) { fwrite(STDOUT, $line); }
}

function redactUrl($url) {
  if (!$url) return '';
  $p = parse_url($url);
  $host = $p['host'] ?? '';
  $path = isset($p['path']) ? trim($p['path'], '/') : '';
  if (strlen($path) > 48) $path = substr($path, 0, 48).'…';
  return $host . ($path ? '/'.$path : '');
}

function as_array($maybeJson) {
  if (is_array($maybeJson)) return $maybeJson;
  if (is_string($maybeJson)) {
    $tmp = json_decode($maybeJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) return $tmp;
  }
  return [];
}

function map_event_to_insert_data(array $event): array {
  $pixel_data = as_array($event['resolution'] ?? []);
  $event_data = as_array($event['event_data'] ?? []);

  $ins = [
    "pixel_id"            => (string)($event['pixel_id'] ?? ''),
    "hem_sha256"          => (string)($event['hem_sha256'] ?? ''),
    "event_timestamp"     => (string)($event['event_timestamp'] ?? ''),
    "event_type"          => (string)($event['event_type'] ?? ''),
    "referrer_url"        => (string)($event['referrer_url'] ?? ''),
    "ip_address"          => (string)($event['ip_address'] ?? ''),
    "activity_start_date" => (string)($event['activity_start_date'] ?? ''),
    "activity_end_date"   => (string)($event['activity_end_date'] ?? ''),
    // event_data
    "title"      => (string)($event_data['title'] ?? ''),
    "url"        => (string)($event_data['url'] ?? ''),
    "referrer"   => (string)($event_data['referrer'] ?? ''),
    "timestamp"  => (string)($event_data['timestamp'] ?? ''),
    "percentage" => (string)($event_data['percentage'] ?? (array_key_exists('percentage',$event_data) ? $event_data['percentage'] : '')),
    "element"    => isset($event_data['element']) ? (is_string($event_data['element']) ? $event_data['element'] : json_encode($event_data['element'], JSON_UNESCAPED_SLASHES)) : '',
    // resolution.* (UPPERCASE → lowercase)
    "uuid"                 => (string)($pixel_data['UUID'] ?? ''),
    "first_name"           => (string)($pixel_data['FIRST_NAME'] ?? ''),
    "last_name"            => (string)($pixel_data['LAST_NAME'] ?? ''),
    "personal_address"     => (string)($pixel_data['PERSONAL_ADDRESS'] ?? ''),
    "personal_city"        => (string)($pixel_data['PERSONAL_CITY'] ?? ''),
    "personal_state"       => (string)($pixel_data['PERSONAL_STATE'] ?? ''),
    "personal_zip"         => (string)($pixel_data['PERSONAL_ZIP'] ?? ''),
    "personal_zip4"        => (string)($pixel_data['PERSONAL_ZIP4'] ?? ''),
    "age_range"            => (string)($pixel_data['AGE_RANGE'] ?? ''),
    "children"             => (string)($pixel_data['CHILDREN'] ?? ''),
    "gender"               => (string)($pixel_data['GENDER'] ?? ''),
    "homeowner"            => (string)($pixel_data['HOMEOWNER'] ?? ''),
    "married"              => (string)($pixel_data['MARRIED'] ?? ''),
    "income_range"         => (string)($pixel_data['INCOME_RANGE'] ?? ''),
    "net_worth"            => (string)($pixel_data['NET_WORTH'] ?? ''),
    "direct_number"        => (string)($pixel_data['DIRECT_NUMBER'] ?? ''),
    "direct_number_dnc"    => (string)($pixel_data['DIRECT_NUMBER_DNC'] ?? ''),
    "mobile_phone"         => (string)($pixel_data['MOBILE_PHONE'] ?? ''),
    "mobile_phone_dnc"     => (string)($pixel_data['MOBILE_PHONE_DNC'] ?? ''),
    "personal_phone"       => (string)($pixel_data['PERSONAL_PHONE'] ?? ''),
    "personal_phone_dnc"   => (string)($pixel_data['PERSONAL_PHONE_DNC'] ?? ''),
    "personal_emails"      => (string)($pixel_data['PERSONAL_EMAILS'] ?? ''),
    "business_email"       => (string)($pixel_data['BUSINESS_EMAIL'] ?? ''),
    "deep_verified_emails" => (string)($pixel_data['DEEP_VERIFIED_EMAILS'] ?? ''),
    "sha256_personal_email"=> (string)($pixel_data['SHA256_PERSONAL_EMAIL'] ?? ''),
    "sha256_business_email"=> (string)($pixel_data['SHA256_BUSINESS_EMAIL'] ?? ''),
    "company_address"      => (string)($pixel_data['COMPANY_ADDRESS'] ?? ''),
    "company_name"         => (string)($pixel_data['COMPANY_NAME'] ?? ''),
    "company_city"         => (string)($pixel_data['COMPANY_CITY'] ?? ''),
    "company_state"        => (string)($pixel_data['COMPANY_STATE'] ?? ''),
    "company_zip"          => (string)($pixel_data['COMPANY_ZIP'] ?? ''),
    "company_description"  => (string)($pixel_data['COMPANY_DESCRIPTION'] ?? ''),
    "company_domain"       => (string)($pixel_data['COMPANY_DOMAIN'] ?? ''),
    "company_employee_count"=> (string)($pixel_data['COMPANY_EMPLOYEE_COUNT'] ?? ''),
    "company_industry"     => (string)($pixel_data['COMPANY_INDUSTRY'] ?? ''),
    "company_phone"        => (string)($pixel_data['COMPANY_PHONE'] ?? ''),
    "company_revenue"      => (string)($pixel_data['COMPANY_REVENUE'] ?? ''),
    "company_sic"          => (string)($pixel_data['COMPANY_SIC'] ?? ''),
    "company_naics"        => (string)($pixel_data['COMPANY_NAICS'] ?? ''),
    "job_title"            => (string)($pixel_data['JOB_TITLE'] ?? ''),
    "headline"             => (string)($pixel_data['HEADLINE'] ?? ''),
    "department"           => (string)($pixel_data['DEPARTMENT'] ?? ''),
    "seniority_level"      => (string)($pixel_data['SENIORITY_LEVEL'] ?? ''),
    "inferred_years_experience" => (string)($pixel_data['INFERRED_YEARS_EXPERIENCE'] ?? ''),
    "linkedin_url"         => (string)($pixel_data['LINKEDIN_URL'] ?? ''),
    "twitter_url"          => (string)($pixel_data['TWITTER_URL'] ?? ''),
    "facebook_url"         => (string)($pixel_data['FACEBOOK_URL'] ?? ''),
    "social_connections"   => (string)($pixel_data['SOCIAL_CONNECTIONS'] ?? ''),
    "skills"               => (string)($pixel_data['SKILLS'] ?? ''),
    "interests"            => (string)($pixel_data['INTERESTS'] ?? ''),
    "skiptrace_match_score"=> (string)($pixel_data['SKIPTRACE_MATCH_SCORE'] ?? ''),
    "skiptrace_name"       => (string)($pixel_data['SKIPTRACE_NAME'] ?? ''),
    "skiptrace_address"    => (string)($pixel_data['SKIPTRACE_ADDRESS'] ?? ''),
    "skiptrace_city"       => (string)($pixel_data['SKIPTRACE_CITY'] ?? ''),
    "skiptrace_state"      => (string)($pixel_data['SKIPTRACE_STATE'] ?? ''),
    "skiptrace_zip"        => (string)($pixel_data['SKIPTRACE_ZIP'] ?? ''),
    "skiptrace_landline_numbers" => (string)($pixel_data['SKIPTRACE_LANDLINE_NUMBERS'] ?? ''),
    "skiptrace_wireless_numbers" => (string)($pixel_data['SKIPTRACE_WIRELESS_NUMBERS'] ?? ''),
    "skiptrace_credit_rating"    => (string)($pixel_data['SKIPTRACE_CREDIT_RATING'] ?? ''),
    "skiptrace_dnc"        => (string)($pixel_data['SKIPTRACE_DNC'] ?? ''),
    "skiptrace_exact_age"  => (string)($pixel_data['SKIPTRACE_EXACT_AGE'] ?? ''),
    "skiptrace_ethnic_code"=> (string)($pixel_data['SKIPTRACE_ETHNIC_CODE'] ?? ''),
    "skiptrace_language_code"=> (string)($pixel_data['SKIPTRACE_LANGUAGE_CODE'] ?? ''),
    "skiptrace_ip"         => (string)($pixel_data['SKIPTRACE_IP'] ?? ''),
    "skiptrace_b2b_address"=> (string)($pixel_data['SKIPTRACE_B2B_ADDRESS'] ?? ''),
    "skiptrace_b2b_phone"  => (string)($pixel_data['SKIPTRACE_B2B_PHONE'] ?? ''),
    "skiptrace_b2b_source" => (string)($pixel_data['SKIPTRACE_B2B_SOURCE'] ?? ''),
    "skiptrace_b2b_website"=> (string)($pixel_data['SKIPTRACE_B2B_WEBSITE'] ?? ''),
    "valid_phones"         => (string)($pixel_data['VALID_PHONES'] ?? ''),
  ];

  foreach (['COMPANY_NAME_HISTORY' => 'company_name_history', 'JOB_TITLE_HISTORY' => 'job_title_history'] as $src => $dst) {
    if (array_key_exists($src, $pixel_data)) {
      $raw = $pixel_data[$src];
      if (is_array($raw) || is_object($raw)) {
        $ins[$dst] = json_encode($raw, JSON_UNESCAPED_SLASHES);
      } else {
        $s = (string)$raw;
        if ($s !== '' && $s !== 'Array') $ins[$dst] = $s;
      }
    }
  }
  return $ins;
}

function exists_in_log(mysqli $dst, string $uuid, string $evtTs, string $hem): bool {
  $sql = "SELECT 1 FROM superpixel_resolution_log
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

function insert_resolution_log(mysqli $dst, array $data): void {
  $cols = array_keys($data);
  $placeholders = array_fill(0, count($cols), '?');
  $sql = "INSERT INTO superpixel_resolution_log (`".implode('`,`',$cols)."`) VALUES (".implode(',', $placeholders).")";
  $stmt = $dst->prepare($sql);
  if (!$stmt) throw new Exception("Prepare failed: ".$dst->error);
  $types = str_repeat('s', count($cols));
  $vals  = array_map(function($v){
    if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
    if (is_bool($v)) return $v ? '1' : '0';
    return (string)($v ?? '');
  }, array_values($data));
  $stmt->bind_param($types, ...$vals);
  $stmt->execute();
  $stmt->close();
}

// ---------- connect ----------
mysqli_report(MYSQLI_REPORT_OFF);

$src = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $SRC_DB);
if ($src->connect_error) { logline("ERR: Source DB connect failed: ".$src->connect_error, true); http_response_code(500); exit(1); }

$dst = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DEST_DB);
if ($dst->connect_error) { logline("ERR: Target DB connect failed: ".$dst->connect_error, true); http_response_code(500); exit(1); }

// ---------- diagnostics ----------
$verRes = $dst->query("SELECT @@version v, @@version_comment c");
$verRow = $verRes ? $verRes->fetch_assoc() : null;
logline("Start backfill client={$DEST_DB} limit={$LIMIT} dry_run=".($DRY_RUN?'1':'0')." mysql=".($verRow?($verRow['v'].' '.$verRow['c']):'unknown'), true);

foreach (['superpixel_resolution_log','superpixel_emails'] as $tbl) {
  $q = "SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = '".$dst->real_escape_string($DEST_DB)."' AND EVENT_OBJECT_TABLE='".$dst->real_escape_string($tbl)."'";
  if ($tres = $dst->query($q)) {
    $names = [];
    while ($r = $tres->fetch_assoc()) { $names[] = "{$r['TRIGGER_NAME']}[{$r['ACTION_TIMING']} {$r['EVENT_MANIPULATION']}]"; }
    logline("Triggers on {$DEST_DB}.{$tbl}: ".($names?implode(', ',$names):'(none)'));
  }
}

// ---------- fetch last N raw events ----------
$sql = "SELECT id,payload FROM {$SRC_DB}.raw_events WHERE client_name=? ORDER BY id DESC LIMIT ?";
$stmt = $src->prepare($sql);
$stmt->bind_param('si', $DEST_DB, $LIMIT);
$stmt->execute();
$res = $stmt->get_result();

$inserted = 0; $skippedExisting = 0; $skippedBadJson = 0; $errors = [];

while ($row = $res->fetch_assoc()) {
  $rid = (int)$row['id'];
  logline("Row {$rid}: begin");

  $payload = $row['payload'];
  $event = json_decode($payload, true);
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($event)) {
    $skippedBadJson++; logline("Row {$rid}: BAD JSON - ".json_last_error_msg());
    continue;
  }

  $ins = map_event_to_insert_data($event);
  $uuid  = $ins['uuid'] ?? '';
  $evtTs = $ins['event_timestamp'] ?? '';
  $hem   = $ins['hem_sha256'] ?? '';
  $url   = $ins['url'] ?? '';

  logline(sprintf(
    "Row %d: mapped uuid=%s evt_ts=%s hem=%s url=%s",
    $rid,
    $uuid !== '' ? $uuid : '(empty)',
    $evtTs !== '' ? $evtTs : '(empty)',
    $hem !== '' ? substr($hem,0,8).'…' : '(empty)',
    redactUrl($url)
  ));

  if ($uuid === '') { logline("Row {$rid}: SKIP missing uuid"); continue; }

  if (!$DRY_RUN && $uuid !== '' && $evtTs !== '' && exists_in_log($dst, $uuid, $evtTs, $hem)) {
    $skippedExisting++; logline("Row {$rid}: dedupe hit (exists in log)"); continue;
  }

  if ($DRY_RUN) { logline("Row {$rid}: DRY RUN (no insert)"); continue; }

  try {
    insert_resolution_log($dst, $ins);
    $inserted++; logline("Row {$rid}: INSERT OK");
  } catch (Throwable $e) {
    // Capture MySQL errno/sqlstate if available
    $errno = $dst->errno ?: ($src->errno ?: 0);
    $sqlstate = $dst->sqlstate ?: ($src->sqlstate ?: '00000');

    $msg = $e->getMessage();
    $errors[] = "id {$rid}: {$msg}";
    logline("Row {$rid}: INSERT ERR errno={$errno} sqlstate={$sqlstate} msg=".str_replace(["\n","\r"],' ',$msg), true);

    if (strpos($msg, "already used by statement which invoked this stored function") !== false || $errno == 1442) {
      logline("Row {$rid}: HINT => MySQL 1442 (trigger recursion). This happens when an AFTER INSERT trigger on superpixel_resolution_log calls a proc that writes back to superpixel_resolution_log or superpixel_emails.", true);
    }
  }
}

$stmt->close();
$src->close();
$dst->close();

$out = [
  'client' => $DEST_DB,
  'limit_requested' => $LIMIT,
  'inserted' => $inserted,
  'skipped_existing' => $skippedExisting,
  'skipped_bad_json' => $skippedBadJson,
  'errors' => $errors,
];
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
logline("Done: ".json_encode($out));
