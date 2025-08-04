<?php
/**
 * Process visitor emails - lookup NPN/CRD
 * In the hybrid approach, database triggers handle email parsing into superpixel_emails
 * This script focuses on NPN/CRD lookup but can also parse emails if needed
 */

function processVisitorEmails($client_db, $uuid, $parse_emails = true, $debug = false) {
    // Database configuration (consistent with other scripts)
    $host = '34.31.66.104';
    $user = 'root';
    $pass = 'AccuPoint01!';
    
    $results = [
        'emails_parsed' => 0,
        'emails_found' => 0,
        'npn_found' => false,
        'crd_found' => false,
        'npn' => null,
        'crd' => null
    ];
    
    // Connect to client database
    $client_mysqli = new mysqli(
        $host,
        $user,
        $pass,
        $client_db
    );
    
    if ($client_mysqli->connect_error) {
        if ($debug) echo "Failed to connect to client database: " . $client_mysqli->connect_error . "\n";
        return $results;
    }
    
    // First, check if emails are already in superpixel_emails (from triggers)
    $email_check = $client_mysqli->prepare("SELECT COUNT(*) as count FROM superpixel_emails WHERE uuid = ?");
    $email_check->bind_param("s", $uuid);
    $email_check->execute();
    $check_result = $email_check->get_result();
    $existing_count = $check_result->fetch_assoc()['count'];
    $email_check->close();
    
    if ($debug) echo "Found $existing_count existing emails for UUID: $uuid\n";
    
    // If no emails exist and parse_emails is true, parse them from the source tables
    if ($existing_count == 0 && $parse_emails) {
        if ($debug) echo "No emails found in superpixel_emails, parsing from source tables...\n";
        
        // Get visitor's emails from both tables
        $email_sources = [];
        
        // Get from visitors table
        $visitor_query = "SELECT business_email, personal_emails, deep_verified_emails 
                          FROM superpixel_visitors 
                          WHERE uuid = ? LIMIT 1";
        
        $stmt = $client_mysqli->prepare($visitor_query);
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['business_email'])) {
                $email_sources[] = ['emails' => $row['business_email'], 'type' => 'business'];
            }
            if (!empty($row['personal_emails'])) {
                $email_sources[] = ['emails' => $row['personal_emails'], 'type' => 'personal'];
            }
            if (!empty($row['deep_verified_emails'])) {
                $email_sources[] = ['emails' => $row['deep_verified_emails'], 'type' => 'deep_verified'];
            }
        }
        $stmt->close();
        
        // Parse and store individual emails
        foreach ($email_sources as $source) {
            $emails = explode(',', $source['emails']);
            foreach ($emails as $email) {
                $email = trim($email);
                // Basic email validation
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Insert into superpixel_emails
                    $insert_query = "INSERT IGNORE INTO superpixel_emails 
                                    (uuid, email, email_type, source_column) 
                                    VALUES (?, ?, ?, ?)";
                    
                    $stmt = $client_mysqli->prepare($insert_query);
                    $source_col = $source['type'] . '_email' . ($source['type'] !== 'business' ? 's' : '');
                    $stmt->bind_param("ssss", $uuid, $email, $source['type'], $source_col);
                    
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $results['emails_parsed']++;
                    }
                    $stmt->close();
                }
            }
        }
        
        if ($debug && $results['emails_parsed'] > 0) {
            echo "Parsed " . $results['emails_parsed'] . " new emails\n";
        }
    }
    
    // Now get all emails from superpixel_emails for NPN/CRD lookup
    $email_query = "SELECT DISTINCT email FROM superpixel_emails WHERE uuid = ?";
    $stmt = $client_mysqli->prepare($email_query);
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $all_emails = [];
    while ($row = $result->fetch_assoc()) {
        $all_emails[] = $row['email'];
    }
    $stmt->close();
    
    $results['emails_found'] = count($all_emails);
    if ($debug) echo "Total emails for lookup: " . $results['emails_found'] . "\n";
    
    // Perform NPN/CRD lookup from match_emails
    if (!empty($all_emails)) {
        // Connect to accupoint_solutions database
        $match_mysqli = new mysqli(
            $host,
            $user,
            $pass,
            'accupoint_solutions'
        );
        
        if (!$match_mysqli->connect_error) {
            // Build IN clause for all emails
            $placeholders = str_repeat('?,', count($all_emails) - 1) . '?';
            
            // STEP 1: Direct email lookup
            $lookup_query = "SELECT Email, CRD, NPN, AgentID 
                            FROM match_emails 
                            WHERE Email IN ($placeholders)
                            ORDER BY NPN DESC, CRD DESC
                            LIMIT 1";
            
            $stmt = $match_mysqli->prepare($lookup_query);
            $stmt->bind_param(str_repeat('s', count($all_emails)), ...$all_emails);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $results['crd'] = $row['CRD'];
                $results['npn'] = $row['NPN'];
                $agentId = $row['AgentID'];
                
                if ($debug) echo "Direct match found - CRD: {$row['CRD']}, NPN: {$row['NPN']}\n";
                
                // STEP 2: If we have CRD but no NPN, look for NPN using CRD
                // Note: After running normalize_match_emails.php, this should rarely be needed
                // as NPNs are propagated to all rows with the same CRD
                if (!empty($results['crd']) && empty($results['npn'])) {
                    $crd_query = "SELECT NPN FROM match_emails 
                                 WHERE CRD = ? AND NPN IS NOT NULL 
                                 LIMIT 1";
                    
                    $stmt2 = $match_mysqli->prepare($crd_query);
                    $stmt2->bind_param("s", $results['crd']);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    
                    if ($row2 = $result2->fetch_assoc()) {
                        $results['npn'] = $row2['NPN'];
                        if ($debug) echo "Found NPN via CRD lookup: {$row2['NPN']}\n";
                    }
                    $stmt2->close();
                }
                
                // STEP 3: If still no NPN but we have AgentID, try that
                // This is useful for emails without CRD but with AgentID
                if (empty($results['npn']) && !empty($agentId)) {
                    $agent_query = "SELECT NPN FROM match_emails 
                                   WHERE AgentID = ? AND NPN IS NOT NULL 
                                   LIMIT 1";
                    
                    $stmt3 = $match_mysqli->prepare($agent_query);
                    $stmt3->bind_param("s", $agentId);
                    $stmt3->execute();
                    $result3 = $stmt3->get_result();
                    
                    if ($row3 = $result3->fetch_assoc()) {
                        $results['npn'] = $row3['NPN'];
                        if ($debug) echo "Found NPN via AgentID lookup: {$row3['NPN']}\n";
                    }
                    $stmt3->close();
                }
            }
            $stmt->close();
            
            // Update visitor and resolution log tables if we found NPN/CRD
            if (!empty($results['npn']) || !empty($results['crd'])) {
                $results['npn_found'] = !empty($results['npn']);
                $results['crd_found'] = !empty($results['crd']);
                
                // Update visitors table
                $update_query = "UPDATE superpixel_visitors 
                                SET npn = COALESCE(npn, ?), 
                                    crd = COALESCE(crd, ?) 
                                WHERE uuid = ?";
                
                $stmt = $client_mysqli->prepare($update_query);
                $stmt->bind_param("sss", $results['npn'], $results['crd'], $uuid);
                $stmt->execute();
                $stmt->close();
                
                // Update resolution log
                $update_query = "UPDATE superpixel_resolution_log 
                                SET npn = COALESCE(npn, ?), 
                                    crd = COALESCE(crd, ?) 
                                WHERE uuid = ?";
                
                $stmt = $client_mysqli->prepare($update_query);
                $stmt->bind_param("sss", $results['npn'], $results['crd'], $uuid);
                $stmt->execute();
                $stmt->close();
                
                if ($debug) echo "Updated tables with NPN: {$results['npn']}, CRD: {$results['crd']}\n";
            }
            
            $match_mysqli->close();
        } else {
            if ($debug) echo "Failed to connect to accupoint_solutions database\n";
        }
    }
    
    $client_mysqli->close();
    
    return $results;
}

// If called directly from command line
if (php_sapi_name() === 'cli' && isset($argv[1]) && isset($argv[2])) {
    $client_db = $argv[1];
    $uuid = $argv[2];
    $parse_emails = !isset($argv[3]) || $argv[3] !== 'lookup-only';
    $debug = isset($argv[3]) && $argv[3] === 'debug';
    
    echo "Processing emails for client: $client_db, UUID: $uuid\n";
    $results = processVisitorEmails($client_db, $uuid, $parse_emails, $debug);
    
    echo "Results:\n";
    echo "- Emails found: " . $results['emails_found'] . "\n";
    echo "- Emails parsed: " . $results['emails_parsed'] . "\n";
    echo "- NPN found: " . ($results['npn_found'] ? 'Yes' : 'No') . "\n";
    echo "- CRD found: " . ($results['crd_found'] ? 'Yes' : 'No') . "\n";
    if ($results['npn']) echo "- NPN: " . $results['npn'] . "\n";
    if ($results['crd']) echo "- CRD: " . $results['crd'] . "\n";
}
?> 