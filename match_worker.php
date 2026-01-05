<?php
/**
 * match_worker.php
 * Location: /opt/auto-pixel/match_worker.php
 * 
 * LOGIC TIER HIERARCHY:
 * 1. UUID Match (Golden Rule) -> "Matched to APS" (Score 100)
 * 2. Strict Bio Match -> "Same First & Last & Email" (Score 100)
 * 3. Nickname Match -> "Nickname: [Name] + Same Last & Email" (Score 80)
 */

$DB_HOST = '34.26.61.148';
$DB_USER = 'root';
$DB_PASS = 'AccuPoint01!';
$CENTRAL_DB = 'pixel_v2';
$MASTER_DB = 'accupoint_solutions';
$BATCH_SIZE = 100;

// --- LOGGING ---
function workerLog($msg) {
    $log = '/var/log/auto-pixel/match_worker.log';
    if (!is_dir(dirname($log))) mkdir(dirname($log), 0755, true);
    $line = "[" . date('Y-m-d H:i:s') . "] $msg";
    echo "$line\n";
    file_put_contents($log, "$line\n", FILE_APPEND);
}

// --- SETUP ---
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $CENTRAL_DB);
if ($mysqli->connect_error) exit("Central DB Error\n");

// Get Active Clients
$clients = $mysqli->query("SELECT client_name, client_slug FROM pixel_sheets WHERE status='active' AND paused=0")->fetch_all(MYSQLI_ASSOC);
$mysqli->close();

// Connect to Master DB
$master = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $MASTER_DB);
if ($master->connect_error) exit("Master DB Error\n");

// --- PROCESSING LOOP ---
foreach ($clients as $client) {
    $slug = $client['client_slug'];
    
    try {
        $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $slug);
        if ($db->connect_error) continue;

        // Verify V2 table exists
        if ($db->query("SHOW TABLES LIKE 'matched_professionals'")->num_rows === 0) {
            $db->close(); continue;
        }

        // 1. Find Pending Visitors
        // We look for visitors NOT in matched_professionals yet
        $sql = "SELECT v.uuid, v.first_name, v.last_name, v.email_best, v.personal_emails, v.business_email 
                FROM visitors v
                LEFT JOIN matched_professionals mp ON v.uuid = mp.visitor_uuid
                WHERE mp.visitor_uuid IS NULL 
                AND (v.email_best IS NOT NULL OR v.personal_emails IS NOT NULL OR v.business_email IS NOT NULL)
                ORDER BY v.last_seen_at DESC
                LIMIT $BATCH_SIZE";

        $q = $db->query($sql);
        $visitors = $q ? $q->fetch_all(MYSQLI_ASSOC) : [];

        if (empty($visitors)) { $db->close(); continue; }

        workerLog("Processing " . count($visitors) . " visitors for $slug");

        foreach ($visitors as $v) {
            $uuid = $v['uuid'];
            $vFn = trim($v['first_name'] ?? '');
            $vLn = trim($v['last_name'] ?? '');
            
            // Consolidate Emails
            $emails = [];
            if (!empty($v['email_best'])) $emails[] = $v['email_best'];
            if (!empty($v['business_email'])) $emails[] = $v['business_email'];
            if (!empty($v['personal_emails'])) {
                foreach(explode(',', $v['personal_emails']) as $e) $emails[] = trim($e);
            }
            $emails = array_values(array_unique(array_filter($emails)));

            // Match Result Containers
            $matchData = null; // {npn, crd, npn_active, crd_active, ...}
            $score = 0;
            $reason = null;
            $matchedEmail = null;
            $matchedFn = null;
            $matchedLn = null;
            $sourceType = 'none'; // uuid_exact, strict_bio, nickname

            // ---------------------------------------------------------
            // TIER 1: UUID MATCH (Golden Rule)
            // ---------------------------------------------------------
            if ($uuid) {
                $stmt = $master->prepare("SELECT NPN, CRD, NPN_Active, CRD_Active, email_lc, is_rr, is_ia, is_agent 
                                          FROM Contacts WHERE uuid = ? LIMIT 1");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    // Found!
                    if (!empty($row['NPN']) || !empty($row['CRD'])) {
                        $matchData = $row;
                        $score = 100;
                        $reason = "Matched to APS";
                        $sourceType = 'uuid_exact';
                    }
                }
                $stmt->close();
            }

            // ---------------------------------------------------------
            // TIER 2 & 3: BIO MATCH (If Tier 1 failed)
            // ---------------------------------------------------------
            if (!$matchData && !empty($emails) && $vFn && $vLn) {
                
                // 1. Fetch ALL candidates for these emails
                $phs = implode(',', array_fill(0, count($emails), '?'));
                $types = str_repeat('s', count($emails));
                $sqlBio = "SELECT Email, NPN, CRD, NPN_Active, CRD_Active, First_Name, Last_Name, is_rr, is_ia, is_agent 
                           FROM match_emails WHERE Email IN ($phs)";
                
                $stmt = $master->prepare($sqlBio);
                $stmt->bind_param($types, ...$emails);
                $stmt->execute();
                $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                // 2. Evaluate Candidates in PHP (Faster than complex SQL ORs)
                foreach ($candidates as $cand) {
                    $cFn = trim($cand['First_Name'] ?? '');
                    $cLn = trim($cand['Last_Name'] ?? '');
                    $cEmail = strtolower(trim($cand['Email']));

                    // Skip if no NPN/CRD (Safety)
                    if (empty($cand['NPN']) && empty($cand['CRD'])) continue;

                    // CHECK: Strict (First + Last + Email)
                    if (strcasecmp($cFn, $vFn) === 0 && strcasecmp($cLn, $vLn) === 0) {
                        // Found Tier 2 - Stop looking, this is best.
                        $matchData = $cand;
                        $score = 100;
                        $reason = "Same First & Last & Email";
                        $sourceType = 'strict_bio';
                        $matchedEmail = $cEmail;
                        $matchedFn = $cFn;
                        $matchedLn = $cLn;
                        break; 
                    }

                    // CHECK: Nickname (3 chars First + Last + Email)
                    // Only if we haven't found a score 100 yet
                    if ($score < 80) {
                        if (strcasecmp($cLn, $vLn) === 0 && 
                            stripos($cFn, substr($vFn, 0, 3)) === 0) {
                            
                            $matchData = $cand;
                            $score = 80;
                            $reason = "Nickname: " . $cFn . " + Same Last & Email";
                            $sourceType = 'nickname';
                            $matchedEmail = $cEmail;
                            $matchedFn = $cFn;
                            $matchedLn = $cLn;
                        }
                    }
                }
            }

            // ---------------------------------------------------------
            // UPDATE PHASE
            // ---------------------------------------------------------
            if ($matchData) {
                $npn = $matchData['NPN'] ?? null;
                $crd = $matchData['CRD'] ?? null;
                $npnActive = !empty($matchData['NPN_Active']) ? 1 : 0;
                $crdActive = !empty($matchData['CRD_Active']) ? 1 : 0;
                $isRr = $matchData['is_rr'] ?? 0;
                $isIa = $matchData['is_ia'] ?? 0;
                $isAgent = $matchData['is_agent'] ?? 0;

                // A. Update 'matched_professionals'
                $ins = $db->prepare("INSERT INTO matched_professionals 
                    (visitor_uuid, crd, npn, is_rr, is_ia, is_agent, source, matched_at, confidence_score) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE matched_at=NOW(), confidence_score=VALUES(confidence_score), source=VALUES(source)");
                $ins->bind_param("sssiiisi", $uuid, $crd, $npn, $isRr, $isIa, $isAgent, $sourceType, $score);
                $ins->execute();
                $ins->close();

                // B. Update 'visitors'
                // If Tier 1 (UUID), we also update email_best
                $updateSql = "UPDATE visitors SET 
                              npn=?, crd=?, npn_active=?, crd_active=?, 
                              APS_confidence_score=?, reason=?";
                $params = [$npn, $crd, $npnActive, $crdActive, $score, $reason];
                $types = "ssiiis";

                if ($sourceType === 'uuid_exact' && !empty($matchData['email_lc'])) {
                    $updateSql .= ", email_best=?";
                    $params[] = $matchData['email_lc'];
                    $types .= "s";
                }
                
                $updateSql .= " WHERE uuid=?";
                $params[] = $uuid;
                $types .= "s";

                $stmt = $db->prepare($updateSql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();

                // C. Update 'events' (Backfill all events for this UUID)
                // "append all of these datapoints back to events"
                $evtUpd = $db->prepare("UPDATE events SET 
                                        npn=?, crd=?, npn_active=?, crd_active=?, 
                                        APS_confidence_score=?, reason=? 
                                        WHERE uuid=?");
                $evtUpd->bind_param("ssiiiss", $npn, $crd, $npnActive, $crdActive, $score, $reason, $uuid);
                $evtUpd->execute();
                $evtUpd->close();

                // D. Update 'emails' (If matched via email)
                if ($matchedEmail) {
                    $emUpd = $db->prepare("UPDATE emails SET 
                                           matched_first_name=?, matched_last_name=?, 
                                           confidence_score=?, reason=?,
                                           npn=?, crd=?, npn_active=?, crd_active=?
                                           WHERE uuid=? AND email=?");
                    $emUpd->bind_param("ssisssiiss", 
                        $matchedFn, $matchedLn, $score, $reason,
                        $npn, $crd, $npnActive, $crdActive,
                        $uuid, $matchedEmail
                    );
                    $emUpd->execute();
                    $emUpd->close();
                }

                workerLog("MATCH ($sourceType): $uuid -> NPN: $npn ($reason)");

            } else {
                // No Match - Mark as processed so we don't loop
                $db->query("INSERT IGNORE INTO matched_professionals (visitor_uuid, matched_at, confidence_score, source) VALUES ('$uuid', NOW(), 0, 'no_match')");
            }
        } // End Visitor Loop

        $db->close();

    } catch (Exception $e) {
        workerLog("Error on client $slug: " . $e->getMessage());
    }
}

$master->close();
?>
