<?php
/**
 * Analyze match_emails table to understand data patterns and optimize matching
 */

// Database configuration
$host = '34.31.66.104';
$user = 'root';
$pass = 'AccuPoint01!';
$dbname = 'accupoint_solutions';

$mysqli = new mysqli($host, $user, $pass, $dbname);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

echo "=== MATCH_EMAILS TABLE ANALYSIS ===\n\n";

// 1. Total statistics
$result = $mysqli->query("SELECT COUNT(*) as total, 
                                COUNT(DISTINCT Email) as unique_emails,
                                COUNT(DISTINCT CRD) as unique_crds,
                                COUNT(DISTINCT NPN) as unique_npns,
                                COUNT(DISTINCT AgentID) as unique_agents
                         FROM match_emails");
$stats = $result->fetch_assoc();

echo "📊 OVERALL STATISTICS:\n";
echo "Total Rows: " . number_format($stats['total']) . "\n";
echo "Unique Emails: " . number_format($stats['unique_emails']) . "\n";
echo "Unique CRDs: " . number_format($stats['unique_crds']) . "\n";
echo "Unique NPNs: " . number_format($stats['unique_npns']) . "\n";
echo "Unique AgentIDs: " . number_format($stats['unique_agents']) . "\n\n";

// 2. NPN Coverage Analysis
$result = $mysqli->query("SELECT 
                            COUNT(*) as total_rows,
                            COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) as rows_with_npn,
                            COUNT(CASE WHEN NPN IS NULL THEN 1 END) as rows_without_npn,
                            ROUND(COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as npn_coverage_pct
                         FROM match_emails");
$npn_coverage = $result->fetch_assoc();

echo "📈 NPN COVERAGE:\n";
echo "Rows with NPN: " . number_format($npn_coverage['rows_with_npn']) . " (" . $npn_coverage['npn_coverage_pct'] . "%)\n";
echo "Rows without NPN: " . number_format($npn_coverage['rows_without_npn']) . "\n\n";

// 3. CRD to NPN Analysis
$result = $mysqli->query("SELECT 
                            COUNT(DISTINCT CRD) as total_crds,
                            COUNT(DISTINCT CASE WHEN has_npn > 0 THEN CRD END) as crds_with_npn,
                            COUNT(DISTINCT CASE WHEN has_npn = 0 THEN CRD END) as crds_without_npn
                         FROM (
                            SELECT CRD, MAX(CASE WHEN NPN IS NOT NULL THEN 1 ELSE 0 END) as has_npn
                            FROM match_emails
                            WHERE CRD IS NOT NULL
                            GROUP BY CRD
                         ) crd_summary");
$crd_analysis = $result->fetch_assoc();

echo "🔗 CRD TO NPN MAPPING:\n";
echo "CRDs with at least one NPN: " . number_format($crd_analysis['crds_with_npn']) . "\n";
echo "CRDs with no NPN: " . number_format($crd_analysis['crds_without_npn']) . "\n";
echo "Success Rate: " . round($crd_analysis['crds_with_npn'] * 100.0 / $crd_analysis['total_crds'], 2) . "%\n\n";

// 4. Multi-email advisors
$result = $mysqli->query("SELECT email_count, COUNT(*) as advisor_count
                         FROM (
                            SELECT CRD, COUNT(DISTINCT Email) as email_count
                            FROM match_emails
                            WHERE CRD IS NOT NULL
                            GROUP BY CRD
                         ) email_counts
                         GROUP BY email_count
                         ORDER BY email_count
                         LIMIT 10");

echo "📧 EMAILS PER ADVISOR (by CRD):\n";
while ($row = $result->fetch_assoc()) {
    echo $row['email_count'] . " email(s): " . number_format($row['advisor_count']) . " advisors\n";
}
echo "\n";

// 5. Example of multi-step lookup benefit
$result = $mysqli->query("SELECT COUNT(*) as enhanced_matches
                         FROM (
                            SELECT DISTINCT m1.Email
                            FROM match_emails m1
                            LEFT JOIN match_emails m2 ON m1.CRD = m2.CRD AND m2.NPN IS NOT NULL
                            WHERE m1.NPN IS NULL AND m2.NPN IS NOT NULL
                         ) enhanced");
$enhanced = $result->fetch_assoc();

echo "✨ MULTI-STEP LOOKUP BENEFIT:\n";
echo "Additional NPNs found via CRD lookup: " . number_format($enhanced['enhanced_matches']) . "\n\n";

// 6. AgentID Analysis
$result = $mysqli->query("SELECT 
                            COUNT(DISTINCT CASE WHEN AgentID IS NOT NULL AND NPN IS NULL THEN Email END) as emails_with_agentid_no_npn,
                            COUNT(DISTINCT CASE WHEN a2.NPN IS NOT NULL THEN a1.Email END) as npns_via_agentid
                         FROM match_emails a1
                         LEFT JOIN match_emails a2 ON a1.AgentID = a2.AgentID AND a2.NPN IS NOT NULL
                         WHERE a1.AgentID IS NOT NULL AND a1.NPN IS NULL");
$agentid_analysis = $result->fetch_assoc();

echo "🆔 AGENTID LOOKUP BENEFIT:\n";
echo "Emails with AgentID but no NPN: " . number_format($agentid_analysis['emails_with_agentid_no_npn']) . "\n";
echo "Additional NPNs found via AgentID: " . number_format($agentid_analysis['npns_via_agentid']) . "\n\n";

// 7. Sample problematic case
echo "📋 SAMPLE CASE (James Bockenek example):\n";
$result = $mysqli->query("SELECT * FROM match_emails WHERE CRD = '24504' ORDER BY Email");
if ($result->num_rows > 0) {
    echo "CRD | NPN | AgentID | Email\n";
    echo str_repeat("-", 80) . "\n";
    while ($row = $result->fetch_assoc()) {
        echo $row['CRD'] . " | " . 
             ($row['NPN'] ?: 'NULL') . " | " . 
             ($row['AgentID'] ?: 'NULL') . " | " . 
             $row['Email'] . "\n";
    }
} else {
    // Try another example
    $result = $mysqli->query("SELECT CRD, COUNT(*) as cnt, 
                                    COUNT(DISTINCT NPN) as npn_count,
                                    COUNT(CASE WHEN NPN IS NOT NULL THEN 1 END) as rows_with_npn
                             FROM match_emails 
                             WHERE CRD IS NOT NULL
                             GROUP BY CRD 
                             HAVING cnt > 2 AND rows_with_npn > 0 AND rows_with_npn < cnt
                             LIMIT 1");
    if ($example = $result->fetch_assoc()) {
        $crd = $example['CRD'];
        $result2 = $mysqli->query("SELECT * FROM match_emails WHERE CRD = '$crd' ORDER BY Email");
        echo "Example CRD $crd with partial NPN coverage:\n";
        echo "CRD | NPN | AgentID | Email\n";
        echo str_repeat("-", 80) . "\n";
        while ($row = $result2->fetch_assoc()) {
            echo $row['CRD'] . " | " . 
                 ($row['NPN'] ?: 'NULL') . " | " . 
                 ($row['AgentID'] ?: 'NULL') . " | " . 
                 $row['Email'] . "\n";
        }
    }
}

echo "\n=== ANALYSIS COMPLETE ===\n";

$mysqli->close();
?> 