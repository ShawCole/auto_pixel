<?php
// Debug script to test database query

$mysqli = new mysqli('34.31.66.104', 'root', 'AccuPoint01!');
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error);
}
echo "Connection successful\n";

$sql = "SELECT TABLE_SCHEMA as db_name
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', 'pixel', 'template')
          AND TABLE_NAME IN ('superpixel_resolution_log', 'superpixel_visitors')
        GROUP BY TABLE_SCHEMA
        HAVING COUNT(CASE WHEN TABLE_NAME = 'superpixel_resolution_log' THEN 1 END) > 0
           AND COUNT(CASE WHEN TABLE_NAME = 'superpixel_visitors' THEN 1 END) > 0
        ORDER BY TABLE_SCHEMA
        LIMIT 5";

echo "Running query...\n";
$result = $mysqli->query($sql);

if (!$result) {
    echo 'Query failed: ' . $mysqli->error . "\n";
} else {
    echo 'Query successful. Found ' . $result->num_rows . " databases:\n";
    while ($row = $result->fetch_assoc()) {
        echo '- ' . $row['db_name'] . "\n";
    }
}

$mysqli->close();
echo "Debug complete.\n"; 