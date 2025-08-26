<?php
date_default_timezone_set('UTC');

$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';

function q($s) { return "'".str_replace("'", "\\'", $s)."'"; }

$mx = new mysqli($host, $user, $pass, 'pixel');
if ($mx->connect_error) { fwrite(STDERR, "DB connect fail: ".$mx->connect_error."\n"); exit(1); }

// fetch a batch of unprocessed rows
$sql = "
SELECT r.id, r.client_name, r.payload
FROM pixel.raw_events r
LEFT JOIN pixel.raw_events_processing p ON p.id = r.id
WHERE p.processed_at IS NULL
ORDER BY r.id ASC
LIMIT 200";
$res = $mx->query($sql);
if (!$res) { fwrite(STDERR, "Select error: ".$mx->error."\n"); exit(1); }

$processed = 0;
while ($row = $res->fetch_assoc()) {
  $id = (int)$row['id'];
  $client = $row['client_name'];
  $payload = $row['payload'];

  // POST to the same webhook with client parameter
  $url = "https://hook.thynkdata.com/pixel_import.php?client=".rawurlencode($client);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err || $code !== 200) {
    $e = "HTTP $code ".($err ?: $body);
    $mx->query("INSERT INTO pixel.raw_events_processing (id, attempts, last_error)
                VALUES ($id, 1, ".q(substr($e,0,2000)).")
                ON DUPLICATE KEY UPDATE attempts=attempts+1, last_error=".q(substr($e,0,2000)));
  } else {
    $mx->query("INSERT INTO pixel.raw_events_processing (id, processed_at, attempts, last_error)
                VALUES ($id, NOW(), 1, NULL)
                ON DUPLICATE KEY UPDATE processed_at=NOW(), last_error=NULL, attempts=attempts+1");
    $processed++;
  }
}
echo "Processed: $processed\n";
