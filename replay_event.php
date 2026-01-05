<?php
// replay_event.php
// Usage: php replay_event.php <PIXEL_ID> <CLIENT_NAME>

if ($argc < 3) {
    die("Usage: php replay_event.php <PIXEL_ID> <CLIENT_NAME>\nExample: php replay_event.php d69d4f47-0be8-4ec4-9721-ffb3eed4a1e0 VettaFi\n");
}

$pixelId = $argv[1];
$clientName = $argv[2];
$apiKey = 'sk_aaaEJaKJZEzw39WFBTLPrvdnPZa5CmjMybZWzED4lY'; // Your confirmed working key

echo "\n--- STARTING REPLAY FOR CLIENT: $clientName ---\n";

// 1. FETCH FROM API (Get most recent 1 page_view)
$apiUrl = "https://api.audiencelab.io/pixels/$pixelId?page=1&page_size=1&event_type=page_view";
echo "1. Fetching data from API: $apiUrl\n";

$opts = [
    "http" => [
        "method" => "GET",
        "header" => "X-Api-Key: $apiKey\r\n"
    ]
];
$context = stream_context_create($opts);
$response = file_get_contents($apiUrl, false, $context);

if (!$response) { die("Error: Failed to fetch from API.\n"); }

$data = json_decode($response, true);
if (empty($data['events'])) { die("Error: No events found in API response.\n"); }

// Get the first event found
$singleEvent = $data['events'][0];
echo "   > Found event with timestamp: " . $singleEvent['event_timestamp'] . "\n";

// 2. CONSTRUCT WEBHOOK PAYLOAD
// The API returns the exact object the webhook needs, we just wrap it in "events" array
$payload = json_encode(['events' => [$singleEvent]]);

// 3. POST TO WEBHOOK (Localhost)
$webhookUrl = "http://localhost/pixel_import.php?client=" . urlencode($clientName);
echo "2. Posting to Webhook: $webhookUrl\n";

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload)
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "3. Result: [HTTP $httpCode]\n";
echo "   > Response: $result\n";
echo "----------------------------------------\n";
?>
