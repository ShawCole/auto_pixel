<?php
// webhook_debug.php - Comprehensive webhook debugger
// This captures EVERYTHING about incoming requests

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$logFile = '/var/www/hook.thynkdata.com/webhook_debug_full.log';

// Capture all request details
$requestData = [
    'timestamp' => date('c'),
    'client_param' => $_GET['client'] ?? 'none',
    'method' => $_SERVER['REQUEST_METHOD'],
    'request_uri' => $_SERVER['REQUEST_URI'],
    'remote_addr' => $_SERVER['REMOTE_ADDR'],
    'http_x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'none',
    'http_x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? 'none',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'none',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'none',
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'none',
    'all_headers' => getallheaders(),
    'get_params' => $_GET,
    'post_params' => $_POST,
    'raw_body' => file_get_contents('php://input'),
    'server_vars' => $_SERVER
];

// Try to decode JSON body if present
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $decoded = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $requestData['decoded_json'] = $decoded;
    } else {
        $requestData['json_error'] = json_last_error_msg();
    }
}

// Log to file
file_put_contents($logFile, "\n========== NEW REQUEST ==========\n" . json_encode($requestData, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);

// Also log a simplified version
$simpleLog = sprintf(
    "[%s] %s request from %s (X-Forwarded-For: %s) - Client: %s - UA: %s\n",
    date('c'),
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REMOTE_ADDR'],
    $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'none',
    $_GET['client'] ?? 'none',
    substr($_SERVER['HTTP_USER_AGENT'] ?? 'none', 0, 50)
);
file_put_contents('/var/www/hook.thynkdata.com/webhook_simple.log', $simpleLog, FILE_APPEND | LOCK_EX);

// Always return success to see if SimpleAudience accepts it
$response = [
    'status' => 'success',
    'message' => 'Debug webhook received your request',
    'timestamp' => date('c'),
    'your_ip' => $_SERVER['REMOTE_ADDR'],
    'forwarded_ips' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'none',
    'method' => $_SERVER['REQUEST_METHOD'],
    'client' => $_GET['client'] ?? 'none'
];

// For GET requests, add more debug info
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $response['debug_info'] = [
        'note' => 'This is a debug webhook that always returns success',
        'your_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'none',
        'all_get_params' => $_GET
    ];
}

// Log the response we're sending back
file_put_contents($logFile, "\n--- RESPONSE SENT ---\n" . json_encode($response, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode($response);
?> 