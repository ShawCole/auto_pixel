<?php
// backfill_master.php
// USAGE: sudo php backfill_master.php

$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';
$client_db = 'accupoint_solutions_new';

array_map('unlink', glob("backfill_status_*.json"));
file_put_contents('backfill_matches.log', '');

$conn = new mysqli($host, $user, $pass, $client_db);
$q = "SELECT COUNT(*) as count FROM superpixel_visitors WHERE first_name != '' AND last_name != ''";
$total_rows = $conn->query($q)->fetch_assoc()['count'];
$conn->close();

$num_workers = 5; 
$chunk_size = ceil($total_rows / $num_workers);

echo "\n===============================================\n";
echo "LAUNCHING $num_workers WORKERS FOR $total_rows VISITORS\n";
echo "===============================================\n";

for ($i = 1; $i <= $num_workers; $i++) {
    $offset = ($i - 1) * $chunk_size;
    // Added ORDER BY in worker query to stabilize offsets
    $cmd = "php backfill_worker.php $i $offset $chunk_size > /dev/null 2>&1 &";
    exec($cmd);
    echo "Launched Worker $i (Offset: $offset, Limit: $chunk_size)\n";
}
sleep(1); 

echo "\nStarting Dashboard...\n";

$running = true;
$last_match_read_pos = 0;
$start_time = time();

while ($running) {
    // 1. Read Status
    $total_processed = 0;
    $total_matches = 0;
    $workers_done = 0;
    
    for ($i = 1; $i <= $num_workers; $i++) {
        $file = "backfill_status_{$i}.json";
        if (file_exists($file)) {
            $json = json_decode(file_get_contents($file), true);
            if ($json) {
                $total_processed += $json['processed'];
                $total_matches += $json['matches'];
                if ($json['status'] === 'done') $workers_done++;
            }
        }
    }

    // 2. Calculate Stats
    $elapsed = time() - $start_time;
    $duration = gmdate("H:i:s", $elapsed); // Format 00:00:00
    if ($elapsed < 1) $elapsed = 1;
    $speed = round($total_processed / $elapsed);
    $percent = ($total_rows > 0) ? round(($total_processed / $total_rows) * 100, 1) : 0;
    
    // 3. Print Matches
    clearstatcache();
    $flog = fopen('backfill_matches.log', 'r');
    fseek($flog, $last_match_read_pos);
    while ($line = fgets($flog)) {
        $m = json_decode($line, true);
        if ($m) {
             $bm = $m['match'];
             $licenses = [];
             if (!empty($bm['npn'])) $licenses[] = "NPN: {$bm['npn']}";
             if (!empty($bm['crd'])) $licenses[] = "CRD: {$bm['crd']}";
             $license_str = empty($licenses) ? "" : " (" . implode(" | ", $licenses) . ")";

             echo "\r\033[K"; 
             echo "--------------------------------------------------\n";
             echo "[MATCH] UUID: {$m['uuid']}\n";
             echo "   Client:  {$m['client_name']}\n";
             echo "   Master:  {$bm['master_name']}{$license_str}\n";
             echo "   Email:   {$bm['email']} (Score: {$bm['score']})\n";
             echo "   Reason:  {$bm['reason']}\n";
             echo "--------------------------------------------------\n";
        }
    }
    $last_match_read_pos = ftell($flog);
    fclose($flog);

    // 4. Status Bar (With Duration)
    $status_line = sprintf(
        "\r[STATUS] %d/%d (%s%%) | Speed: %d/s | Time: %s | Matches: %d | Workers: %d/%d Done   ", 
        $total_processed, 
        $total_rows, 
        $percent,
        $speed,
        $duration,
        $total_matches, 
        $workers_done, 
        $num_workers
    );
    echo $status_line;

    if ($workers_done >= $num_workers) {
        $running = false;
    }
    
    usleep(500000); 
}

echo "\n\nALL WORKERS FINISHED.\n";
array_map('unlink', glob("backfill_status_*.json"));
?>
