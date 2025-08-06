<?php
// Raw webhook capture to diagnose AudienceLab's new data structure
// This will log the EXACT JSON being sent so we can see what changed

header('Content-Type: application/json');

// Log file for raw data
$logFile = __DIR__ . '/webhook_raw_capture.log';

// Get raw input
$rawInput = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');

// Log the raw input
$logEntry = "\n\n========== WEBHOOK CAPTURE: $timestamp ==========\n";
$logEntry .= "REQUEST METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
$logEntry .= "REQUEST URI: " . $_SERVER['REQUEST_URI'] . "\n";
$logEntry .= "QUERY STRING: " . ($_SERVER['QUERY_STRING'] ?? '') . "\n";
$logEntry .= "HEADERS:\n";
foreach (getallheaders() as $header => $value) {
    $logEntry .= "  $header: $value\n";
}
$logEntry .= "\nRAW BODY:\n$rawInput\n";

// Decode JSON to see structure
$data = json_decode($rawInput, true);
if ($data) {
    $logEntry .= "\nDECODED JSON STRUCTURE:\n";
    $logEntry .= json_encode($data, JSON_PRETTY_PRINT) . "\n";
    
    // Analyze the structure
    if (isset($data['events']) && is_array($data['events'])) {
        $logEntry .= "\nEVENT STRUCTURE ANALYSIS:\n";
        $logEntry .= "Number of events: " . count($data['events']) . "\n";
        
        if (count($data['events']) > 0) {
            $firstEvent = $data['events'][0];
            $logEntry .= "\nFIRST EVENT KEYS:\n";
            foreach (array_keys($firstEvent) as $key) {
                $value = $firstEvent[$key];
                $type = gettype($value);
                $sample = is_array($value) ? json_encode($value) : substr((string)$value, 0, 100);
                $logEntry .= "  - $key ($type): $sample\n";
            }
            
            // Check for nested structures
            $logEntry .= "\nNESTED STRUCTURES:\n";
            foreach ($firstEvent as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $logEntry .= "  $key contains: " . json_encode($value, JSON_PRETTY_PRINT) . "\n";
                }
            }
        }
    }
} else {
    $logEntry .= "\nFAILED TO DECODE JSON - Invalid format\n";
}

// Write to log file
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// Return success response
echo json_encode([
    'status' => 'captured',
    'timestamp' => $timestamp,
    'message' => 'Data logged to webhook_raw_capture.log'
]);
?> 