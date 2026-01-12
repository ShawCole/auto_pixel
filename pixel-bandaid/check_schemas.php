<?php
$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';

echo "--- DB: accupoint_solutions ---\n";
$m = new mysqli($host, $user, $pass, 'accupoint_solutions');
$res = $m->query("DESCRIBE match_emails_v2");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
$m->close();

echo "\n--- DB: VettaFi ---\n";
$v = new mysqli($host, $user, $pass, 'VettaFi');
$res = $v->query("DESCRIBE superpixel_visitors");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
$v->close();
?>