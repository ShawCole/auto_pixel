<?php
// Script to analyze new columns from AudienceLab webhook data
// and generate SQL to add missing columns to all client databases

$newFieldsFromWebhook = [
    // Personal Demographics
    'age_range' => 'VARCHAR(100)',
    'children' => 'VARCHAR(10)',
    'gender' => 'VARCHAR(20)',
    'homeowner' => 'VARCHAR(255)',
    'married' => 'VARCHAR(10)',
    'net_worth' => 'VARCHAR(100)',
    'income_range' => 'VARCHAR(100)',
    'personal_zip4' => 'VARCHAR(10)',
    
    // Contact Information
    'direct_number' => 'TEXT',
    'direct_number_dnc' => 'VARCHAR(255)',
    'mobile_phone_dnc' => 'VARCHAR(255)',
    'personal_phone_dnc' => 'VARCHAR(255)',
    'sha256_personal_email' => 'LONGTEXT',
    'sha256_business_email' => 'LONGTEXT',
    'deep_verified_emails' => 'TEXT',
    
    // Professional Information
    'headline' => 'TEXT',
    'department' => 'VARCHAR(100)',
    'seniority_level' => 'VARCHAR(50)',
    'inferred_years_experience' => 'VARCHAR(50)',
    'company_name_history' => 'TEXT',
    'job_title_history' => 'TEXT',
    'education_history' => 'TEXT',
    'skills' => 'TEXT',
    'interests' => 'TEXT',
    
    // Company Information
    'company_address' => 'TEXT',
    'company_description' => 'TEXT',
    'company_domain' => 'VARCHAR(100)',
    'company_employee_count' => 'VARCHAR(50)',
    'company_phone' => 'VARCHAR(255)',
    'company_revenue' => 'VARCHAR(100)',
    'company_sic' => 'VARCHAR(50)',
    'company_naics' => 'VARCHAR(50)',
    'company_city' => 'VARCHAR(100)',
    'company_state' => 'VARCHAR(50)',
    'company_zip' => 'VARCHAR(20)',
    'company_industry' => 'VARCHAR(100)',
    
    // Social Media
    'linkedin_url' => 'TEXT',
    'facebook_url' => 'TEXT',
    'social_connections' => 'TEXT',
    
    // Skiptrace Information
    'skiptrace_match_score' => 'VARCHAR(10)',
    'skiptrace_name' => 'VARCHAR(100)',
    'skiptrace_address' => 'TEXT',
    'skiptrace_city' => 'VARCHAR(100)',
    'skiptrace_state' => 'VARCHAR(50)',
    'skiptrace_zip' => 'VARCHAR(20)',
    'skiptrace_landline_numbers' => 'TEXT',
    'skiptrace_wireless_numbers' => 'TEXT',
    'skiptrace_credit_rating' => 'VARCHAR(50)',
    'skiptrace_dnc' => 'VARCHAR(10)',
    'skiptrace_exact_age' => 'VARCHAR(10)',
    'skiptrace_ethnic_code' => 'VARCHAR(50)',
    'skiptrace_language_code' => 'VARCHAR(50)',
    'skiptrace_ip' => 'VARCHAR(45)',
    'skiptrace_b2b_address' => 'TEXT',
    'skiptrace_b2b_phone' => 'VARCHAR(20)',
    'skiptrace_b2b_source' => 'TEXT',
    'skiptrace_b2b_website' => 'TEXT',
    
    // Activity/Event Data
    'activity_start_date' => 'VARCHAR(100)',
    'activity_end_date' => 'VARCHAR(100)',
    'referrer_url' => 'TEXT',
    
    // CRITICAL: License fields
    'npn' => 'VARCHAR(255)',
    'crd' => 'VARCHAR(255)'
];

// Database configuration
$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

// List of client databases to update
$clientDatabases = [
    'AcquireUp',
    'CountryLifeFinancial',
    'Emerge',
    'FocusFinancialGroup',
    'HorizonFinancial',
    'MidWestFinancialGroup',
    'SellMax',
    'ThirdCoastAgency',
    'UltimateLegacy',
    'UniversalCapital'
];

try {
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== ANALYZING DATABASE SCHEMAS ===\n\n";
    
    $alterStatements = [];
    
    foreach ($clientDatabases as $database) {
        echo "Checking database: $database\n";
        echo str_repeat('-', 40) . "\n";
        
        // Get existing columns
        $stmt = $pdo->query("
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = '$database' 
            AND TABLE_NAME = 'superpixel_resolution_log'
        ");
        $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($existingColumns)) {
            echo "  ⚠️  Table superpixel_resolution_log not found\n\n";
            continue;
        }
        
        $missingColumns = [];
        foreach ($newFieldsFromWebhook as $column => $type) {
            if (!in_array($column, $existingColumns)) {
                $missingColumns[$column] = $type;
            }
        }
        
        if (empty($missingColumns)) {
            echo "  ✅ All columns already exist\n\n";
        } else {
            echo "  Missing columns:\n";
            $alterStatement = "ALTER TABLE `$database`.`superpixel_resolution_log`\n";
            $addClauses = [];
            
            foreach ($missingColumns as $column => $type) {
                echo "    - $column ($type)\n";
                $addClauses[] = "  ADD COLUMN `$column` $type DEFAULT NULL";
                
                // Add indexes for important lookup fields
                if (in_array($column, ['npn', 'crd', 'company_domain', 'sha256_personal_email'])) {
                    $addClauses[] = "  ADD INDEX `idx_$column` (`$column`(100))";
                }
            }
            
            if (!empty($addClauses)) {
                $alterStatement .= implode(",\n", $addClauses) . ";";
                $alterStatements[$database] = $alterStatement;
            }
            echo "\n";
        }
    }
    
    // Generate SQL file with all ALTER statements
    if (!empty($alterStatements)) {
        $sqlContent = "-- SQL Script to add new columns from AudienceLab\n";
        $sqlContent .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($alterStatements as $database => $sql) {
            $sqlContent .= "-- Database: $database\n";
            $sqlContent .= $sql . "\n\n";
        }
        
        file_put_contents('add_new_columns.sql', $sqlContent);
        echo "\n=== SQL SCRIPT GENERATED ===\n";
        echo "Saved to: add_new_columns.sql\n";
        
        // Also check superpixel_visitors table
        echo "\n=== CHECKING superpixel_visitors TABLE ===\n\n";
        
        $visitorAlterStatements = [];
        foreach ($clientDatabases as $database) {
            echo "Checking visitors table in: $database\n";
            
            $stmt = $pdo->query("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = '$database' 
                AND TABLE_NAME = 'superpixel_visitors'
            ");
            $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($existingColumns)) {
                echo "  ⚠️  Table superpixel_visitors not found\n\n";
                continue;
            }
            
            // For visitors table, we'll add a subset of the most important fields
            $visitorFields = [
                'npn' => 'VARCHAR(255)',
                'crd' => 'VARCHAR(255)',
                'age_range' => 'VARCHAR(100)',
                'gender' => 'VARCHAR(20)',
                'income_range' => 'VARCHAR(100)',
                'net_worth' => 'VARCHAR(100)',
                'company_name' => 'VARCHAR(255)',
                'job_title' => 'TEXT',
                'company_industry' => 'VARCHAR(100)'
            ];
            
            $missingColumns = [];
            foreach ($visitorFields as $column => $type) {
                if (!in_array($column, $existingColumns)) {
                    $missingColumns[$column] = $type;
                }
            }
            
            if (!empty($missingColumns)) {
                $alterStatement = "ALTER TABLE `$database`.`superpixel_visitors`\n";
                $addClauses = [];
                
                foreach ($missingColumns as $column => $type) {
                    $addClauses[] = "  ADD COLUMN `$column` $type DEFAULT NULL";
                    
                    if (in_array($column, ['npn', 'crd'])) {
                        $addClauses[] = "  ADD INDEX `idx_$column` (`$column`(100))";
                    }
                }
                
                $alterStatement .= implode(",\n", $addClauses) . ";";
                $visitorAlterStatements[$database] = $alterStatement;
            }
        }
        
        if (!empty($visitorAlterStatements)) {
            $visitorSqlContent = "-- SQL Script to add new columns to superpixel_visitors\n";
            $visitorSqlContent .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            
            foreach ($visitorAlterStatements as $database => $sql) {
                $visitorSqlContent .= "-- Database: $database\n";
                $visitorSqlContent .= $sql . "\n\n";
            }
            
            file_put_contents('add_visitor_columns.sql', $visitorSqlContent);
            echo "\nVisitor table SQL saved to: add_visitor_columns.sql\n";
        }
    }
    
    echo "\n=== SUMMARY ===\n";
    echo "Total new fields available: " . count($newFieldsFromWebhook) . "\n";
    echo "Databases checked: " . count($clientDatabases) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 