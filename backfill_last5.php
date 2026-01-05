<?php
/**
 * backfill_last5.php
 * - Pulls last 5 rows for client 'USA_Financial_NEW' from pixel.raw_events
 * - Parses payload like pixel_import.php
 * - Normalizes all timestamps to 'YYYY-MM-DD HH:MM:SS'
 * - Inserts into USA_Financial_NEW.superpixel_resolution_log
 * - DOES NOT touch superpixel_emails (avoids 1442)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$dbHost = getenv('DB_HOST') ?: '34.26.61.148';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'AccuPoint01!';

$SRC_DB = 'pixel';
$DST_DB = 'USA_Financial_NEW';
$CLIENT = 'USA_Financial_NEW';

function norm_dt(?string $s, ?string $fallback = null): ?string {
  if ($s === null) return $fallback;
  $s = trim($s);
  if ($s === '' || stripos($s, '0000-00-00') === 0) return $fallback;

  // Handle ISO8601 with T / Z and optional millis
  // Examples: 2025-08-25T16:37:13Z  |  2025-08-25T16:37:13.439Z  |  2025-08-25 16:37:13
  $s = str_replace('T', ' ', $s);
  if (substr($s, -1) === 'Z') $s = substr($s, 0, -1);
  // Trim fractional seconds if present
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $s)) {
    $s = preg_replace('/\.\d+$/', '', $s);
  }
  // Accept date-only
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
    return $s . ' 00:00:00';
  }
  // Accept full datetime
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) {
    return $s;
  }
  return $fallback;
}

function getTargetColumns(mysqli $conn, string $db, string $table): array {
  $cols = [];
  $res = $conn->query("SHOW COLUMNS FROM `$db`.`$table`");
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      // ['Field' => name, 'Type' => type, ...]
      $cols[$row['Field']] = strtolower($row['Type']);
    }
    $res->free();
  }
  return $cols;
}

function esc(mysqli $conn, $v): string {
  return $conn->real_escape_string((string)$v);
}

$src = new mysqli($dbHost, $dbUser, $dbPass, $SRC_DB);
if ($src->connect_error) {
  fwrite(STDERR, "Source DB connect failed: " . $src->connect_error . PHP_EOL);
  exit(1);
}
$dst = new mysqli($dbHost, $dbUser, $dbPass, $DST_DB);
if ($dst->connect_error) {
  fwrite(STDERR, "Target DB connect failed: " . $dst->connect_error . PHP_EOL);
  exit(1);
}

$targetCols = getTargetColumns($dst, $DST_DB, 'superpixel_resolution_log');
if (empty($targetCols)) {
  fwrite(STDERR, "Could not DESCRIBE $DST_DB.superpixel_resolution_log\n");
  exit(1);
}

// Fetch last 5 raw events for this client
$sql = "SELECT id, client_name, uuid, event_timestamp, stored_at, payload, payload_sha256
        FROM `$SRC_DB`.`raw_events`
        WHERE client_name = ?
        ORDER BY id DESC
        LIMIT 5";
$stmt = $src->prepare($sql);
$stmt->bind_param('s', $CLIENT);
$stmt->execute();
$res = $stmt->get_result();

$inserted = 0; $skipped = 0; $failed = 0;

while ($row = $res->fetch_assoc()) {
  $payload = $row['payload'];
  $event = json_decode($payload, true);
  if (!is_array($event)) {
    // some pipelines store event body at top-level directly already
    // if it's a wrapper with 'events', take first
    $ev = json_decode($payload, true);
    if (is_array($ev) && isset($ev['events'][0])) {
      $event = $ev['events'][0];
    } else {
      $failed++;
      fwrite(STDERR, "Row {$row['id']}: payload not JSON object\n");
      continue;
    }
  }

  // Resolution section (might be JSON string)
  $resBlock = $event['resolution'] ?? [];
  if (is_string($resBlock)) {
    $tmp = json_decode($resBlock, true);
    if (is_array($tmp)) $resBlock = $tmp;
  }

  // Event data section (might be JSON string)
  $ed = $event['event_data'] ?? [];
  if (is_string($ed)) {
    $tmp = json_decode($ed, true);
    if (is_array($tmp)) $ed = $tmp;
  }

  // Build insert_data similar to pixel_import.php
  $insert = [];

  // Basic event fields
  $insert['pixel_id']          = (string)($event['pixel_id']   ?? '');
  $insert['hem_sha256']        = (string)($event['hem_sha256'] ?? '');
  $insert['event_type']        = (string)($event['event_type'] ?? '');
  $insert['ip_address']        = (string)($event['ip_address'] ?? '');
  $insert['referrer_url']      = (string)($event['referrer_url'] ?? '');
  $insert['url']               = (string)($ed['url'] ?? '');
  $insert['referrer']          = array_key_exists('referrer',$ed) ? (string)$ed['referrer'] : '';
  $insert['title']             = (string)($ed['title'] ?? '');
  $insert['percentage']        = (string)($ed['percentage'] ?? '');
  $insert['element']           = isset($ed['element']) ? (is_string($ed['element']) ? $ed['element'] : json_encode($ed['element'])) : '';

  // Resolution fields (UPPERCASE → lowercase)
  $map = [
    'UUID' => 'uuid',
    'FIRST_NAME' => 'first_name',
    'LAST_NAME' => 'last_name',
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
    'INCOME_RANGE' => 'income_range',
    'NET_WORTH' => 'net_worth',

    'DIRECT_NUMBER' => 'direct_number',
    'DIRECT_NUMBER_DNC' => 'direct_number_dnc',
    'MOBILE_PHONE' => 'mobile_phone',
    'MOBILE_PHONE_DNC' => 'mobile_phone_dnc',
    'PERSONAL_PHONE' => 'personal_phone',
    'PERSONAL_PHONE_DNC' => 'personal_phone_dnc',
    'PERSONAL_EMAILS' => 'personal_emails',
    'BUSINESS_EMAIL' => 'business_email',
    'DEEP_VERIFIED_EMAILS' => 'deep_verified_emails',
    'SHA256_PERSONAL_EMAIL' => 'sha256_personal_email',
    'SHA256_BUSINESS_EMAIL' => 'sha256_business_email',

    'COMPANY_ADDRESS' => 'company_address',
    'COMPANY_NAME' => 'company_name',
    'COMPANY_CITY' => 'company_city',
    'COMPANY_STATE' => 'company_state',
    'COMPANY_ZIP' => 'company_zip',
    'COMPANY_DESCRIPTION' => 'company_description',
    'COMPANY_DOMAIN' => 'company_domain',
    'COMPANY_EMPLOYEE_COUNT' => 'company_employee_count',
    'COMPANY_LINKEDIN_URL' => 'company_linkedin_url',
    'COMPANY_REVENUE' => 'company_revenue',
    'COMPANY_SIC' => 'company_sic',
    'COMPANY_NAICS' => 'company_naics',
    'COMPANY_INDUSTRY' => 'company_industry',
    'COMPANY_PHONE' => 'company_phone',

    'JOB_TITLE' => 'job_title',
    'HEADLINE' => 'headline',
    'DEPARTMENT' => 'department',
    'SENIORITY_LEVEL' => 'seniority_level',
    'INFERRED_YEARS_EXPERIENCE' => 'inferred_years_experience',
    'COMPANY_NAME_HISTORY' => 'company_name_history',
    'JOB_TITLE_HISTORY' => 'job_title_history',
    'EDUCATION_HISTORY' => 'education_history',

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

    'VALID_PHONES' => 'valid_phones',
  ];
  foreach ($map as $k => $col) {
    if (array_key_exists($k, $resBlock)) {
      $v = $resBlock[$k];
      if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_SLASHES);
      $insert[$col] = (string)$v;
    }
  }

  // Normalize timestamps
  // - raw_events.event_timestamp (string) or event['event_timestamp'] → event_timestamp (DATETIME)
  $evtRaw = $event['event_timestamp'] ?? $row['event_timestamp'] ?? null;
  $createdAt = $row['stored_at'] ?? null;

  $insert['event_timestamp']     = norm_dt($evtRaw, $createdAt ? date('Y-m-d H:i:s', strtotime($createdAt)) : null);
  $insert['activity_start_date'] = norm_dt($event['activity_start_date'] ?? null, null);
  $insert['activity_end_date']   = norm_dt($event['activity_end_date'] ?? null, null);
  $insert['timestamp']           = norm_dt($ed['timestamp'] ?? null, null); // event_data.timestamp
  // created_at → use raw_events.stored_at if target table has that column
  $createdNorm = $createdAt ? date('Y-m-d H:i:s', strtotime($createdAt)) : null;
  if (isset($targetCols['created_at'])) {
    $insert['created_at'] = $createdNorm;
  }

  // Keep only columns that exist in destination table
  $finalCols = [];
  foreach ($insert as $col => $val) {
    if (isset($targetCols[$col])) {
      $finalCols[$col] = $val;
    }
  }

  // Ensure required columns present
  if (!isset($finalCols['uuid'])) {
    $finalCols['uuid'] = (string)($row['uuid'] ?? ($resBlock['UUID'] ?? ''));
  }
  if (isset($targetCols['event_type']) && !isset($finalCols['event_type'])) {
    $finalCols['event_type'] = (string)($event['event_type'] ?? '');
  }

  // Coerce types to avoid 1292 for DATETIME and numeric columns
  foreach ($finalCols as $c => $v) {
    $type = $targetCols[$c];
    if (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) {
      $finalCols[$c] = norm_dt($v, null); // NULL if not valid
    } elseif (preg_match('/int|decimal|float|double|bigint|smallint|mediumint|tinyint/', $type)) {
      $vv = trim((string)$v);
      $finalCols[$c] = ($vv === '' || !preg_match('/^-?\d+(\.\d+)?$/', $vv)) ? null : $vv;
    } else {
      // leave as string
      $finalCols[$c] = (string)$v;
    }
  }

  // Optional duplicate guard: if table has both uuid & event_timestamp columns
  $isDup = false;
  if (isset($targetCols['uuid']) && isset($targetCols['event_timestamp']) && !empty($finalCols['uuid']) && !empty($finalCols['event_timestamp'])) {
    $q = $dst->prepare("SELECT id FROM `$DST_DB`.`superpixel_resolution_log` WHERE uuid = ? AND event_timestamp = ? LIMIT 1");
    $q->bind_param('ss', $finalCols['uuid'], $finalCols['event_timestamp']);
    $q->execute();
    $dupRes = $q->get_result();
    if ($dupRes && $dupRes->fetch_assoc()) $isDup = true;
    $q->close();
  }
  if ($isDup) { $skipped++; continue; }

  // Build INSERT statement (no updates to superpixel_emails here)
  $cols = [];
  $vals = [];
  foreach ($finalCols as $c => $v) {
    $cols[] = "`$c`";
    if ($v === null || $v === '') {
      // For strings that are truly empty, insert empty string; for DATETIME/numeric we converted to null above
      // Decide: if column is datetime/numeric, null; else empty string
      $type = $targetCols[$c];
      if (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false ||
          preg_match('/int|decimal|float|double|bigint|smallint|mediumint|tinyint/', $type)) {
        $vals[] = "NULL";
      } else {
        $vals[] = "''";
      }
    } else {
      $vals[] = "'" . esc($dst, $v) . "'";
    }
  }
  $sqlIns = "INSERT INTO `$DST_DB`.`superpixel_resolution_log` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
  if (!$dst->query($sqlIns)) {
    $failed++;
    fwrite(STDERR, "Insert failed for raw_events.id {$row['id']}: " . $dst->error . PHP_EOL);
  } else {
    $inserted++;
  }
}

$res->free();
$stmt->close();
$src->close();
$dst->close();

echo json_encode([
  'status'   => 'ok',
  'inserted' => $inserted,
  'skipped'  => $skipped,
  'failed'   => $failed
], JSON_PRETTY_PRINT), PHP_EOL;
