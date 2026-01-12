<?php
/**
 * Process visitor emails - OPTIMIZED FOR PERSISTENT CONNECTIONS
 * Logic:
 * 1. Accepts existing DB connections (prevents handshake overhead in loops).
 * 2. Batches queries (Master DB lookup).
 * 3. Batches updates (Transaction).
 */

function processVisitorEmails($client_db, $uuid, $parse_emails = true, $progress_callback = null, $external_client_link = null, $external_master_link = null) {
    $host = '34.26.61.148';
    $user = 'root';
    $pass = 'AccuPoint01!';
    
    $results = ['emails_processed' => 0, 'best_match' => null];
    
    // --- OPTIMIZATION: USE EXISTING CONNECTION IF PROVIDED ---
    if ($external_client_link) {
        $client_mysqli = $external_client_link;
        // Ensure we are on the right DB (in case the link was used for another client)
        $client_mysqli->select_db($client_db);
    } else {
        $client_mysqli = new mysqli($host, $user, $pass, $client_db);
        if ($client_mysqli->connect_error) return $results;
    }

    // --- PHASE 1: PARSE EMAILS ---
    $visitor_first_name = '';
    $visitor_last_name = '';

    $stmt = $client_mysqli->prepare("SELECT first_name, last_name, business_email, personal_emails, deep_verified_emails FROM superpixel_visitors WHERE uuid = ? LIMIT 1");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $visitor_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($visitor_row) {
        $visitor_first_name = trim($visitor_row['first_name'] ?? '');
        $visitor_last_name = trim($visitor_row['last_name'] ?? '');
    }

    if ($parse_emails && $visitor_row) {
        $stmt_insert = $client_mysqli->prepare("INSERT IGNORE INTO superpixel_emails (uuid, email, first_name, last_name, email_type, source_column) VALUES (?, ?, ?, ?, ?, ?)");
        
        $sources = [
            ['emails' => $visitor_row['business_email'], 'type' => 'business'],
            ['emails' => $visitor_row['personal_emails'], 'type' => 'personal'],
            ['emails' => $visitor_row['deep_verified_emails'], 'type' => 'deep_verified']
        ];

        foreach ($sources as $source) {
            if (empty($source['emails'])) continue;
            $emails = explode(',', $source['emails']);
            foreach ($emails as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $col = $source['type'] . '_email' . ($source['type'] !== 'business' ? 's' : '');
                    $stmt_insert->bind_param("ssssss", $uuid, $email, $visitor_first_name, $visitor_last_name, $source['type'], $col);
                    $stmt_insert->execute();
                }
            }
        }
        $stmt_insert->close();
    }
    
    // --- PHASE 2: BATCH ENRICHMENT ---

    if (empty($visitor_first_name) || empty($visitor_last_name)) {
        // Only close if we opened it ourselves
        if (!$external_client_link) $client_mysqli->close();
        return $results;
    }

    $email_rows = [];
    $clean_emails = [];
    $result = $client_mysqli->query("SELECT id, email, email_type FROM superpixel_emails WHERE uuid = '$uuid'"); 
    while ($row = $result->fetch_assoc()) {
        $email_rows[] = $row;
        $clean_emails[] = $client_mysqli->real_escape_string(trim($row['email']));
    }

    $results['emails_processed'] = count($email_rows);
    $best_match = null; 

    if (!empty($email_rows)) {
        
        // --- OPTIMIZATION: USE EXISTING MASTER CONNECTION ---
        if ($external_master_link) {
            $match_mysqli = $external_master_link;
            // Ensure we ping to keep alive if script is long running
            if (!$match_mysqli->ping()) $match_mysqli->connect($host, $user, $pass, 'accupoint_solutions');
        } else {
            $match_mysqli = new mysqli($host, $user, $pass, 'accupoint_solutions');
        }
        
        if (!$match_mysqli->connect_error) {
            
            $email_list_sql = "'" . implode("','", $clean_emails) . "'";
            $batch_sql = "SELECT Email, NPN, CRD, NPN_Active, CRD_Active, first_name, last_name 
                          FROM match_emails_v2 
                          WHERE Email IN ($email_list_sql)";
            
            $master_map = [];
            $batch_res = $match_mysqli->query($batch_sql);
            if ($batch_res) {
                while ($m = $batch_res->fetch_assoc()) {
                    $master_map[strtolower(trim($m['Email']))][] = $m;
                }
            }
            // Only close if we created it
            if (!$external_master_link) $match_mysqli->close();

            $client_mysqli->begin_transaction();
            $stmt_update = $client_mysqli->prepare("UPDATE superpixel_emails SET confidence_score=?, reason=?, matched_first_name=?, matched_last_name=?, npn=?, crd=?, npn_active=?, crd_active=? WHERE id=?");

            $v_fn = mb_strtolower($visitor_first_name);
            $v_ln = mb_strtolower($visitor_last_name);
            $v_fn_3 = mb_substr($v_fn, 0, 3);

            foreach ($email_rows as $row) {
                $email_raw = trim($row['email']);
                $email_key = strtolower($email_raw);
                
                $match_data = null;
                $score = 0;
                $reason = "No Match";

                if (isset($master_map[$email_key])) {
                    foreach ($master_map[$email_key] as $cand) {
                        $m_fn = mb_strtolower($cand['first_name'] ?? '');
                        $m_ln = mb_strtolower($cand['last_name'] ?? '');
                        
                        if ($m_fn === $v_fn && $m_ln === $v_ln) {
                            $score = 100;
                            $reason = 'Same Person';
                            $match_data = $cand;
                            break; 
                        }
                        
                        if ($score < 80 && $m_ln === $v_ln && mb_substr($m_fn, 0, 3) === $v_fn_3) {
                            $score = 80;
                            $reason = 'Nickname';
                            $match_data = $cand;
                        }
                    }
                }

                if ($match_data) {
                    $stmt_update->bind_param("isssssiii", 
                        $score, $reason, $match_data['first_name'], $match_data['last_name'], 
                        $match_data['NPN'], $match_data['CRD'], $match_data['NPN_Active'], $match_data['CRD_Active'], 
                        $row['id']
                    );
                    $stmt_update->execute();

                    if ($best_match === null || $score > $best_match['score']) {
                        $best_match = [
                            'score' => $score,
                            'npn' => $match_data['NPN'],
                            'crd' => $match_data['CRD'],
                            'email' => $email_raw,
                            'reason' => $reason,
                            'email_type' => $row['email_type'],
                            'master_name' => $match_data['first_name'] . ' ' . $match_data['last_name']
                        ];
                    }
                }

                if (is_callable($progress_callback)) {
                    $progress_callback($email_raw, $row['email_type'], "$visitor_first_name $visitor_last_name", $reason, $uuid);
                }
            }

            $stmt_update->close();
            $client_mysqli->commit();
        }
    }

    // --- PHASE 3: UPDATE VISITOR PARENT ---
    if ($best_match) {
        $results['best_match'] = $best_match;
        $stmt_v = $client_mysqli->prepare("UPDATE superpixel_visitors SET npn=?, crd=? WHERE uuid=?");
        $stmt_v->bind_param("sss", $best_match['npn'], $best_match['crd'], $uuid);
        $stmt_v->execute();
        $stmt_v->close();

        $stmt_l = $client_mysqli->prepare("UPDATE superpixel_resolution_log SET npn=?, crd=? WHERE uuid=?");
        $stmt_l->bind_param("sss", $best_match['npn'], $best_match['crd'], $uuid);
        $stmt_l->execute();
        $stmt_l->close();
    }

    if (!$external_client_link) $client_mysqli->close();
    return $results;
}
?>
