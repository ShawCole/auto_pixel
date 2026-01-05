<?php
/**
 * smart_sync_v2.php
 * Location: /opt/auto-pixel/smart_sync_v2.php
 * 
 * DYNAMIC ENGINE:
 * 1. Reads 'enabled_headers' JSON from pixel_sheets (The Brain).
 * 2. Maps Sheet Headers -> DB Columns using the master $COLUMN_MAP.
 * 3. Handles Timezone conversion for 'datetime' types.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

// --- CONFIG ---
$DB_HOST = '34.26.61.148';
$DB_USER = 'root';
$DB_PASS = 'AccuPoint01!';
$CENTRAL_DB = 'pixel_v2';
$CREDENTIALS_PATH = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

$LIMITS = ['visitors' => 10000, 'events' => 50000, 'matched_professionals' => 10000];
$MAX_SHEETS_PER_RUN = 5;
$STAGGER_DELAY = 5;

// --- THE MASTER MAP (Matches 'available_columns' sheet_header) ---
$COLUMN_MAP = [
    // IDENTITY
    'UUID' => ['sql' => 'uuid'],
    'First Name' => ['sql' => 'first_name'],
    'Last Name' => ['sql' => 'last_name'],
    'Company' => ['sql' => 'company_name'],
    'Job Title' => ['sql' => 'job_title'],
    'Linkedin URL' => ['sql' => 'linkedin_url'], // Added
    
    // EMAILS
    'Best Email' => ['sql' => 'email_best'],
    'Email' => ['sql' => 'business_email'], // Event context
    'Business Email' => ['sql' => 'business_email'],
    'Personal Emails' => ['sql' => 'personal_emails'],
    
    // CONTACT
    'Phone' => ['sql' => 'mobile_phone'],
    'Address' => ['sql' => 'personal_address'],
    'City' => ['sql' => 'personal_city'],
    'State' => ['sql' => 'personal_state'],
    'Zip' => ['sql' => 'personal_zip'],
    
    // METRICS & TIME
    'First Seen' => ['sql' => 'first_seen_at', 'type' => 'dt'],
    'Last Seen' => ['sql' => 'last_seen_at', 'type' => 'dt'],
    'Timestamp' => ['sql' => 'event_timestamp', 'type' => 'dt'],
    'Event Count' => ['sql' => 'event_count'],
    
    // CONTEXT (Activity)
    'Last Visited URL' => ['sql' => 'last_url'],
    'Last Referrer' => ['sql' => 'last_referrer'],
    'URL' => ['sql' => 'url'],
    'Title' => ['sql' => 'title'],
    'Referrer' => ['sql' => 'referrer'],
    'Event Type' => ['sql' => 'event_type'],
    'IP Address' => ['sql' => 'ip_address'],
    
    // DIMENSIONS (New)
    'Screen Res' => ['sql' => 'screen_resolution'],
    'Viewport Size' => ['sql' => 'viewport_size'],
    
    // ATTRIBUTION (New)
    'UTM Source' => ['sql' => 'utm_source'],
    'UTM Medium' => ['sql' => 'utm_medium'],
    'UTM Campaign' => ['sql' => 'utm_campaign'],
    
    // COMPLIANCE / MATCHED PROS
    'NPN' => ['sql' => 'npn'],
    'CRD' => ['sql' => 'crd'],
    'Confidence Score' => ['sql' => 'confidence_score'],
    'Match Reason' => ['sql' => 'source'],
    'Matched At' => ['sql' => 'matched_at', 'type' => 'dt'],
    'Roles' => ['sql' => "CONCAT_WS(', ', IF(is_rr=1,'RR',NULL), IF(is_ia=1,'IA',NULL), IF(is_agent=1,'Agent',NULL))"],
    
    // DEMOGRAPHICS (Optional)
    'Income Range' => ['sql' => 'income_range'],
    'Net Worth' => ['sql' => 'net_worth'],
    'Homeowner' => ['sql' => 'homeowner']
];

// --- HELPERS ---
function formatDt($dateStr, $targetTzObj) {
    if (empty($dateStr) || $dateStr === '0000-00-00 00:00:00') return '';
    try {
        $dt = new DateTime($dateStr, new DateTimeZone('UTC'));
        $dt->setTimezone($targetTzObj);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) { return $dateStr; }
}

function getGoogleClient() {
    global $CREDENTIALS_PATH;
    $client = new Client();
    $client->setAuthConfig($CREDENTIALS_PATH);
    $client->setScopes([Sheets::SPREADSHEETS]);
    // $client->setSubject('scole@thynkdata.com');
    return $client;
}

// --- SYNC FUNCTION ---
function syncTab($mysqli, $sheetId, $service, $tzObj, $tabName, $config, $tableSQL) {
    global $COLUMN_MAP, $LIMITS;

    if (empty($config['enabled']) || !$config['enabled']) return;

    echo "    > Syncing $tabName...\n";

    $headers = $config['columns'] ?? [];
    if (empty($headers)) return;

    // Build Select
    $selectParts = [];
    $colTypes = [];

    foreach ($headers as $h) {
        if (isset($COLUMN_MAP[$h])) {
            $def = $COLUMN_MAP[$h];
            // We alias the column to the header name for clarity in fetch
            $selectParts[] = $def['sql'] . " AS `" . $h . "`"; 
            $colTypes[$h] = $def['type'] ?? 'string';
        } else {
            $selectParts[] = "NULL AS `" . $h . "`"; // Fallback
            $colTypes[$h] = 'string';
        }
    }

    $limit = $LIMITS[$tabName] ?? 10000;
    $sql = "SELECT " . implode(', ', $selectParts) . " $tableSQL LIMIT $limit";

    try {
        $res = $mysqli->query($sql);
        if (!$res) throw new Exception($mysqli->error);

        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $row = [];
            // Map data to headers in exact order
            foreach ($headers as $h) {
                $val = $r[$h] ?? '';
                if (($colTypes[$h] ?? '') === 'dt') {
                    $val = formatDt($val, $tzObj);
                }
                $row[] = $val;
            }
            $rows[] = $row;
        }

        // Map Tab IDs
        $sheetTabs = ['visitors' => 'Visitors', 'events' => 'Events', 'matched_professionals' => 'Matched Professionals'];
        $targetSheet = $sheetTabs[$tabName] ?? ucfirst($tabName);

        // Update Google Sheet
        $data = array_merge([$headers], $rows);
        $service->spreadsheets_values->clear($sheetId, "$targetSheet!A:Z", new \Google\Service\Sheets\ClearValuesRequest());
        $body = new ValueRange(['values' => $data]);
        $service->spreadsheets_values->update($sheetId, "$targetSheet!A1", $body, ['valueInputOption' => 'RAW']);

    } catch (Exception $e) {
        echo "      ! Error syncing $tabName: " . $e->getMessage() . "\n";
    }
}

// --- MAIN EXECUTION ---
$cliClient = getopt("", ["client:"])['client'] ?? null;

$mysqliInfo = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $CENTRAL_DB);
$sql = "SELECT client_name, client_slug, sheet_id, display_timezone, enabled_headers 
        FROM pixel_sheets 
        WHERE sheet_id IS NOT NULL AND status='active' AND paused=0";

if ($cliClient) $sql .= " AND client_name = '" . $mysqliInfo->real_escape_string($cliClient) . "'";
$sql .= " ORDER BY last_sync_at ASC LIMIT $MAX_SHEETS_PER_RUN";

$sheets = $mysqliInfo->query($sql)->fetch_all(MYSQLI_ASSOC);
$client = getGoogleClient();
$service = new Sheets($client);

foreach ($sheets as $sheet) {
    echo "Syncing V2: {$sheet['client_name']}...\n";
    
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $sheet['client_slug']);
    if ($db->connect_error) { echo "  - DB Connect Failed.\n"; continue; }

    // Check V2 Table Existence
    if ($db->query("SHOW TABLES LIKE 'visitors'")->num_rows == 0) {
        echo "  - SKIPPING: V2 tables not found.\n"; $db->close(); continue;
    }

    // Parse Config
    $config = json_decode($sheet['enabled_headers'], true);
    if (!$config) { echo "  - Error: Invalid JSON config.\n"; $db->close(); continue; }

    try {
        // Timezone Setup
        $tzStr = $sheet['display_timezone'] ?: 'America/New_York';
        try { $tzObj = new DateTimeZone($tzStr); } 
        catch (Exception $e) { $tzObj = new DateTimeZone('America/New_York'); }

        // --- RUN SYNCS ---
        if (isset($config['visitors'])) {
            syncTab($db, $sheet['sheet_id'], $service, $tzObj, 'visitors', $config['visitors'], 
                "FROM visitors ORDER BY last_seen_at DESC");
        }

        if (isset($config['events'])) {
            syncTab($db, $sheet['sheet_id'], $service, $tzObj, 'events', $config['events'], 
                "FROM events ORDER BY event_timestamp DESC");
        }

        if (isset($config['matched_professionals']) && $config['matched_professionals']['enabled']) {
            // Check/Create Tab Logic
            try { $service->spreadsheets_values->get($sheet['sheet_id'], "Matched Professionals!A1"); } 
            catch (Exception $e) {
                $req = new Google\Service\Sheets\Request(['addSheet' => ['properties' => ['title' => 'Matched Professionals']]]);
                $service->spreadsheets->batchUpdate($sheet['sheet_id'], new Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => [$req]]));
            }
            
            // Join for Context
            syncTab($db, $sheet['sheet_id'], $service, $tzObj, 'matched_professionals', $config['matched_professionals'], 
                "FROM matched_professionals mp JOIN visitors v ON mp.visitor_uuid = v.uuid ORDER BY mp.matched_at DESC");
        }
        
        $mysqliInfo->query("UPDATE pixel_sheets SET last_sync_at = NOW() WHERE client_slug = '{$sheet['client_slug']}'");
        echo "  - Success.\n";

    } catch (Exception $e) {
        echo "  - Error: " . $e->getMessage() . "\n";
    }
    
    $db->close();
    sleep($STAGGER_DELAY);
}
$mysqliInfo->close();
?>
