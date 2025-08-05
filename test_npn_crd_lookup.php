<?php
/**
 * Test NPN/CRD lookup system with normalized match_emails table
 */

// Database configuration
$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';

echo "=== TESTING NPN/CRD LOOKUP WITH NORMALIZED MATCH_EMAILS ===\n\n";

// Connect to accupoint_solutions to get test emails
$match_mysqli = new mysqli($host, $user, $pass, 'accupoint_solutions');
if ($match_mysqli->connect_error) {
    die("Connection failed: " . $match_mysqli->connect_error . "\n");
}

// Step 1: Get some sample emails with NPNs from match_emails
echo "1. GETTING SAMPLE EMAILS WITH NPNs FROM MATCH_EMAILS\n";
echo "=====================================================\n";

$sample_query = "SELECT Email, CRD, NPN, AgentID FROM match_emails 
                 WHERE Email IS NOT NULL AND Email != '' 
                   AND NPN IS NOT NULL AND NPN != ''
                 LIMIT 5";

$result = $match_mysqli->query($sample_query);
$test_emails = [];

if ($result && $result->num_rows > 0) {
    echo "Found sample emails with NPNs:\n";
    while ($row = $result->fetch_assoc()) {
        $test_emails[] = $row;
        echo "  Email: {$row['Email']} | NPN: {$row['NPN']} | CRD: {$row['CRD']}\n";
    }
} else {
    die("❌ No emails with NPNs found in match_emails table!\n");
}

// Step 2: Test the lookup function
echo "\n2. TESTING processVisitorEmails FUNCTION\n";
echo "=========================================\n";

if (file_exists('process_visitor_emails.php')) {
    require_once 'process_visitor_emails.php';
    
    // Connect to AcquireUp to test with
    $client_db = 'AcquireUp';
    
    foreach ($test_emails as $i => $email_data) {
        echo "\nTest " . ($i + 1) . ": {$email_data['Email']}\n";
        echo "Expected - NPN: {$email_data['NPN']}, CRD: {$email_data['CRD']}\n";
        
        // Insert test email into superpixel_emails temporarily
        $test_uuid = 'test-uuid-' . time() . '-' . $i;
        
        $client_mysqli = new mysqli($host, $user, $pass, $client_db);
        if (!$client_mysqli->connect_error) {
            // Insert test email
            $insert_sql = "INSERT IGNORE INTO superpixel_emails (uuid, email, email_type, source_column) 
                          VALUES (?, ?, 'business', 'test')";
            $stmt = $client_mysqli->prepare($insert_sql);
            $stmt->bind_param("ss", $test_uuid, $email_data['Email']);
            $stmt->execute();
            $stmt->close();
            
            // Test the lookup
            $result = processVisitorEmails($client_db, $test_uuid, false, true);
            
            echo "Actual   - NPN: " . ($result['npn'] ?: 'NULL') . ", CRD: " . ($result['crd'] ?: 'NULL') . "\n";
            
            // Check if match was successful
            $npn_match = ($result['npn'] == $email_data['NPN']);
            $crd_match = ($result['crd'] == $email_data['CRD']);
            
            if ($npn_match && $crd_match) {
                echo "✅ PERFECT MATCH!\n";
            } elseif ($npn_match || $crd_match) {
                echo "⚠️  PARTIAL MATCH\n";
            } else {
                echo "❌ NO MATCH\n";
            }
            
            // Cleanup
            $cleanup_sql = "DELETE FROM superpixel_emails WHERE uuid = ?";
            $stmt = $client_mysqli->prepare($cleanup_sql);
            $stmt->bind_param("s", $test_uuid);
            $stmt->execute();
            $stmt->close();
            
            $client_mysqli->close();
        }
        
        if ($i >= 2) break; // Test first 3 emails only
    }
} else {
    echo "❌ process_visitor_emails.php not found\n";
}

// Step 3: Test direct match_emails lookup
echo "\n3. TESTING DIRECT MATCH_EMAILS LOOKUP\n";
echo "======================================\n";

$test_email = $test_emails[0]['Email'];
echo "Testing direct lookup for: $test_email\n";

$direct_query = "SELECT Email, CRD, NPN, AgentID FROM match_emails 
                 WHERE Email = ? 
                 ORDER BY NPN IS NOT NULL DESC, CRD IS NOT NULL DESC 
                 LIMIT 1";

$stmt = $match_mysqli->prepare($direct_query);
$stmt->bind_param("s", $test_email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo "✅ Direct lookup successful:\n";
    echo "  Email: {$row['Email']}\n";
    echo "  NPN: {$row['NPN']}\n";
    echo "  CRD: {$row['CRD']}\n";
    echo "  AgentID: {$row['AgentID']}\n";
} else {
    echo "❌ Direct lookup failed for $test_email\n";
}

$stmt->close();
$match_mysqli->close();

echo "\n=== TEST COMPLETE ===\n";
echo "This shows whether the NPN/CRD lookup system is working with your normalized match_emails table.\n";
?> 