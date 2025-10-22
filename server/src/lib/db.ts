import mysql from "mysql2/promise";

const { DB_HOST, DB_USER, DB_PASS, TEMPLATE_DB, TEMPLATE_TABLE } = process.env;

// Enable verbose logging
const DEBUG = process.env.DEBUG === '*' || process.env.NODE_ENV === 'development';

function log(message: string, data?: any) {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] [DB] ${message}`);
    if (data && DEBUG) {
        console.log(JSON.stringify(data, null, 2));
    }
}

export async function ensureClientSchema(client: string) {
    log(`🔧 Starting database schema setup for client: ${client}`);

    if (!DB_HOST || !DB_USER || !DB_PASS || !TEMPLATE_DB || !TEMPLATE_TABLE) {
        const missingVars = [];
        if (!DB_HOST) missingVars.push('DB_HOST');
        if (!DB_USER) missingVars.push('DB_USER');
        if (!DB_PASS) missingVars.push('DB_PASS');
        if (!TEMPLATE_DB) missingVars.push('TEMPLATE_DB');
        if (!TEMPLATE_TABLE) missingVars.push('TEMPLATE_TABLE');

        log(`❌ Missing required database environment variables: ${missingVars.join(', ')}`);
        throw new Error(`Missing required database environment variables: ${missingVars.join(', ')}`);
    }

    log("🔌 Connecting to MariaDB...", {
        host: DB_HOST,
        user: DB_USER,
        database: 'root connection'
    });

    const root = await mysql.createConnection({
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        connectTimeout: 30000, // 30 seconds
        namedPlaceholders: false,
        supportBigNumbers: true,
        bigNumberStrings: false
    });

    try {
        log("✅ Connected to MariaDB successfully");

        // Create database if it doesn't exist
        log(`🗄️  Creating database '${client}' if it doesn't exist...`);
        await root.query(`CREATE DATABASE IF NOT EXISTS \`${client}\``);
        log(`✅ Database '${client}' created/verified`);

        // Grant permissions to the database user for this specific database
        log(`🔐 Granting permissions to user '${DB_USER}' on database '${client}'...`);
        await root.execute(`GRANT ALL PRIVILEGES ON \`${client}\`.* TO '${DB_USER}'@'%'`);
        await root.execute(`FLUSH PRIVILEGES`);
        log(`✅ Permissions granted to '${DB_USER}' on database '${client}'`);

        const tablesToClone = ["superpixel_resolution_log", "superpixel_visitors"];

        for (const table of tablesToClone) {
            log(`📋 Creating table '${table}' in database '${client}'...`);
            const createTableQuery = `CREATE TABLE IF NOT EXISTS \`${client}\`.\`${table}\` LIKE \`${TEMPLATE_DB}\`.\`${table}\``;
            log("🔍 Executing query:", createTableQuery);
            await root.execute(createTableQuery);
            log(`✅ Table '${table}' created/verified in database '${client}'`);

            // Verify the table was created successfully
            log("🔍 Verifying table creation...");
            const [rows] = await root.query(
                `SHOW TABLES IN \`${client}\` LIKE '${table}'`
            );
            log("📊 Table verification result:", rows);

            if (Array.isArray(rows) && rows.length === 0) {
                log(`❌ Failed to create table ${table} in database ${client}`);
                throw new Error(`Failed to create table ${table} in database ${client}`);
            }
        }

        // Create the superpixel_emails table for individual email tracking
        log(`📧 Creating superpixel_emails table in database '${client}'...`);
        const emailTableQuery = `
            CREATE TABLE IF NOT EXISTS \`${client}\`.superpixel_emails (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL,
                email_type ENUM('personal', 'business', 'deep_verified') NOT NULL,
                source_column VARCHAR(50),
                source_table ENUM('resolution_log', 'visitors') DEFAULT 'resolution_log',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_uuid_email (uuid, email),
                INDEX idx_email (email),
                INDEX idx_uuid (uuid),
                INDEX idx_email_type (email_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `;
        await root.execute(emailTableQuery);
        log(`✅ superpixel_emails table created/verified in database '${client}'`);

        // Verify the email table was created successfully
        log("🔍 Verifying email table creation...");
        const [emailRows] = await root.query(
            `SHOW TABLES IN \`${client}\` LIKE 'superpixel_emails'`
        );
        log("📊 Email table verification result:", emailRows);

        if (Array.isArray(emailRows) && emailRows.length === 0) {
            log(`❌ Failed to create superpixel_emails table in database ${client}`);
            throw new Error(`Failed to create superpixel_emails table in database ${client}`);
        }

        // Create simple email parsing stored procedure
        log(`🔧 Creating email parsing procedure in database '${client}'...`);

        try {
            // Drop if exists
            await root.execute(`DROP PROCEDURE IF EXISTS \`${client}\`.parse_visitor_emails`);

            // Simple procedure that just parses and inserts emails
            const emailProcedure = `
                CREATE PROCEDURE \`${client}\`.parse_visitor_emails(
                    IN p_uuid VARCHAR(100),
                    IN p_email_string TEXT,
                    IN p_email_type ENUM('personal', 'business', 'deep_verified'),
                    IN p_source_column VARCHAR(50)
                )
                BEGIN
                    DECLARE email_item VARCHAR(255);
                    DECLARE remaining_string TEXT;
                    DECLARE comma_pos INT;
                    
                    SET remaining_string = TRIM(p_email_string);
                    
                    -- Handle NULL or empty string
                    IF remaining_string IS NULL OR remaining_string = '' THEN
                        LEAVE parse_emails;
                    END IF;
                    
                    parse_emails: WHILE LENGTH(remaining_string) > 0 DO
                        -- Find the next comma
                        SET comma_pos = LOCATE(',', remaining_string);
                        
                        IF comma_pos = 0 THEN
                            -- No more commas, this is the last email
                            SET email_item = TRIM(remaining_string);
                            SET remaining_string = '';
                        ELSE
                            -- Extract email before comma
                            SET email_item = TRIM(SUBSTRING(remaining_string, 1, comma_pos - 1));
                            SET remaining_string = TRIM(SUBSTRING(remaining_string, comma_pos + 1));
                        END IF;
                        
                        -- Basic email validation and insert
                        IF email_item REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\\\.[A-Za-z]{2,}$' THEN
                            INSERT IGNORE INTO \`${client}\`.superpixel_emails 
                            (uuid, email, email_type, source_column) 
                            VALUES (p_uuid, email_item, p_email_type, p_source_column);
                        END IF;
                    END WHILE parse_emails;
                END
            `;

            await root.execute(emailProcedure);
            log(`✅ Email parsing procedure created for '${client}'`);
        } catch (procError: any) {
            log(`⚠️ Warning: Could not create email parsing procedure:`, {
                message: procError.message,
                sqlMessage: procError.sqlMessage
            });
            // Continue - we can still use PHP-based parsing
        }

        // Create simple triggers that ONLY parse emails (no NPN/CRD lookup)
        log(`🔧 Creating email parsing triggers in database '${client}'...`);

        try {
            // Drop existing triggers
            await root.execute(`DROP TRIGGER IF EXISTS \`${client}\`.after_resolution_log_insert`);
            await root.execute(`DROP TRIGGER IF EXISTS \`${client}\`.after_visitors_insert`);
            await root.execute(`DROP TRIGGER IF EXISTS \`${client}\`.after_visitors_update`);

            // Trigger for resolution log - parse emails when events are inserted
            const resolutionTrigger = `
                CREATE TRIGGER \`${client}\`.after_resolution_log_insert
                AFTER INSERT ON \`${client}\`.superpixel_resolution_log
                FOR EACH ROW
                BEGIN
                    -- Parse business emails
                    IF NEW.business_email IS NOT NULL AND NEW.business_email != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.business_email, 'business', 'business_email');
                    END IF;
                    
                    -- Parse personal emails
                    IF NEW.personal_emails IS NOT NULL AND NEW.personal_emails != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.personal_emails, 'personal', 'personal_emails');
                    END IF;
                    
                    -- Parse deep verified emails
                    IF NEW.deep_verified_emails IS NOT NULL AND NEW.deep_verified_emails != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.deep_verified_emails, 'deep_verified', 'deep_verified_emails');
                    END IF;
                END
            `;

            // Trigger for visitors insert
            const visitorInsertTrigger = `
                CREATE TRIGGER \`${client}\`.after_visitors_insert
                AFTER INSERT ON \`${client}\`.superpixel_visitors
                FOR EACH ROW
                BEGIN
                    -- Parse business emails
                    IF NEW.business_email IS NOT NULL AND NEW.business_email != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.business_email, 'business', 'business_email');
                    END IF;
                    
                    -- Parse personal emails
                    IF NEW.personal_emails IS NOT NULL AND NEW.personal_emails != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.personal_emails, 'personal', 'personal_emails');
                    END IF;
                    
                    -- Parse deep verified emails
                    IF NEW.deep_verified_emails IS NOT NULL AND NEW.deep_verified_emails != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.deep_verified_emails, 'deep_verified', 'deep_verified_emails');
                    END IF;
                END
            `;

            // Trigger for visitors update - re-parse if emails change
            const visitorUpdateTrigger = `
                CREATE TRIGGER \`${client}\`.after_visitors_update
                AFTER UPDATE ON \`${client}\`.superpixel_visitors
                FOR EACH ROW
                BEGIN
                    -- Only process if email fields changed
                    IF (OLD.business_email != NEW.business_email OR 
                        OLD.personal_emails != NEW.personal_emails OR 
                        OLD.deep_verified_emails != NEW.deep_verified_emails) THEN
                        
                        -- Delete old emails for this UUID
                        DELETE FROM \`${client}\`.superpixel_emails WHERE uuid = NEW.uuid;
                        
                        -- Re-parse all emails
                        IF NEW.business_email IS NOT NULL AND NEW.business_email != '' THEN
                            CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.business_email, 'business', 'business_email');
                        END IF;
                        
                        IF NEW.personal_emails IS NOT NULL AND NEW.personal_emails != '' THEN
                            CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.personal_emails, 'personal', 'personal_emails');
                        END IF;
                        
                        IF NEW.deep_verified_emails IS NOT NULL AND NEW.deep_verified_emails != '' THEN
                            CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.deep_verified_emails, 'deep_verified', 'deep_verified_emails');
                        END IF;
                    END IF;
                END
            `;

            // Create the triggers
            await root.execute(resolutionTrigger);
            log(`✅ Resolution log email parsing trigger created`);

            await root.execute(visitorInsertTrigger);
            log(`✅ Visitor insert email parsing trigger created`);

            await root.execute(visitorUpdateTrigger);
            log(`✅ Visitor update email parsing trigger created`);

        } catch (triggerError: any) {
            log(`⚠️ Warning: Could not create email parsing triggers:`, {
                message: triggerError.message,
                sqlMessage: triggerError.sqlMessage
            });
            // Continue - PHP scripts can still parse emails
        }

        // Create rich visitor update trigger to hydrate superpixel_visitors from superpixel_resolution_log
        log(`🔧 Creating rich visitor update trigger in database '${client}'...`);
        try {
            // Drop if exists
            const dropVisitorTrigger = `DROP TRIGGER IF EXISTS \`${client}\`.after_resolution_log_insert_visitor_update`;
            await root.execute(dropVisitorTrigger);

            // Rich trigger: fully hydrate/update visitor profile and last activity from resolution log
            const richVisitorTrigger =
                "CREATE TRIGGER `" + client + "`.after_resolution_log_insert_visitor_update\n" +
                "AFTER INSERT ON `" + client + "`.superpixel_resolution_log\n" +
                "FOR EACH ROW\n" +
                "BEGIN\n" +
                "  IF NEW.uuid IS NOT NULL AND NEW.uuid <> '' AND NEW.uuid <> 'null' THEN\n" +
                "    IF EXISTS (SELECT 1 FROM `" + client + "`.superpixel_visitors WHERE uuid = NEW.uuid) THEN\n" +
                "      UPDATE `" + client + "`.superpixel_visitors\n" +
                "      SET\n" +
                "        first_name = COALESCE(NULLIF(first_name,''), NEW.first_name),\n" +
                "        last_name  = COALESCE(NULLIF(last_name ,''), NEW.last_name),\n" +
                "        business_email = CASE WHEN business_email IS NOT NULL AND business_email <> '' THEN business_email ELSE NEW.business_email END,\n" +
                "        personal_emails = COALESCE(NULLIF(personal_emails,''), NEW.personal_emails),\n" +
                "        deep_verified_emails = COALESCE(NULLIF(deep_verified_emails,''), NEW.deep_verified_emails),\n" +
                "        sha256_personal_email = COALESCE(NULLIF(sha256_personal_email,''), NEW.sha256_personal_email),\n" +
                "        sha256_business_email = COALESCE(NULLIF(sha256_business_email,''), NEW.sha256_business_email),\n" +
                "        hem_sha256 = COALESCE(NULLIF(hem_sha256,''), NEW.hem_sha256),\n" +
                "        direct_number = COALESCE(NULLIF(direct_number,''), NEW.direct_number),\n" +
                "        direct_number_dnc = COALESCE(NULLIF(direct_number_dnc,''), NEW.direct_number_dnc),\n" +
                "        mobile_phone = COALESCE(NULLIF(mobile_phone,''), NEW.mobile_phone),\n" +
                "        mobile_phone_dnc = COALESCE(NULLIF(mobile_phone_dnc,''), NEW.mobile_phone_dnc),\n" +
                "        personal_phone = COALESCE(NULLIF(personal_phone,''), NEW.personal_phone),\n" +
                "        personal_phone_dnc = COALESCE(NULLIF(personal_phone_dnc,''), NEW.personal_phone_dnc),\n" +
                "        personal_address = COALESCE(NULLIF(personal_address,''), NEW.personal_address),\n" +
                "        personal_city = COALESCE(NULLIF(personal_city,''), NEW.personal_city),\n" +
                "        personal_state = COALESCE(NULLIF(personal_state,''), NEW.personal_state),\n" +
                "        personal_zip = COALESCE(NULLIF(personal_zip,''), NEW.personal_zip),\n" +
                "        personal_zip4 = COALESCE(NULLIF(personal_zip4,''), NEW.personal_zip4),\n" +
                "        age_range = COALESCE(NULLIF(age_range,''), NEW.age_range),\n" +
                "        children = COALESCE(NULLIF(children,''), NEW.children),\n" +
                "        gender = COALESCE(NULLIF(gender,''), NEW.gender),\n" +
                "        homeowner = COALESCE(NULLIF(homeowner,''), NEW.homeowner),\n" +
                "        married = COALESCE(NULLIF(married,''), NEW.married),\n" +
                "        net_worth = COALESCE(NULLIF(net_worth,''), NEW.net_worth),\n" +
                "        income_range = COALESCE(NULLIF(income_range,''), NEW.income_range),\n" +
                "        job_title = COALESCE(NULLIF(job_title,''), NEW.job_title),\n" +
                "        headline = COALESCE(NULLIF(headline,''), NEW.headline),\n" +
                "        department = COALESCE(NULLIF(department,''), NEW.department),\n" +
                "        seniority_level = COALESCE(NULLIF(seniority_level,''), NEW.seniority_level),\n" +
                "        inferred_years_experience = COALESCE(NULLIF(inferred_years_experience,''), NEW.inferred_years_experience),\n" +
                "        company_address = COALESCE(NULLIF(company_address,''), NEW.company_address),\n" +
                "        company_description = COALESCE(NULLIF(company_description,''), NEW.company_description),\n" +
                "        company_domain = COALESCE(NULLIF(company_domain,''), NEW.company_domain),\n" +
                "        company_employee_count = COALESCE(NULLIF(company_employee_count,''), NEW.company_employee_count),\n" +
                "        company_linkedin_url = COALESCE(NULLIF(company_linkedin_url,''), NEW.company_linkedin_url),\n" +
                "        company_name = COALESCE(NULLIF(company_name,''), NEW.company_name),\n" +
                "        company_phone = COALESCE(NULLIF(company_phone,''), NEW.company_phone),\n" +
                "        company_revenue = COALESCE(NULLIF(company_revenue,''), NEW.company_revenue),\n" +
                "        company_sic = COALESCE(NULLIF(company_sic,''), NEW.company_sic),\n" +
                "        company_naics = COALESCE(NULLIF(company_naics,''), NEW.company_naics),\n" +
                "        company_city = COALESCE(NULLIF(company_city,''), NEW.company_city),\n" +
                "        company_state = COALESCE(NULLIF(company_state,''), NEW.company_state),\n" +
                "        company_zip = COALESCE(NULLIF(company_zip,''), NEW.company_zip),\n" +
                "        company_industry = COALESCE(NULLIF(company_industry,''), NEW.company_industry),\n" +
                "        linkedin_url = COALESCE(NULLIF(linkedin_url,''), NEW.linkedin_url),\n" +
                "        twitter_url = COALESCE(NULLIF(twitter_url ,''), NEW.twitter_url),\n" +
                "        facebook_url = COALESCE(NULLIF(facebook_url,''), NEW.facebook_url),\n" +
                "        social_connections = COALESCE(NULLIF(social_connections,''), NEW.social_connections),\n" +
                "        skills = COALESCE(NULLIF(skills,''), NEW.skills),\n" +
                "        interests = COALESCE(NULLIF(interests,''), NEW.interests),\n" +
                "        skiptrace_match_score = COALESCE(NULLIF(skiptrace_match_score,''), NEW.skiptrace_match_score),\n" +
                "        skiptrace_name = COALESCE(NULLIF(skiptrace_name,''), NEW.skiptrace_name),\n" +
                "        skiptrace_address = COALESCE(NULLIF(skiptrace_address,''), NEW.skiptrace_address),\n" +
                "        skiptrace_city = COALESCE(NULLIF(skiptrace_city,''), NEW.skiptrace_city),\n" +
                "        skiptrace_state = COALESCE(NULLIF(skiptrace_state,''), NEW.skiptrace_state),\n" +
                "        skiptrace_zip = COALESCE(NULLIF(skiptrace_zip,''), NEW.skiptrace_zip),\n" +
                "        skiptrace_landline_numbers = COALESCE(NULLIF(skiptrace_landline_numbers,''), NEW.skiptrace_landline_numbers),\n" +
                "        skiptrace_wireless_numbers = COALESCE(NULLIF(skiptrace_wireless_numbers,''), NEW.skiptrace_wireless_numbers),\n" +
                "        skiptrace_credit_rating = COALESCE(NULLIF(skiptrace_credit_rating,''), NEW.skiptrace_credit_rating),\n" +
                "        skiptrace_dnc = COALESCE(NULLIF(skiptrace_dnc,''), NEW.skiptrace_dnc),\n" +
                "        skiptrace_exact_age = COALESCE(NULLIF(skiptrace_exact_age,''), NEW.skiptrace_exact_age),\n" +
                "        skiptrace_ethnic_code = COALESCE(NULLIF(skiptrace_ethnic_code,''), NEW.skiptrace_ethnic_code),\n" +
                "        skiptrace_language_code = COALESCE(NULLIF(skiptrace_language_code,''), NEW.skiptrace_language_code),\n" +
                "        skiptrace_ip = COALESCE(NULLIF(skiptrace_ip,''), NEW.skiptrace_ip),\n" +
                "        skiptrace_b2b_address = COALESCE(NULLIF(skiptrace_b2b_address,''), NEW.skiptrace_b2b_address),\n" +
                "        skiptrace_b2b_phone = COALESCE(NULLIF(skiptrace_b2b_phone,''), NEW.skiptrace_b2b_phone),\n" +
                "        skiptrace_b2b_source = COALESCE(NULLIF(skiptrace_b2b_source,''), NEW.skiptrace_b2b_source),\n" +
                "        skiptrace_b2b_website = COALESCE(NULLIF(skiptrace_b2b_website,''), NEW.skiptrace_b2b_website),\n" +
                "        npn  = COALESCE(NULLIF(npn,''), NEW.npn),\n" +
                "        crd  = COALESCE(NULLIF(crd,''), NEW.crd),\n" +
                "        title = COALESCE(NULLIF(title,''), NEW.title),\n" +
                "        url = CASE WHEN NEW.url IS NOT NULL AND NEW.url <> '' THEN NEW.url ELSE url END,\n" +
                "        element = CASE WHEN NEW.element IS NOT NULL AND NEW.element <> '' THEN NEW.element ELSE element END,\n" +
                "        percentage = CASE WHEN NEW.percentage IS NOT NULL AND NEW.percentage <> '' THEN CAST(NEW.percentage AS SIGNED) ELSE percentage END,\n" +
                "        referrer = CASE WHEN NEW.referrer IS NOT NULL AND NEW.referrer <> '' THEN NEW.referrer ELSE referrer END,\n" +
                "        event_timestamp = CASE WHEN NEW.event_timestamp IS NOT NULL AND NEW.event_timestamp <> '' THEN NEW.event_timestamp ELSE event_timestamp END,\n" +
                "        event_type = CASE WHEN NEW.event_type IS NOT NULL AND NEW.event_type <> '' THEN NEW.event_type ELSE event_type END,\n" +
                "        event_count = event_count + 1,\n" +
                "        last_seen_at = CURRENT_TIMESTAMP\n" +
                "      WHERE uuid = NEW.uuid;\n" +
                "    ELSE\n" +
                "      INSERT INTO `" + client + "`.superpixel_visitors (\n" +
                "        uuid, first_name, last_name,\n" +
                "        personal_address, personal_city, personal_state, personal_zip, personal_zip4,\n" +
                "        age_range, children, gender, homeowner, married, net_worth, income_range,\n" +
                "        direct_number, direct_number_dnc, mobile_phone, mobile_phone_dnc, personal_phone, personal_phone_dnc,\n" +
                "        business_email, personal_emails, deep_verified_emails, sha256_personal_email, sha256_business_email,\n" +
                "        hem_sha256, job_title, headline, department, seniority_level, inferred_years_experience,\n" +
                "        company_address, company_description, company_domain, company_employee_count, company_linkedin_url,\n" +
                "        company_name, company_phone, company_revenue, company_sic, company_naics, company_city, company_state, company_zip, company_industry,\n" +
                "        linkedin_url, twitter_url, facebook_url, social_connections, skills, interests,\n" +
                "        skiptrace_match_score, skiptrace_name, skiptrace_address, skiptrace_city, skiptrace_state, skiptrace_zip,\n" +
                "        skiptrace_landline_numbers, skiptrace_wireless_numbers, skiptrace_credit_rating, skiptrace_dnc, skiptrace_exact_age, skiptrace_ethnic_code, skiptrace_language_code, skiptrace_ip,\n" +
                "        url, element, percentage, referrer, event_timestamp, event_type,\n" +
                "        event_count, first_seen_at, last_seen_at, npn, crd, title\n" +
                "      ) VALUES (\n" +
                "        NEW.uuid, NEW.first_name, NEW.last_name,\n" +
                "        NEW.personal_address, NEW.personal_city, NEW.personal_state, NEW.personal_zip, NEW.personal_zip4,\n" +
                "        NEW.age_range, NEW.children, NEW.gender, NEW.homeowner, NEW.married, NEW.net_worth, NEW.income_range,\n" +
                "        NEW.direct_number, NEW.direct_number_dnc, NEW.mobile_phone, NEW.mobile_phone_dnc, NEW.personal_phone, NEW.personal_phone_dnc,\n" +
                "        NEW.business_email, NEW.personal_emails, NEW.deep_verified_emails, NEW.sha256_personal_email, NEW.sha256_business_email,\n" +
                "        NEW.hem_sha256, NEW.job_title, NEW.headline, NEW.department, NEW.seniority_level, NEW.inferred_years_experience,\n" +
                "        NEW.company_address, NEW.company_description, NEW.company_domain, NEW.company_employee_count, NEW.company_linkedin_url,\n" +
                "        NEW.company_name, NEW.company_phone, NEW.company_revenue, NEW.company_sic, NEW.company_naics, NEW.company_city, NEW.company_state, NEW.company_zip, NEW.company_industry,\n" +
                "        NEW.linkedin_url, NEW.twitter_url, NEW.facebook_url, NEW.social_connections, NEW.skills, NEW.interests,\n" +
                "        NEW.skiptrace_match_score, NEW.skiptrace_name, NEW.skiptrace_address, NEW.skiptrace_city, NEW.skiptrace_state, NEW.skiptrace_zip,\n" +
                "        NEW.skiptrace_landline_numbers, NEW.skiptrace_wireless_numbers, NEW.skiptrace_credit_rating, NEW.skiptrace_dnc, NEW.skiptrace_exact_age, NEW.skiptrace_ethnic_code, NEW.skiptrace_language_code, NEW.skiptrace_ip,\n" +
                "        NEW.url, NEW.element, CASE WHEN NEW.percentage IS NOT NULL AND NEW.percentage <> '' THEN CAST(NEW.percentage AS SIGNED) ELSE NULL END, NEW.referrer, NEW.event_timestamp, NEW.event_type,\n" +
                "        1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NEW.npn, NEW.crd, NEW.title\n" +
                "      );\n" +
                "    END IF;\n" +
                "  END IF;\n" +
                "END";

            await root.execute(richVisitorTrigger);
            log(`✅ Rich visitor update trigger created`);
        } catch (visitorTriggerError: any) {
            log(`⚠️ Warning: Could not create rich visitor update trigger:`, {
                message: visitorTriggerError.message,
                sqlMessage: visitorTriggerError.sqlMessage
            });
        }

        // Note: NPN/CRD lookup will be handled by PHP scripts
        log(`ℹ️  Note: NPN/CRD lookup will be handled by PHP scripts (process_visitor_emails.php)`);

        log(`🎉 Database schema setup completed for client: ${client}`);
    } catch (error: any) {
        log("💥 Database operation failed:", {
            error: error.message,
            code: error.code,
            errno: error.errno,
            sqlState: error.sqlState,
            sqlMessage: error.sqlMessage
        });
        throw error;
    } finally {
        log("🔌 Closing database connection...");
        await root.end();
        log("✅ Database connection closed");
    }
} 
