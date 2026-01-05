<?php
// backfill_worker.php
// USAGE: php backfill_worker.php [WORKER_ID] [MIN_ID] [MAX_ID]

require_once 'process_visitor_emails.php';
set_time_limit(0);

$worker_id = $argv[1];
$min_id    = (int)$argv[2];
$max_id    = (int)$argv[3];
$status_file = "backfill_status_{$worker_id}.json";

$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';
$client_db = 'accupoint_solutions_new';

$mysqli_client = new mysqli($host, $user, $pass, $client_db);
$mysqli_master = new mysqli($host, $user, $pass, 'accupoint_solutions');

// Fetch Batch by ID Range (Stable Partitioning)
// Note: 'superpixel_visitors' uses UUID as primary key, so we can't use numeric IDs easily.
// IF UUID is PK, we stick to LIMIT/OFFSET but force an ORDER BY to stabilize it.

// FIX: Adding ORDER BY uuid ensures stability for LIMIT/OFFSET
$offset = $min_id; // Reusing variable name for clarity with master script
$limit = $max_id;  // Reusing variable name

$query = "SELECT uuid, first_name, last_name, business_email, personal_emails, deep_verified_emails 
          FROM $client_db.superpixel_visitors 
          WHERE first_name != '' AND last_name != ''
          ORDER BY uuid ASC 
          LIMIT $offset, $limit";

$result = $mysqli_client->query($query);

$processed = 0;
$matches = 0;

function write_status($file, $data) {
    file_put_contents($file, json_encode($data));
}

// Initial Status
write_status($status_file, ['processed' => 0, 'matches' => 0, 'status' => 'running']);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $processed++;
        
        $res = processVisitorEmails(
            $client_db, 
            $row['uuid'], 
            true, 
            null, 
            $mysqli_client, 
            $mysqli_master, 
            $row
        );

        if (!empty($res['best_match'])) {
            $matches++;
            $bm = $res['best_match'];
            $log_entry = json_encode([
                'uuid' => $row['uuid'], 
                'client_name' => $row['first_name'] . ' ' . $row['last_name'],
                'match' => $bm
            ]) . PHP_EOL;
            file_put_contents('backfill_matches.log', $log_entry, FILE_APPEND | LOCK_EX);
        }

        if ($processed % 10 == 0) {
            write_status($status_file, ['processed' => $processed, 'matches' => $matches, 'status' => 'running']);
        }
    }
}

write_status($status_file, ['processed' => $processed, 'matches' => $matches, 'status' => 'done']);

$mysqli_client->close();
$mysqli_master->close();
?>
