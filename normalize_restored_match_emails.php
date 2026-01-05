<?php
/**
 * Normalize restored match_emails table
 * 
 * Criteria:
 * - If multiple rows with same CRD and one has NPN, propagate NPN to others with same CRD
 * - If multiple rows with same NPN and one has CRD, propagate CRD to others with same NPN  
 * - Same logic applies to AgentID
 * - Do NOT update if there are conflicting values (multiple different NPNs for same CRD, etc.)
 */

$mode = isset($argv[1]) ? $argv[1] : 'analyze';

if (!in_array($mode, ['analyze', 'update'])) {
    die("Usage: php normalize_restored_match_emails.php [analyze|update]\n");
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

echo "=== MATCH_EMAILS NORMALIZATION (RESTORED TABLE) ===\n";
echo "Mode: " . strtoupper($mode) . "\n\n";

// Get table stats first
$result = $mysqli->query("SELECT COUNT(*) as total, 
                         SUM(CASE WHEN CRD IS NOT NULL AND CRD != '' THEN 1 ELSE 0 END) as has_crd,
                         SUM(CASE WHEN NPN IS NOT NULL AND NPN != '' THEN 1 ELSE 0 END) as has_npn,
                         SUM(CASE WHEN AgentID IS NOT NULL AND AgentID != '' THEN 1 ELSE 0 END) as has_agentid
                         FROM match_emails");
$stats = $result->fetch_assoc();

echo "Table statistics (before normalization):\n";
echo "- Total rows: " . number_format($stats['total']) . "\n";
echo "- Rows with CRD: " . number_format($stats['has_crd']) . "\n";
echo "- Rows with NPN: " . number_format($stats['has_npn']) . "\n";
echo "- Rows with AgentID: " . number_format($stats['has_agentid']) . "\n\n";

$updates_needed = [];

// 1. CRD-based normalization: CRD → NPN and CRD → AgentID
echo "=== ANALYZING CRD-BASED NORMALIZATION ===\n";

// CRD → NPN propagation
echo "1. Analyzing CRD → NPN propagation...\n";
$crd_npn_query = "
    SELECT crd_group.CRD, 
           COUNT(*) as total_rows,
           COUNT(DISTINCT crd_group.NPN) as distinct_npns,
           SUM(CASE WHEN crd_group.NPN IS NOT NULL AND crd_group.NPN != '' THEN 1 ELSE 0 END) as rows_with_npn,
           MAX(CASE WHEN crd_group.NPN IS NOT NULL AND crd_group.NPN != '' THEN crd_group.NPN END) as available_npn
    FROM match_emails crd_group 
    WHERE crd_group.CRD IS NOT NULL AND crd_group.CRD != ''
    GROUP BY crd_group.CRD
    HAVING total_rows > 1 
       AND rows_with_npn > 0 
       AND rows_with_npn < total_rows 
       AND distinct_npns = 1
";

$result = $mysqli->query($crd_npn_query);
$crd_npn_updates = [];
while ($row = $result->fetch_assoc()) {
    $crd_npn_updates[] = $row;
}

echo "   Found " . count($crd_npn_updates) . " CRDs that can propagate NPN\n";

if ($mode === 'analyze' && count($crd_npn_updates) > 0) {
    echo "   Sample CRD → NPN updates:\n";
    foreach (array_slice($crd_npn_updates, 0, 5) as $update) {
        echo "     CRD {$update['CRD']}: {$update['rows_with_npn']}/{$update['total_rows']} rows have NPN {$update['available_npn']}\n";
    }
}

// CRD → AgentID propagation
echo "2. Analyzing CRD → AgentID propagation...\n";
$crd_agentid_query = "
    SELECT crd_group.CRD, 
           COUNT(*) as total_rows,
           COUNT(DISTINCT crd_group.AgentID) as distinct_agentids,
           SUM(CASE WHEN crd_group.AgentID IS NOT NULL AND crd_group.AgentID != '' THEN 1 ELSE 0 END) as rows_with_agentid,
           MAX(CASE WHEN crd_group.AgentID IS NOT NULL AND crd_group.AgentID != '' THEN crd_group.AgentID END) as available_agentid
    FROM match_emails crd_group 
    WHERE crd_group.CRD IS NOT NULL AND crd_group.CRD != ''
    GROUP BY crd_group.CRD
    HAVING total_rows > 1 
       AND rows_with_agentid > 0 
       AND rows_with_agentid < total_rows 
       AND distinct_agentids = 1
";

$result = $mysqli->query($crd_agentid_query);
$crd_agentid_updates = [];
while ($row = $result->fetch_assoc()) {
    $crd_agentid_updates[] = $row;
}

echo "   Found " . count($crd_agentid_updates) . " CRDs that can propagate AgentID\n";

// 2. NPN-based normalization: NPN → CRD and NPN → AgentID  
echo "\n=== ANALYZING NPN-BASED NORMALIZATION ===\n";

// NPN → CRD propagation
echo "1. Analyzing NPN → CRD propagation...\n";
$npn_crd_query = "
    SELECT npn_group.NPN, 
           COUNT(*) as total_rows,
           COUNT(DISTINCT npn_group.CRD) as distinct_crds,
           SUM(CASE WHEN npn_group.CRD IS NOT NULL AND npn_group.CRD != '' THEN 1 ELSE 0 END) as rows_with_crd,
           MAX(CASE WHEN npn_group.CRD IS NOT NULL AND npn_group.CRD != '' THEN npn_group.CRD END) as available_crd
    FROM match_emails npn_group 
    WHERE npn_group.NPN IS NOT NULL AND npn_group.NPN != ''
    GROUP BY npn_group.NPN
    HAVING total_rows > 1 
       AND rows_with_crd > 0 
       AND rows_with_crd < total_rows 
       AND distinct_crds = 1
";

$result = $mysqli->query($npn_crd_query);
$npn_crd_updates = [];
while ($row = $result->fetch_assoc()) {
    $npn_crd_updates[] = $row;
}

echo "   Found " . count($npn_crd_updates) . " NPNs that can propagate CRD\n";

// NPN → AgentID propagation
echo "2. Analyzing NPN → AgentID propagation...\n";
$npn_agentid_query = "
    SELECT npn_group.NPN, 
           COUNT(*) as total_rows,
           COUNT(DISTINCT npn_group.AgentID) as distinct_agentids,
           SUM(CASE WHEN npn_group.AgentID IS NOT NULL AND npn_group.AgentID != '' THEN 1 ELSE 0 END) as rows_with_agentid,
           MAX(CASE WHEN npn_group.AgentID IS NOT NULL AND npn_group.AgentID != '' THEN npn_group.AgentID END) as available_agentid
    FROM match_emails npn_group 
    WHERE npn_group.NPN IS NOT NULL AND npn_group.NPN != ''
    GROUP BY npn_group.NPN
    HAVING total_rows > 1 
       AND rows_with_agentid > 0 
       AND rows_with_agentid < total_rows 
       AND distinct_agentids = 1
";

$result = $mysqli->query($npn_agentid_query);
$npn_agentid_updates = [];
while ($row = $result->fetch_assoc()) {
    $npn_agentid_updates[] = $row;
}

echo "   Found " . count($npn_agentid_updates) . " NPNs that can propagate AgentID\n";

// 3. AgentID-based normalization: AgentID → CRD and AgentID → NPN
echo "\n=== ANALYZING AGENTID-BASED NORMALIZATION ===\n";

// AgentID → CRD propagation
echo "1. Analyzing AgentID → CRD propagation...\n";
$agentid_crd_query = "
    SELECT agentid_group.AgentID, 
           COUNT(*) as total_rows,
           COUNT(DISTINCT agentid_group.CRD) as distinct_crds,
           SUM(CASE WHEN agentid_group.CRD IS NOT NULL AND agentid_group.CRD != '' THEN 1 ELSE 0 END) as rows_with_crd,
           MAX(CASE WHEN agentid_group.CRD IS NOT NULL AND agentid_group.CRD != '' THEN agentid_group.CRD END) as available_crd
    FROM match_emails agentid_group 
    WHERE agentid_group.AgentID IS NOT NULL AND agentid_group.AgentID != ''
    GROUP BY agentid_group.AgentID
    HAVING total_rows > 1 
       AND rows_with_crd > 0 
       AND rows_with_crd < total_rows 
       AND distinct_crds = 1
";

$result = $mysqli->query($agentid_crd_query);
$agentid_crd_updates = [];
while ($row = $result->fetch_assoc()) {
    $agentid_crd_updates[] = $row;
}

echo "   Found " . count($agentid_crd_updates) . " AgentIDs that can propagate CRD\n";

// AgentID → NPN propagation
echo "2. Analyzing AgentID → NPN propagation...\n";
$agentid_npn_query = "
    SELECT agentid_group.AgentID, 
           COUNT(*) as total_rows,
           COUNT(DISTINCT agentid_group.NPN) as distinct_npns,
           SUM(CASE WHEN agentid_group.NPN IS NOT NULL AND agentid_group.NPN != '' THEN 1 ELSE 0 END) as rows_with_npn,
           MAX(CASE WHEN agentid_group.NPN IS NOT NULL AND agentid_group.NPN != '' THEN agentid_group.NPN END) as available_npn
    FROM match_emails agentid_group 
    WHERE agentid_group.AgentID IS NOT NULL AND agentid_group.AgentID != ''
    GROUP BY agentid_group.AgentID
    HAVING total_rows > 1 
       AND rows_with_npn > 0 
       AND rows_with_npn < total_rows 
       AND distinct_npns = 1
";

$result = $mysqli->query($agentid_npn_query);
$agentid_npn_updates = [];
while ($row = $result->fetch_assoc()) {
    $agentid_npn_updates[] = $row;
}

echo "   Found " . count($agentid_npn_updates) . " AgentIDs that can propagate NPN\n";

// Calculate total potential updates
$total_potential_updates = 0;
foreach ($crd_npn_updates as $update) $total_potential_updates += ($update['total_rows'] - $update['rows_with_npn']);
foreach ($crd_agentid_updates as $update) $total_potential_updates += ($update['total_rows'] - $update['rows_with_agentid']);
foreach ($npn_crd_updates as $update) $total_potential_updates += ($update['total_rows'] - $update['rows_with_crd']);
foreach ($npn_agentid_updates as $update) $total_potential_updates += ($update['total_rows'] - $update['rows_with_agentid']);
foreach ($agentid_crd_updates as $update) $total_potential_updates += ($update['total_rows'] - $update['rows_with_crd']);
foreach ($agentid_npn_updates as $update) $total_potential_updates += ($update['total_rows'] - $update['rows_with_npn']);

echo "\n=== SUMMARY ===\n";
echo "Total potential cell updates: " . number_format($total_potential_updates) . "\n";

if ($mode === 'update') {
    echo "\n=== PERFORMING UPDATES ===\n";
    $total_actual_updates = 0;
    
    // Execute CRD → NPN updates
    echo "Executing CRD → NPN updates...\n";
    foreach ($crd_npn_updates as $update) {
        $sql = "UPDATE match_emails SET NPN = ? WHERE CRD = ? AND (NPN IS NULL OR NPN = '')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ss", $update['available_npn'], $update['CRD']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $total_actual_updates += $affected;
        $stmt->close();
        
        if ($affected > 0) {
            echo "  CRD {$update['CRD']}: Updated $affected rows with NPN {$update['available_npn']}\n";
        }
    }
    
    // Execute CRD → AgentID updates
    echo "Executing CRD → AgentID updates...\n";
    foreach ($crd_agentid_updates as $update) {
        $sql = "UPDATE match_emails SET AgentID = ? WHERE CRD = ? AND (AgentID IS NULL OR AgentID = '')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ss", $update['available_agentid'], $update['CRD']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $total_actual_updates += $affected;
        $stmt->close();
        
        if ($affected > 0) {
            echo "  CRD {$update['CRD']}: Updated $affected rows with AgentID {$update['available_agentid']}\n";
        }
    }
    
    // Execute NPN → CRD updates
    echo "Executing NPN → CRD updates...\n";
    foreach ($npn_crd_updates as $update) {
        $sql = "UPDATE match_emails SET CRD = ? WHERE NPN = ? AND (CRD IS NULL OR CRD = '')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ss", $update['available_crd'], $update['NPN']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $total_actual_updates += $affected;
        $stmt->close();
        
        if ($affected > 0) {
            echo "  NPN {$update['NPN']}: Updated $affected rows with CRD {$update['available_crd']}\n";
        }
    }
    
    // Execute NPN → AgentID updates
    echo "Executing NPN → AgentID updates...\n";
    foreach ($npn_agentid_updates as $update) {
        $sql = "UPDATE match_emails SET AgentID = ? WHERE NPN = ? AND (AgentID IS NULL OR AgentID = '')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ss", $update['available_agentid'], $update['NPN']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $total_actual_updates += $affected;
        $stmt->close();
        
        if ($affected > 0) {
            echo "  NPN {$update['NPN']}: Updated $affected rows with AgentID {$update['available_agentid']}\n";
        }
    }
    
    // Execute AgentID → CRD updates
    echo "Executing AgentID → CRD updates...\n";
    foreach ($agentid_crd_updates as $update) {
        $sql = "UPDATE match_emails SET CRD = ? WHERE AgentID = ? AND (CRD IS NULL OR CRD = '')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ss", $update['available_crd'], $update['AgentID']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $total_actual_updates += $affected;
        $stmt->close();
        
        if ($affected > 0) {
            echo "  AgentID {$update['AgentID']}: Updated $affected rows with CRD {$update['available_crd']}\n";
        }
    }
    
    // Execute AgentID → NPN updates
    echo "Executing AgentID → NPN updates...\n";
    foreach ($agentid_npn_updates as $update) {
        $sql = "UPDATE match_emails SET NPN = ? WHERE AgentID = ? AND (NPN IS NULL OR NPN = '')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ss", $update['available_npn'], $update['AgentID']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $total_actual_updates += $affected;
        $stmt->close();
        
        if ($affected > 0) {
            echo "  AgentID {$update['AgentID']}: Updated $affected rows with NPN {$update['available_npn']}\n";
        }
    }
    
    echo "\n=== NORMALIZATION COMPLETE ===\n";
    echo "Total cells updated: " . number_format($total_actual_updates) . "\n";
    
    // Show final statistics
    $result = $mysqli->query("SELECT COUNT(*) as total, 
                             SUM(CASE WHEN CRD IS NOT NULL AND CRD != '' THEN 1 ELSE 0 END) as has_crd,
                             SUM(CASE WHEN NPN IS NOT NULL AND NPN != '' THEN 1 ELSE 0 END) as has_npn,
                             SUM(CASE WHEN AgentID IS NOT NULL AND AgentID != '' THEN 1 ELSE 0 END) as has_agentid
                             FROM match_emails");
    $final_stats = $result->fetch_assoc();
    
    echo "\nTable statistics (after normalization):\n";
    echo "- Total rows: " . number_format($final_stats['total']) . "\n";
    echo "- Rows with CRD: " . number_format($final_stats['has_crd']) . " (+" . number_format($final_stats['has_crd'] - $stats['has_crd']) . ")\n";
    echo "- Rows with NPN: " . number_format($final_stats['has_npn']) . " (+" . number_format($final_stats['has_npn'] - $stats['has_npn']) . ")\n";
    echo "- Rows with AgentID: " . number_format($final_stats['has_agentid']) . " (+" . number_format($final_stats['has_agentid'] - $stats['has_agentid']) . ")\n";
}

$mysqli->close();
?> 