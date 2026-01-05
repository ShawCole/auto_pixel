<?php
/**
 * Normalize match_emails table by propagating NPNs to all rows with the same CRD
 * Only updates when all existing NPNs for a CRD are consistent (no conflicts)
 * 
 * Usage: php normalize_match_emails.php [analyze|update]
 */

$mode = isset($argv[1]) ? $argv[1] : 'analyze';

if (!in_array($mode, ['analyze', 'update'])) {
    die("Usage: php normalize_match_emails.php [analyze|update]\n");
}

// Database configuration (consistent with other scripts)
$host = '34.26.61.148';
$user = 'root';
$pass = 'AccuPoint01!';
$dbname = 'accupoint_solutions';

// Connect to accupoint_solutions database
$mysqli = new mysqli($host, $user, $pass, $dbname);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

echo "=== MATCH_EMAILS NORMALIZATION " . strtoupper($mode) . " ===\n\n";

// Step 1: Analyze CRDs to find those with consistent NPNs
echo "📊 Analyzing CRD-NPN relationships...\n";

$analysis_query = "
    SELECT 
        CRD,
        COUNT(DISTINCT Email) as total_emails,
        COUNT(DISTINCT CASE WHEN NPN IS NOT NULL THEN NPN END) as unique_npns,
        MAX(NPN) as npn_value,
        COUNT(CASE WHEN NPN IS NULL THEN 1 END) as null_npn_count,
        COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) as non_null_npn_count
    FROM match_emails
    WHERE CRD IS NOT NULL
    GROUP BY CRD
    HAVING unique_npns = 1 AND null_npn_count > 0
";

$result = $mysqli->query($analysis_query);
if (!$result) {
    die("Analysis query failed: " . $mysqli->error . "\n");
}

$normalizable_crds = [];
$total_updates_needed = 0;

while ($row = $result->fetch_assoc()) {
    $normalizable_crds[$row['CRD']] = [
        'npn' => $row['npn_value'],
        'total_emails' => $row['total_emails'],
        'missing_npns' => $row['null_npn_count']
    ];
    $total_updates_needed += $row['null_npn_count'];
}

echo "✅ Found " . count($normalizable_crds) . " CRDs with consistent NPNs\n";
echo "📝 Total rows that need NPN updates: " . number_format($total_updates_needed) . "\n\n";

// Step 2: Check for conflicts (CRDs with multiple different NPNs)
echo "⚠️  Checking for CRD-NPN conflicts...\n";

$conflict_query = "
    SELECT 
        CRD,
        COUNT(DISTINCT Email) as total_emails,
        COUNT(DISTINCT NPN) as unique_npns,
        GROUP_CONCAT(DISTINCT NPN ORDER BY NPN) as npn_values
    FROM match_emails
    WHERE CRD IS NOT NULL AND NPN IS NOT NULL
    GROUP BY CRD
    HAVING unique_npns > 1
    LIMIT 10
";

$conflict_result = $mysqli->query($conflict_query);
$conflict_count = 0;

if ($conflict_result->num_rows > 0) {
    echo "\n❌ CONFLICTS FOUND (showing first 10):\n";
    echo "CRD | Email Count | Unique NPNs | NPN Values\n";
    echo str_repeat("-", 80) . "\n";
    
    while ($row = $conflict_result->fetch_assoc()) {
        echo $row['CRD'] . " | " . $row['total_emails'] . " | " . 
             $row['unique_npns'] . " | " . $row['npn_values'] . "\n";
        $conflict_count++;
    }
    
    // Get total conflict count
    $total_conflicts_query = "
        SELECT COUNT(DISTINCT CRD) as conflict_count
        FROM (
            SELECT CRD, COUNT(DISTINCT NPN) as unique_npns
            FROM match_emails
            WHERE CRD IS NOT NULL AND NPN IS NOT NULL
            GROUP BY CRD
            HAVING unique_npns > 1
        ) conflicts
    ";
    
    $total_result = $mysqli->query($total_conflicts_query);
    $total_conflicts = $total_result->fetch_assoc()['conflict_count'];
    
    echo "\nTotal CRDs with conflicting NPNs: " . number_format($total_conflicts) . "\n\n";
}

// Step 3: Show sample updates that would be made
if (count($normalizable_crds) > 0) {
    echo "📋 Sample updates that " . ($mode === 'update' ? "will be" : "would be") . " made:\n";
    
    $sample_query = "
        SELECT m.CRD, m.Email, m.AgentID
        FROM match_emails m
        WHERE m.CRD IN ('" . implode("','", array_slice(array_keys($normalizable_crds), 0, 5)) . "')
        AND m.NPN IS NULL
        LIMIT 10
    ";
    
    $sample_result = $mysqli->query($sample_query);
    
    if ($sample_result->num_rows > 0) {
        echo "CRD | Email | AgentID | → NPN\n";
        echo str_repeat("-", 100) . "\n";
        
        while ($row = $sample_result->fetch_assoc()) {
            $npn = $normalizable_crds[$row['CRD']]['npn'];
            echo $row['CRD'] . " | " . $row['Email'] . " | " . 
                 ($row['AgentID'] ?: 'NULL') . " | → " . $npn . "\n";
        }
    }
}

// Step 4: Perform updates if in update mode
if ($mode === 'update' && count($normalizable_crds) > 0) {
    echo "\n🔄 Starting normalization updates...\n";
    
    $updated_total = 0;
    $batch_size = 1000;
    $batch_count = 0;
    
    // Process in batches for better performance
    $crd_chunks = array_chunk(array_keys($normalizable_crds), $batch_size);
    
    foreach ($crd_chunks as $crd_batch) {
        $batch_count++;
        
        // Build update query for this batch
        $case_when = "";
        foreach ($crd_batch as $crd) {
            $npn = $normalizable_crds[$crd]['npn'];
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
            
            echo "Batch $batch_count: Updated $affected rows\n";
            
            // Show progress every 10 batches
            if ($batch_count % 10 == 0) {
                $percent = round(($batch_count * $batch_size / count($normalizable_crds)) * 100, 1);
                echo "Progress: $percent% complete ($updated_total rows updated so far)\n";
            }
        } else {
            echo "❌ Error in batch $batch_count: " . $mysqli->error . "\n";
        }
    }
    
    echo "\n✅ Normalization complete!\n";
    echo "Total rows updated: " . number_format($updated_total) . "\n";
}

// Step 5: Show final statistics
echo "\n📊 FINAL STATISTICS:\n";

$final_stats = $mysqli->query("
    SELECT 
        COUNT(*) as total_rows,
        COUNT(DISTINCT CRD) as total_crds,
        COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) as rows_with_npn,
        COUNT(CASE WHEN NPN IS NULL THEN 1 END) as rows_without_npn,
        ROUND(COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as npn_coverage_pct
    FROM match_emails
    WHERE CRD IS NOT NULL
");

$stats = $final_stats->fetch_assoc();

echo "Total rows with CRD: " . number_format($stats['total_rows']) . "\n";
echo "Unique CRDs: " . number_format($stats['total_crds']) . "\n";
echo "Rows with NPN: " . number_format($stats['rows_with_npn']) . " (" . $stats['npn_coverage_pct'] . "%)\n";
echo "Rows without NPN: " . number_format($stats['rows_without_npn']) . "\n";

// AgentID coverage for remaining nulls
$agentid_stats = $mysqli->query("
    SELECT COUNT(DISTINCT Email) as emails_with_agentid
    FROM match_emails
    WHERE CRD IS NOT NULL AND NPN IS NULL AND AgentID IS NOT NULL
");

$agentid_count = $agentid_stats->fetch_assoc()['emails_with_agentid'];
echo "\nRemaining NULL NPNs with AgentID: " . number_format($agentid_count) . "\n";

if ($mode === 'analyze') {
    echo "\n💡 To apply these updates, run: php normalize_match_emails.php update\n";
}

echo "\n=== NORMALIZATION " . strtoupper($mode) . " COMPLETE ===\n";

$mysqli->close();
?> 