<?php
$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';
$db = 'VettaFi';

$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

echo "Tables in $db:\n";
$result = $mysqli->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    echo $row[0] . "\n";
}

$mysqli->close();
?>