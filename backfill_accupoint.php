<?php
// backfill_accupoint.php
// FEATURES: Persistent DB Connections + Real-time Dashboard + Smart NPN/CRD Display

require_once 'process_visitor_emails.php';
set_time_limit(0);

$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';
$client_db = 'accupoint_solutions_new';

// --- OPEN PERSISTENT CONNECTIONS ---
echo "Establishing Persistent DB Connections... ";
$mysqli_client = new mysqli($host, $user, $pass, $client_db);
$mysqli_master = new mysqli($host, $user, $pass, 'accupoint_solutions');

if ($mysqli_client->connect_error || $mysqli_master->connect_error) {
    die("DB Connect Error");
}
echo "Done.\n";

// --- HEADER ---
echo "\n========================================\n";
echo "STARTING BACKFILL: $client_db\n";
echo "========================================\n\n";

// 1. COUNTS
echo "Fetching visitor and email counts...\n";
$q_visitors = "SELECT COUNT(*) as count FROM $client_db.superpixel_visitors WHERE first_name != '' AND last_name != ''";
$total_visitors = $mysqli_client->query($q_visitors)->fetch_assoc()['count'];

$q_emails = "SELECT COUNT(*) as count FROM $client_db.superpixel_emails e 
             JOIN $client_db.superpixel_visitors v ON e.uuid = v.uuid 
             WHERE v.first_name != '' AND v.last_name != ''";
$total_emails = $mysqli_client->query($q_emails)->fetch_assoc()['count'];

echo "Found $total_visitors visitors and approx $total_emails emails.\n\n";

// 2. PROCESSING
$query = "SELECT uuid FROM $client_db.superpixel_visitors WHERE first_name != '' AND last_name != ''";
$result = $mysqli_client->query($query);

$cur_visitor = 0;
$cur_email = 0;
$matches = 0;
$first_render = true;

$render_dashboard = function($email, $type, $name, $reason, $uuid) use (&$cur_visitor, $total_visitors, &$cur_email, $total_emails, &$first_render) {
    $cur_email++;
    if (!$first_render) echo "\033[8A"; 
    $first_render = false;

    printf("\033[KProcessing Emails: %d / %d\n", $cur_email, $total_emails);
    printf("\033[KProcessing Visitors: %d / %d\n", $cur_visitor, $total_visitors);
    echo "\033[K--------------------------------------------------\n";
    echo "\033[KUUID: $uuid\n";
    printf("\033[K   Client:  %s\n", $name);
    printf("\033[K   Email:   %s\n", $email);
    printf("\033[K   Type:    %s\n", $type);
    echo "\033[K--------------------------------------------------\n";
};

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cur_visitor++;
        
        $res = processVisitorEmails($client_db, $row['uuid'], true, $render_dashboard, $mysqli_client, $mysqli_master);

        if (!empty($res['best_match'])) {
            $matches++;
            $bm = $res['best_match'];
            
            // --- FORMAT LICENSE STRING ---
            $licenses = [];
            if (!empty($bm['npn'])) $licenses[] = "NPN: {$bm['npn']}";
            if (!empty($bm['crd'])) $licenses[] = "CRD: {$bm['crd']}";
            
            $license_str = empty($licenses) ? "" : " (" . implode(" | ", $licenses) . ")";
            
            // --- CLEAR DASHBOARD ---
            echo "\033[8A\033[J"; 
            
            // --- PRINT MATCH LOG ---
            echo "--------------------------------------------------\n";
            echo "[MATCH] UUID: {$row['uuid']}\n";
            echo "   Client:  {$bm['master_name']}\n"; 
            echo "   Master:  {$bm['master_name']}{$license_str}\n";
            echo "   Email:   {$bm['email']} (Score: {$bm['score']})\n";
            echo "   Reason:  {$bm['reason']}\n";
            echo "--------------------------------------------------\n";
            
            $first_render = true; 
        }
    }
    echo "\n\nDONE! Matches: $matches\n";
}

$mysqli_client->close();
$mysqli_master->close();
?>
