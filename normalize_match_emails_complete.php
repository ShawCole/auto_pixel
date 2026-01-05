<?php
/**
 * Complete normalization of match_emails table
 * Propagates CRD, NPN, and AgentID in ALL directions where safe to do so
 * 
 * Usage: php normalize_match_emails_complete.php [analyze|update]
 */

$mode = isset($argv[1]) ? $argv[1] : 'analyze';

if (!in_array($mode, ['analyze', 'update'])) {
    die("Usage: php normalize_match_emails_complete.php [analyze|update]\n");
}

// Database configuration
$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';
$dbname = 'accupoint_solutions';

$mysqli = new mysqli($host, $user, $pass, $dbname);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== COMPLETE MATCH_EMAILS NORMALIZATION " . strtoupper($mode) . " ===\n\n";

// Track all updates needed
$updates_needed = [
    'crd_to_npn' => [],
    'crd_to_agentid' => [],
    'npn_to_crd' => [],
    'npn_to_agentid' => [],
    'agentid_to_crd' => [],
    'agentid_to_npn' => []
];

// Step 1: Analyze CRD → NPN propagation
echo "📊 Step 1: Analyzing CRD → NPN propagation...\n";
$query = "
    SELECT 
        CRD,
        COUNT(DISTINCT CASE WHEN NPN IS NOT NULL THEN NPN END) as unique_npns,
        MAX(NPN) as npn_value,
        COUNT(CASE WHEN NPN IS NULL THEN 1 END) as null_npn_count
    FROM match_emails
    WHERE CRD IS NOT NULL
    GROUP BY CRD
    HAVING unique_npns = 1 AND null_npn_count > 0
";

$result = $mysqli->query($query);
while ($row = $result->fetch_assoc()) {
    $updates_needed['crd_to_npn'][$row['CRD']] = $row['npn_value'];
}
echo "Found " . count($updates_needed['crd_to_npn']) . " CRDs that can propagate NPNs\n";

// Step 2: Analyze CRD → AgentID propagation
echo "\n📊 Step 2: Analyzing CRD → AgentID propagation...\n";
$query = "
    SELECT 
        CRD,
        COUNT(DISTINCT CASE WHEN AgentID IS NOT NULL THEN AgentID END) as unique_agentids,
        MAX(AgentID) as agentid_value,
        COUNT(CASE WHEN AgentID IS NULL THEN 1 END) as null_agentid_count
    FROM match_emails
    WHERE CRD IS NOT NULL
    GROUP BY CRD
    HAVING unique_agentids = 1 AND null_agentid_count > 0
";

$result = $mysqli->query($query);
while ($row = $result->fetch_assoc()) {
    $updates_needed['crd_to_agentid'][$row['CRD']] = $row['agentid_value'];
}
echo "Found " . count($updates_needed['crd_to_agentid']) . " CRDs that can propagate AgentIDs\n";

// Step 3: Analyze NPN → CRD propagation
echo "\n📊 Step 3: Analyzing NPN → CRD propagation...\n";
$query = "
    SELECT 
        NPN,
        COUNT(DISTINCT CASE WHEN CRD IS NOT NULL THEN CRD END) as unique_crds,
        MAX(CRD) as crd_value,
        COUNT(CASE WHEN CRD IS NULL THEN 1 END) as null_crd_count
    FROM match_emails
    WHERE NPN IS NOT NULL
    GROUP BY NPN
    HAVING unique_crds = 1 AND null_crd_count > 0
";

$result = $mysqli->query($query);
while ($row = $result->fetch_assoc()) {
    $updates_needed['npn_to_crd'][$row['NPN']] = $row['crd_value'];
}
echo "Found " . count($updates_needed['npn_to_crd']) . " NPNs that can propagate CRDs\n";

// Step 4: Analyze NPN → AgentID propagation
echo "\n📊 Step 4: Analyzing NPN → AgentID propagation...\n";
$query = "
    SELECT 
        NPN,
        COUNT(DISTINCT CASE WHEN AgentID IS NOT NULL THEN AgentID END) as unique_agentids,
        MAX(AgentID) as agentid_value,
        COUNT(CASE WHEN AgentID IS NULL THEN 1 END) as null_agentid_count
    FROM match_emails
    WHERE NPN IS NOT NULL
    GROUP BY NPN
    HAVING unique_agentids = 1 AND null_agentid_count > 0
";

$result = $mysqli->query($query);
while ($row = $result->fetch_assoc()) {
    $updates_needed['npn_to_agentid'][$row['NPN']] = $row['agentid_value'];
}
echo "Found " . count($updates_needed['npn_to_agentid']) . " NPNs that can propagate AgentIDs\n";

// Step 5: Analyze AgentID → CRD propagation
echo "\n📊 Step 5: Analyzing AgentID → CRD propagation...\n";
$query = "
    SELECT 
        AgentID,
        COUNT(DISTINCT CASE WHEN CRD IS NOT NULL THEN CRD END) as unique_crds,
        MAX(CRD) as crd_value,
        COUNT(CASE WHEN CRD IS NULL THEN 1 END) as null_crd_count
    FROM match_emails
    WHERE AgentID IS NOT NULL
    GROUP BY AgentID
    HAVING unique_crds = 1 AND null_crd_count > 0
";

$result = $mysqli->query($query);
while ($row = $result->fetch_assoc()) {
    $updates_needed['agentid_to_crd'][$row['AgentID']] = $row['crd_value'];
}
echo "Found " . count($updates_needed['agentid_to_crd']) . " AgentIDs that can propagate CRDs\n";

// Step 6: Analyze AgentID → NPN propagation
echo "\n📊 Step 6: Analyzing AgentID → NPN propagation...\n";
$query = "
    SELECT 
        AgentID,
        COUNT(DISTINCT CASE WHEN NPN IS NOT NULL THEN NPN END) as unique_npns,
        MAX(NPN) as npn_value,
        COUNT(CASE WHEN NPN IS NULL THEN 1 END) as null_npn_count
    FROM match_emails
    WHERE AgentID IS NOT NULL
    GROUP BY AgentID
    HAVING unique_npns = 1 AND null_npn_count > 0
";

$result = $mysqli->query($query);
while ($row = $result->fetch_assoc()) {
    $updates_needed['agentid_to_npn'][$row['AgentID']] = $row['npn_value'];
}
echo "Found " . count($updates_needed['agentid_to_npn']) . " AgentIDs that can propagate NPNs\n";

// Calculate total updates
$total_updates = 0;
echo "\n📊 SUMMARY OF UPDATES NEEDED:\n";
echo str_repeat("-", 50) . "\n";

// For each type, calculate actual row count
foreach ($updates_needed as $type => $mappings) {
    if (empty($mappings)) continue;
    
    $count = 0;
    if ($type === 'crd_to_npn') {
        $crds = array_keys($mappings);
        $chunks = array_chunk($crds, 100);
        foreach ($chunks as $chunk) {
            $in_clause = "'" . implode("','", $chunk) . "'";
            $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM match_emails WHERE CRD IN ($in_clause) AND NPN IS NULL");
            $count += $count_result->fetch_assoc()['cnt'];
        }
    } elseif ($type === 'crd_to_agentid') {
        $crds = array_keys($mappings);
        $chunks = array_chunk($crds, 100);
        foreach ($chunks as $chunk) {
            $in_clause = "'" . implode("','", $chunk) . "'";
            $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM match_emails WHERE CRD IN ($in_clause) AND AgentID IS NULL");
            $count += $count_result->fetch_assoc()['cnt'];
        }
    } elseif ($type === 'npn_to_crd') {
        $npns = array_keys($mappings);
        $chunks = array_chunk($npns, 100);
        foreach ($chunks as $chunk) {
            $in_clause = "'" . implode("','", $chunk) . "'";
            $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM match_emails WHERE NPN IN ($in_clause) AND CRD IS NULL");
            $count += $count_result->fetch_assoc()['cnt'];
        }
    } elseif ($type === 'npn_to_agentid') {
        $npns = array_keys($mappings);
        $chunks = array_chunk($npns, 100);
        foreach ($chunks as $chunk) {
            $in_clause = "'" . implode("','", $chunk) . "'";
            $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM match_emails WHERE NPN IN ($in_clause) AND AgentID IS NULL");
            $count += $count_result->fetch_assoc()['cnt'];
        }
    } elseif ($type === 'agentid_to_crd') {
        $agentids = array_keys($mappings);
        $chunks = array_chunk($agentids, 100);
        foreach ($chunks as $chunk) {
            $in_clause = "'" . implode("','", $chunk) . "'";
            $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM match_emails WHERE AgentID IN ($in_clause) AND CRD IS NULL");
            $count += $count_result->fetch_assoc()['cnt'];
        }
    } elseif ($type === 'agentid_to_npn') {
        $agentids = array_keys($mappings);
        $chunks = array_chunk($agentids, 100);
        foreach ($chunks as $chunk) {
            $in_clause = "'" . implode("','", $chunk) . "'";
            $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM match_emails WHERE AgentID IN ($in_clause) AND NPN IS NULL");
            $count += $count_result->fetch_assoc()['cnt'];
        }
    }
    
    $total_updates += $count;
    echo str_replace('_', ' → ', strtoupper($type)) . ": " . number_format($count) . " rows\n";
}

echo str_repeat("-", 50) . "\n";
echo "TOTAL UPDATES: " . number_format($total_updates) . " rows\n";

// Show sample updates
echo "\n📋 Sample updates that " . ($mode === 'update' ? "will be" : "would be") . " made:\n";
echo "\nCRD → NPN samples:\n";
$sample_query = "SELECT CRD, Email FROM match_emails WHERE CRD IN ('" . 
    implode("','", array_slice(array_keys($updates_needed['crd_to_npn']), 0, 3)) . "') AND NPN IS NULL LIMIT 5";
$result = $mysqli->query($sample_query);
while ($row = $result->fetch_assoc()) {
    $npn = $updates_needed['crd_to_npn'][$row['CRD']];
    echo "  CRD {$row['CRD']} → NPN $npn | {$row['Email']}\n";
}

echo "\nNPN → CRD samples:\n";
$sample_query = "SELECT NPN, Email FROM match_emails WHERE NPN IN ('" . 
    implode("','", array_slice(array_keys($updates_needed['npn_to_crd']), 0, 3)) . "') AND CRD IS NULL LIMIT 5";
$result = $mysqli->query($sample_query);
while ($row = $result->fetch_assoc()) {
    $crd = $updates_needed['npn_to_crd'][$row['NPN']];
    echo "  NPN {$row['NPN']} → CRD $crd | {$row['Email']}\n";
}

// Perform updates if in update mode
if ($mode === 'update' && $total_updates > 0) {
    echo "\n🔄 Starting comprehensive normalization updates...\n";
    $updated_total = 0;
    
    // Update CRD → NPN
    if (!empty($updates_needed['crd_to_npn'])) {
        echo "\nUpdating CRD → NPN...\n";
        $batch_size = 100;
        $crd_chunks = array_chunk(array_keys($updates_needed['crd_to_npn']), $batch_size);
        
        foreach ($crd_chunks as $i => $crd_batch) {
            $case_when = "";
            foreach ($crd_batch as $crd) {
                $npn = $updates_needed['crd_to_npn'][$crd];
                $case_when .= "WHEN '$crd' THEN '$npn' ";
            }
            
            $update_query = "
                UPDATE match_emails 
                SET NPN = CASE CRD $case_when END
                WHERE CRD IN ('" . implode("','", $crd_batch) . "')
                AND NPN IS NULL
            ";
            
            if ($mysqli->query($update_query)) {
                $affected = $mysqli->affected_rows;
                $updated_total += $affected;
                echo "  Batch " . ($i + 1) . ": Updated $affected rows\n";
            }
        }
    }
    
    // Update NPN → CRD
    if (!empty($updates_needed['npn_to_crd'])) {
        echo "\nUpdating NPN → CRD...\n";
        $batch_size = 100;
        $npn_chunks = array_chunk(array_keys($updates_needed['npn_to_crd']), $batch_size);
        
        foreach ($npn_chunks as $i => $npn_batch) {
            $case_when = "";
            foreach ($npn_batch as $npn) {
                $crd = $updates_needed['npn_to_crd'][$npn];
                $case_when .= "WHEN '$npn' THEN '$crd' ";
            }
            
            $update_query = "
                UPDATE match_emails 
                SET CRD = CASE NPN $case_when END
                WHERE NPN IN ('" . implode("','", $npn_batch) . "')
                AND CRD IS NULL
            ";
            
            if ($mysqli->query($update_query)) {
                $affected = $mysqli->affected_rows;
                $updated_total += $affected;
                echo "  Batch " . ($i + 1) . ": Updated $affected rows\n";
            }
        }
    }
    
    // Update CRD → AgentID
    if (!empty($updates_needed['crd_to_agentid'])) {
        echo "\nUpdating CRD → AgentID...\n";
        $batch_size = 100;
        $crd_chunks = array_chunk(array_keys($updates_needed['crd_to_agentid']), $batch_size);
        
        foreach ($crd_chunks as $i => $crd_batch) {
            $case_when = "";
            foreach ($crd_batch as $crd) {
                $agentid = $updates_needed['crd_to_agentid'][$crd];
                $case_when .= "WHEN '$crd' THEN '$agentid' ";
            }
            
            $update_query = "
                UPDATE match_emails 
                SET AgentID = CASE CRD $case_when END
                WHERE CRD IN ('" . implode("','", $crd_batch) . "')
                AND AgentID IS NULL
            ";
            
            if ($mysqli->query($update_query)) {
                $affected = $mysqli->affected_rows;
                $updated_total += $affected;
                echo "  Batch " . ($i + 1) . ": Updated $affected rows\n";
            }
        }
    }
    
    // Update NPN → AgentID
    if (!empty($updates_needed['npn_to_agentid'])) {
        echo "\nUpdating NPN → AgentID...\n";
        $batch_size = 100;
        $npn_chunks = array_chunk(array_keys($updates_needed['npn_to_agentid']), $batch_size);
        
        foreach ($npn_chunks as $i => $npn_batch) {
            $case_when = "";
            foreach ($npn_batch as $npn) {
                $agentid = $updates_needed['npn_to_agentid'][$npn];
                $case_when .= "WHEN '$npn' THEN '$agentid' ";
            }
            
            $update_query = "
                UPDATE match_emails 
                SET AgentID = CASE NPN $case_when END
                WHERE NPN IN ('" . implode("','", $npn_batch) . "')
                AND AgentID IS NULL
            ";
            
            if ($mysqli->query($update_query)) {
                $affected = $mysqli->affected_rows;
                $updated_total += $affected;
                echo "  Batch " . ($i + 1) . ": Updated $affected rows\n";
            }
        }
    }
    
    // Update AgentID → CRD
    if (!empty($updates_needed['agentid_to_crd'])) {
        echo "\nUpdating AgentID → CRD...\n";
        $batch_size = 100;
        $agentid_chunks = array_chunk(array_keys($updates_needed['agentid_to_crd']), $batch_size);
        
        foreach ($agentid_chunks as $i => $agentid_batch) {
            $case_when = "";
            foreach ($agentid_batch as $agentid) {
                $crd = $updates_needed['agentid_to_crd'][$agentid];
                $case_when .= "WHEN '$agentid' THEN '$crd' ";
            }
            
            $update_query = "
                UPDATE match_emails 
                SET CRD = CASE AgentID $case_when END
                WHERE AgentID IN ('" . implode("','", $agentid_batch) . "')
                AND CRD IS NULL
            ";
            
            if ($mysqli->query($update_query)) {
                $affected = $mysqli->affected_rows;
                $updated_total += $affected;
                echo "  Batch " . ($i + 1) . ": Updated $affected rows\n";
            }
        }
    }
    
    // Update AgentID → NPN
    if (!empty($updates_needed['agentid_to_npn'])) {
        echo "\nUpdating AgentID → NPN...\n";
        $batch_size = 100;
        $agentid_chunks = array_chunk(array_keys($updates_needed['agentid_to_npn']), $batch_size);
        
        foreach ($agentid_chunks as $i => $agentid_batch) {
            $case_when = "";
            foreach ($agentid_batch as $agentid) {
                $npn = $updates_needed['agentid_to_npn'][$agentid];
                $case_when .= "WHEN '$agentid' THEN '$npn' ";
            }
            
            $update_query = "
                UPDATE match_emails 
                SET NPN = CASE AgentID $case_when END
                WHERE AgentID IN ('" . implode("','", $agentid_batch) . "')
                AND NPN IS NULL
            ";
            
            if ($mysqli->query($update_query)) {
                $affected = $mysqli->affected_rows;
                $updated_total += $affected;
                echo "  Batch " . ($i + 1) . ": Updated $affected rows\n";
            }
        }
    }
    
    echo "\n✅ Complete normalization finished!\n";
    echo "Total rows updated: " . number_format($updated_total) . "\n";
}

// Show final statistics
echo "\n📊 FINAL STATISTICS:\n";

$final_stats = $mysqli->query("
    SELECT 
        COUNT(*) as total_rows,
        COUNT(DISTINCT CRD) as unique_crds,
        COUNT(DISTINCT NPN) as unique_npns,
        COUNT(DISTINCT AgentID) as unique_agentids,
        COUNT(CASE WHEN CRD IS NOT NULL THEN 1 END) as rows_with_crd,
        COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) as rows_with_npn,
        COUNT(CASE WHEN AgentID IS NOT NULL THEN 1 END) as rows_with_agentid,
        COUNT(CASE WHEN CRD IS NOT NULL AND NPN IS NOT NULL THEN 1 END) as rows_with_both,
        COUNT(CASE WHEN CRD IS NULL AND NPN IS NULL THEN 1 END) as rows_with_neither
    FROM match_emails
");

$stats = $final_stats->fetch_assoc();

echo "Total rows: " . number_format($stats['total_rows']) . "\n";
echo "Unique CRDs: " . number_format($stats['unique_crds']) . "\n";
echo "Unique NPNs: " . number_format($stats['unique_npns']) . "\n";
echo "Unique AgentIDs: " . number_format($stats['unique_agentids']) . "\n";
echo "\n";
echo "Rows with CRD: " . number_format($stats['rows_with_crd']) . " (" . 
     round($stats['rows_with_crd'] * 100.0 / $stats['total_rows'], 2) . "%)\n";
echo "Rows with NPN: " . number_format($stats['rows_with_npn']) . " (" . 
     round($stats['rows_with_npn'] * 100.0 / $stats['total_rows'], 2) . "%)\n";
echo "Rows with AgentID: " . number_format($stats['rows_with_agentid']) . " (" . 
     round($stats['rows_with_agentid'] * 100.0 / $stats['total_rows'], 2) . "%)\n";
echo "Rows with both CRD & NPN: " . number_format($stats['rows_with_both']) . " (" . 
     round($stats['rows_with_both'] * 100.0 / $stats['total_rows'], 2) . "%)\n";
echo "Rows with neither: " . number_format($stats['rows_with_neither']) . " (" . 
     round($stats['rows_with_neither'] * 100.0 / $stats['total_rows'], 2) . "%)\n";

if ($mode === 'analyze') {
    echo "\n💡 To apply these updates, run: php normalize_match_emails_complete.php update\n";
}

echo "\n=== COMPLETE NORMALIZATION " . strtoupper($mode) . " COMPLETE ===\n";

$mysqli->close();
?> 