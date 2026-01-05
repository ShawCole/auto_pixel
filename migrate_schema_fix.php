<?php
// migrate_schema_fix.php - Comprehensive schema migration script
// This script migrates all databases to the corrected schema

$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

function log_message($message) {
    echo "[" . date('Y-m-d H:i:s') . "] $message\n";
}

function migrateDatabase($dbName) {
    global $dbHost, $dbUser, $dbPass;
    
    log_message("🔄 Starting migration for database: $dbName");
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_error) {
        log_message("❌ Failed to connect to $dbName: " . $mysqli->connect_error);
        return false;
    }
    
    // Check if database has the problematic columns
    $checkSql = "SELECT COUNT(*) as count FROM information_schema.columns 
                 WHERE table_schema = ? AND table_name = 'superpixel_visitors' 
                 AND column_name = 'last_visited_url'";
    $stmt = $mysqli->prepare($checkSql);
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $result = $stmt->get_result();
    $hasNewColumns = $result->fetch_assoc()['count'] > 0;
    $stmt->close();
    
    if (!$hasNewColumns) {
        log_message("⚠️  Skipping $dbName - doesn't have new columns (old schema)");
        $mysqli->close();
        return 'skipped';
    }
    
    try {
        // Step 1: Create new tables with correct schema
        log_message("  → Creating superpixel_visitors_new...");
        
        $createVisitorsNew = "
        CREATE TABLE superpixel_visitors_new (
            uuid varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci NOT NULL,
            first_name varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            last_name varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            personal_address text CHARACTER SET utf32 COLLATE utf32_general_ci,
            personal_city varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            personal_state varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            personal_zip varchar(20) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            personal_zip4 varchar(10) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            age_range varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            children varchar(10) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            gender varchar(20) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            homeowner varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            married varchar(10) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            net_worth varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            income_range varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            direct_number text CHARACTER SET utf32 COLLATE utf32_general_ci,
            direct_number_dnc varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            mobile_phone text CHARACTER SET utf32 COLLATE utf32_general_ci,
            mobile_phone_dnc varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            personal_phone text CHARACTER SET utf32 COLLATE utf32_general_ci,
            personal_phone_dnc varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            business_email text CHARACTER SET utf32 COLLATE utf32_general_ci,
            personal_emails text CHARACTER SET utf32 COLLATE utf32_general_ci,
            deep_verified_emails text CHARACTER SET utf32 COLLATE utf32_general_ci,
            sha256_personal_email longtext CHARACTER SET utf32 COLLATE utf32_general_ci,
            sha256_business_email longtext CHARACTER SET utf32 COLLATE utf32_general_ci,
            hem_sha256 text CHARACTER SET utf32 COLLATE utf32_general_ci,
            job_title text CHARACTER SET utf32 COLLATE utf32_general_ci,
            headline text CHARACTER SET utf32 COLLATE utf32_general_ci,
            department varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            seniority_level varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            inferred_years_experience varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_name_history text CHARACTER SET utf32 COLLATE utf32_general_ci,
            job_title_history text CHARACTER SET utf32 COLLATE utf32_general_ci,
            education_history text CHARACTER SET utf32 COLLATE utf32_general_ci,
            company_address text CHARACTER SET utf32 COLLATE utf32_general_ci,
            company_description text CHARACTER SET utf32 COLLATE utf32_general_ci,
            company_domain varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_employee_count varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_linkedin_url text CHARACTER SET utf32 COLLATE utf32_general_ci,
            company_name varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_phone varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_revenue varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_sic varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_naics varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_city varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_state varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_zip varchar(20) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            company_industry varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            linkedin_url text CHARACTER SET utf32 COLLATE utf32_general_ci,
            twitter_url text CHARACTER SET utf32 COLLATE utf32_general_ci,
            facebook_url text CHARACTER SET utf32 COLLATE utf32_general_ci,
            social_connections text CHARACTER SET utf32 COLLATE utf32_general_ci,
            skills text CHARACTER SET utf32 COLLATE utf32_general_ci,
            interests text CHARACTER SET utf32 COLLATE utf32_general_ci,
            skiptrace_match_score varchar(10) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_name varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_address text CHARACTER SET utf32 COLLATE utf32_general_ci,
            skiptrace_city varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_state varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_zip varchar(20) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_landline_numbers text CHARACTER SET utf32 COLLATE utf32_general_ci,
            skiptrace_wireless_numbers text CHARACTER SET utf32 COLLATE utf32_general_ci,
            skiptrace_credit_rating varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_dnc varchar(10) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_exact_age varchar(10) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_ethnic_code varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_language_code varchar(50) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_ip varchar(45) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_b2b_address text CHARACTER SET utf32 COLLATE utf32_general_ci,
            skiptrace_b2b_phone varchar(20) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            skiptrace_b2b_source text CHARACTER SET utf32 COLLATE utf32_general_ci,
            skiptrace_b2b_website text CHARACTER SET utf32 COLLATE utf32_general_ci,
            valid_phones text CHARACTER SET utf32 COLLATE utf32_general_ci,
            first_seen_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            event_count int DEFAULT 0,
                         url text CHARACTER SET utf32 COLLATE utf32_general_ci,
             element text CHARACTER SET utf32 COLLATE utf32_general_ci,
             percentage int DEFAULT NULL,
             referrer text CHARACTER SET utf32 COLLATE utf32_general_ci,
             event_timestamp varchar(100) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
             event_type varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            npn varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            crd varchar(255) CHARACTER SET utf32 COLLATE utf32_general_ci DEFAULT NULL,
            PRIMARY KEY (uuid),
            KEY idx_uuid (uuid),
            KEY idx_hem_sha256 (hem_sha256(255)),
            KEY idx_sha256_personal_email (sha256_personal_email(255)),
            KEY idx_company_domain (company_domain),
            KEY idx_name (last_name, first_name),
            KEY idx_last_seen_at (last_seen_at),
            KEY idx_npn (npn),
            KEY idx_crd (crd)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf32;
        ";
        
        if (!$mysqli->query($createVisitorsNew)) {
            throw new Exception("Failed to create superpixel_visitors_new: " . $mysqli->error);
        }
        
        // Step 2: Migrate data with proper type conversion
        log_message("  → Migrating visitor data...");
        
        $migrateSql = "
        INSERT INTO superpixel_visitors_new 
        SELECT 
            uuid, first_name, last_name, personal_address, personal_city, personal_state, 
            personal_zip, personal_zip4, age_range, children, gender, homeowner, married, 
            net_worth, income_range, direct_number, direct_number_dnc, mobile_phone, 
            mobile_phone_dnc, personal_phone, personal_phone_dnc, business_email, 
            personal_emails, deep_verified_emails, sha256_personal_email, sha256_business_email,
            hem_sha256, job_title, headline, department, seniority_level, inferred_years_experience,
            company_name_history, job_title_history, education_history, company_address, 
            company_description, company_domain, company_employee_count, company_linkedin_url,
            company_name, company_phone, company_revenue, company_sic, company_naics, 
            company_city, company_state, company_zip, company_industry, linkedin_url, 
            twitter_url, facebook_url, social_connections, skills, interests, 
            skiptrace_match_score, skiptrace_name, skiptrace_address, skiptrace_city, 
            skiptrace_state, skiptrace_zip, skiptrace_landline_numbers, skiptrace_wireless_numbers,
            skiptrace_credit_rating, skiptrace_dnc, skiptrace_exact_age, skiptrace_ethnic_code,
            skiptrace_language_code, skiptrace_ip, skiptrace_b2b_address, skiptrace_b2b_phone,
            skiptrace_b2b_source, skiptrace_b2b_website, valid_phones,
                         first_seen_at, last_seen_at, 
             COALESCE(event_count, 0),
             last_visited_url as url,
             last_element as element,
             last_percentage as percentage,
             last_referrer as referrer,
             last_timestamp as event_timestamp,
             COALESCE(last_event, last_event_type) as event_type,
            npn, crd
        FROM superpixel_visitors
        ";
        
        if (!$mysqli->query($migrateSql)) {
            throw new Exception("Failed to migrate visitor data: " . $mysqli->error);
        }
        
        // Step 3: Create new resolution log table (remove elements column)
        log_message("  → Creating superpixel_resolution_log_new...");
        
        $createResolutionNew = "
        CREATE TABLE superpixel_resolution_log_new LIKE superpixel_resolution_log;
        ";
        
        if (!$mysqli->query($createResolutionNew)) {
            throw new Exception("Failed to create superpixel_resolution_log_new: " . $mysqli->error);
        }
        
        // Remove the redundant 'elements' column
        $dropElements = "ALTER TABLE superpixel_resolution_log_new DROP COLUMN IF EXISTS elements";
        $mysqli->query($dropElements); // Don't fail if column doesn't exist
        
        // Step 4: Migrate resolution log data
        log_message("  → Migrating resolution log data...");
        
        // Get column list excluding 'elements'
        $columnsResult = $mysqli->query("SHOW COLUMNS FROM superpixel_resolution_log_new");
        $columns = [];
        while ($row = $columnsResult->fetch_assoc()) {
            if ($row['Field'] !== 'elements') {
                $columns[] = $row['Field'];
            }
        }
        $columnList = implode(', ', $columns);
        
        $migrateResolution = "INSERT INTO superpixel_resolution_log_new ($columnList) 
                             SELECT $columnList FROM superpixel_resolution_log";
        
        if (!$mysqli->query($migrateResolution)) {
            throw new Exception("Failed to migrate resolution log data: " . $mysqli->error);
        }
        
        // Step 5: Atomic table swap
        log_message("  → Performing atomic table swap...");
        
        $mysqli->autocommit(false);
        
        try {
            // Drop old tables and rename new ones
            $mysqli->query("DROP TABLE superpixel_visitors");
            $mysqli->query("DROP TABLE superpixel_resolution_log");
            $mysqli->query("RENAME TABLE superpixel_visitors_new TO superpixel_visitors");
            $mysqli->query("RENAME TABLE superpixel_resolution_log_new TO superpixel_resolution_log");
            
            $mysqli->commit();
            log_message("  ✅ Migration completed successfully for $dbName");
            
        } catch (Exception $e) {
            $mysqli->rollback();
            throw $e;
        }
        
        $mysqli->autocommit(true);
        $mysqli->close();
        return true;
        
    } catch (Exception $e) {
        log_message("  ❌ Migration failed for $dbName: " . $e->getMessage());
        $mysqli->close();
        return false;
    }
}

// Main execution
log_message("🚀 COMPREHENSIVE SCHEMA MIGRATION STARTED");
log_message("=====================================");

// Get list of databases to migrate
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
if ($mysqli->connect_error) {
    die("Failed to connect to pixel database: " . $mysqli->connect_error);
}

$result = $mysqli->query("SELECT DISTINCT client_name FROM pixel_sheets WHERE sheet_id IS NOT NULL");
$databases = [];
while ($row = $result->fetch_assoc()) {
    $databases[] = $row['client_name'];
}

// Add the template database
$databases[] = 'pixel';

$mysqli->close();

log_message("📊 Found " . count($databases) . " databases to migrate");

$successCount = 0;
$skippedCount = 0;
$failedCount = 0;

foreach ($databases as $db) {
    $result = migrateDatabase($db);
    if ($result === true) {
        $successCount++;
    } elseif ($result === 'skipped') {
        $skippedCount++;
    } else {
        $failedCount++;
    }
    
    sleep(1); // Brief pause between migrations
}

log_message("=====================================");
log_message("🎉 MIGRATION COMPLETED!");
log_message("📊 Results:");
log_message("   ✅ $successCount migrated successfully");
log_message("   ⚠️  $skippedCount skipped (old schema)");
log_message("   ❌ $failedCount failed");

if ($successCount > 0) {
    log_message("✅ Schema migration successful!");
    exit(0);
} else {
    log_message("❌ No databases were migrated");
    exit(1);
}
?> 