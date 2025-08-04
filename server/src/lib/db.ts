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

        // Create email parsing stored procedure
        log(`🔧 Creating email parsing procedure in database '${client}'...`);

        try {
            // First drop if exists
            await root.execute(`DROP PROCEDURE IF EXISTS \`${client}\`.parse_visitor_emails`);

            // Create the procedure with proper delimiters
            const emailParsingProcedure = `
                DELIMITER $$
                CREATE PROCEDURE \`${client}\`.parse_visitor_emails(
                    IN p_uuid VARCHAR(100),
                    IN p_email_string TEXT,
                    IN p_email_type ENUM('personal', 'business', 'deep_verified'),
                    IN p_source_column VARCHAR(50)
                )
                BEGIN
                    DECLARE done INT DEFAULT FALSE;
                    DECLARE email_item VARCHAR(255);
                    DECLARE email_cursor CURSOR FOR 
                        SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(p_email_string, ',', n.digit+1), ',', -1)) as email
                        FROM (SELECT 0 as digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 
                              UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 
                              UNION ALL SELECT 8 UNION ALL SELECT 9) n
                        WHERE CHAR_LENGTH(p_email_string) - CHAR_LENGTH(REPLACE(p_email_string, ',', '')) >= n.digit
                           OR n.digit = 0;
                    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
                    
                    IF p_email_string IS NOT NULL AND p_email_string != '' THEN
                        OPEN email_cursor;
                        read_loop: LOOP
                            FETCH email_cursor INTO email_item;
                            IF done THEN
                                LEAVE read_loop;
                            END IF;
                            
                            SET email_item = TRIM(email_item);
                            
                            -- Validate email format
                            IF email_item REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\\\.[A-Za-z]{2,}$' AND email_item != '' THEN
                                INSERT IGNORE INTO \`${client}\`.superpixel_emails 
                                (uuid, email, email_type, source_column) 
                                VALUES (p_uuid, email_item, p_email_type, p_source_column);
                            END IF;
                        END LOOP;
                        CLOSE email_cursor;
                    END IF;
                END$$
                DELIMITER ;
            `;

            // Execute procedure creation in parts due to DELIMITER limitations
            await root.execute(`DROP PROCEDURE IF EXISTS \`${client}\`.parse_visitor_emails`);

            // Simplified procedure without complex cursor logic for now
            const simpleProcedure = `
                CREATE PROCEDURE \`${client}\`.parse_visitor_emails(
                    IN p_uuid VARCHAR(100),
                    IN p_email_string TEXT,
                    IN p_email_type ENUM('personal', 'business', 'deep_verified'),
                    IN p_source_column VARCHAR(50)
                )
                BEGIN
                    DECLARE email_item VARCHAR(255);
                    DECLARE pos INT DEFAULT 1;
                    DECLARE comma_pos INT;
                    
                    IF p_email_string IS NOT NULL AND p_email_string != '' THEN
                        -- Simple loop to split by comma
                        WHILE pos <= CHAR_LENGTH(p_email_string) DO
                            SET comma_pos = LOCATE(',', p_email_string, pos);
                            IF comma_pos = 0 THEN
                                SET comma_pos = CHAR_LENGTH(p_email_string) + 1;
                            END IF;
                            
                            SET email_item = TRIM(SUBSTRING(p_email_string, pos, comma_pos - pos));
                            
                            IF email_item != '' AND email_item REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\\\.[A-Za-z]{2,}$' THEN
                                INSERT IGNORE INTO \`${client}\`.superpixel_emails 
                                (uuid, email, email_type, source_column) 
                                VALUES (p_uuid, email_item, p_email_type, p_source_column);
                            END IF;
                            
                            SET pos = comma_pos + 1;
                        END WHILE;
                    END IF;
                END
            `;

            await root.execute(simpleProcedure);
            log(`✅ Email parsing procedure created for '${client}'`);
        } catch (procError: any) {
            log(`❌ ERROR: Could not create email parsing procedure for '${client}':`, {
                message: procError.message,
                code: procError.code,
                errno: procError.errno,
                sqlState: procError.sqlState,
                sqlMessage: procError.sqlMessage
            });
            // Don't throw - procedure is optional for basic functionality
        }

        // Create triggers for automatic email parsing and NPN/CRD lookup
        log(`🔧 Creating triggers for email parsing and NPN/CRD lookup in database '${client}'...`);

        // Check if reference table exists first
        try {
            const [refTableCheck] = await root.query(`SHOW TABLES IN pixel LIKE 'CPACFANoIntent_CENTRAL'`);
            const hasRefTable = Array.isArray(refTableCheck) && refTableCheck.length > 0;
            log(`🔍 Reference table pixel.CPACFANoIntent_CENTRAL exists: ${hasRefTable}`);

            if (!hasRefTable) {
                log(`⚠️ Warning: Reference table pixel.CPACFANoIntent_CENTRAL not found - NPN/CRD lookup will be skipped`);
            }
        } catch (refError: any) {
            log(`⚠️ Warning: Could not check reference table:`, refError.message);
        }

        try {
            // Drop existing triggers first
            await root.execute(`DROP TRIGGER IF EXISTS \`${client}\`.after_resolution_log_insert`);
            await root.execute(`DROP TRIGGER IF EXISTS \`${client}\`.after_visitors_insert`);
            await root.execute(`DROP TRIGGER IF EXISTS \`${client}\`.after_visitors_update`);
            await root.execute(`DROP TRIGGER IF EXISTS \`${client}\`.after_email_insert`);

            // Trigger for superpixel_resolution_log - parse emails when events are inserted
            const resolutionLogInsertTrigger = `
                CREATE TRIGGER \`${client}\`.after_resolution_log_insert
                AFTER INSERT ON \`${client}\`.superpixel_resolution_log
                FOR EACH ROW
                BEGIN
                    -- Parse business emails from event
                    IF NEW.business_email IS NOT NULL AND NEW.business_email != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.business_email, 'business', 'business_email');
                    END IF;
                    
                    -- Parse personal emails from event
                    IF NEW.personal_emails IS NOT NULL AND NEW.personal_emails != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.personal_emails, 'personal', 'personal_emails');
                    END IF;
                    
                    -- Parse deep verified emails from event
                    IF NEW.deep_verified_emails IS NOT NULL AND NEW.deep_verified_emails != '' THEN
                        CALL \`${client}\`.parse_visitor_emails(NEW.uuid, NEW.deep_verified_emails, 'deep_verified', 'deep_verified_emails');
                    END IF;
                END
            `;

            // Trigger for superpixel_visitors - parse emails and lookup NPN/CRD after insert
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

            // Trigger for superpixel_visitors - re-parse emails and lookup NPN/CRD after update
            const visitorUpdateTrigger = `
                CREATE TRIGGER \`${client}\`.after_visitors_update
                AFTER UPDATE ON \`${client}\`.superpixel_visitors
                FOR EACH ROW
                BEGIN
                    -- Check if email fields changed
                    IF (OLD.business_email != NEW.business_email OR 
                        OLD.personal_emails != NEW.personal_emails OR 
                        OLD.deep_verified_emails != NEW.deep_verified_emails) THEN
                        
                        -- Delete existing emails for this UUID
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

            // Trigger for superpixel_emails - lookup NPN/CRD when new email is added
            const emailInsertTrigger = `
                CREATE TRIGGER \`${client}\`.after_email_insert
                AFTER INSERT ON \`${client}\`.superpixel_emails
                FOR EACH ROW
                BEGIN
                    DECLARE vNPN VARCHAR(255);
                    DECLARE vCRD VARCHAR(255);
                    DECLARE table_exists INT DEFAULT 0;
                    
                    -- Check if reference table exists
                    SELECT COUNT(*) INTO table_exists
                    FROM information_schema.tables 
                    WHERE table_schema = 'pixel' AND table_name = 'CPACFANoIntent_CENTRAL';
                    
                    IF table_exists > 0 THEN
                        -- Look up NPN/CRD by email in CPACFANoIntent_CENTRAL table
                        SELECT NPN, CRD INTO vNPN, vCRD
                        FROM pixel.CPACFANoIntent_CENTRAL
                        WHERE business_email = NEW.email
                           OR personal_email_1 = NEW.email
                           OR personal_email_2 = NEW.email
                           OR personal_email_3 = NEW.email
                           OR personal_email_4 = NEW.email
                           OR personal_email_5 = NEW.email
                        LIMIT 1;
                        
                        -- Update visitors table if NPN/CRD found
                        IF vNPN IS NOT NULL OR vCRD IS NOT NULL THEN
                            UPDATE \`${client}\`.superpixel_visitors
                            SET npn = COALESCE(npn, vNPN),
                                crd = COALESCE(crd, vCRD)
                            WHERE uuid = NEW.uuid;
                            
                            -- Also update resolution log entries for this UUID
                            UPDATE \`${client}\`.superpixel_resolution_log
                            SET npn = COALESCE(npn, vNPN),
                                crd = COALESCE(crd, vCRD)
                            WHERE uuid = NEW.uuid;
                        END IF;
                    END IF;
                END
            `;

            await root.execute(resolutionLogInsertTrigger);
            log(`✅ Resolution log insert trigger created for '${client}'`);

            await root.execute(visitorInsertTrigger);
            log(`✅ Visitor insert trigger created for '${client}'`);

            await root.execute(visitorUpdateTrigger);
            log(`✅ Visitor update trigger created for '${client}'`);

            await root.execute(emailInsertTrigger);
            log(`✅ Email insert trigger created for '${client}'`);
        } catch (triggerError: any) {
            log(`❌ ERROR: Could not create triggers for '${client}':`, {
                message: triggerError.message,
                code: triggerError.code,
                errno: triggerError.errno,
                sqlState: triggerError.sqlState,
                sqlMessage: triggerError.sqlMessage
            });
            // Don't throw - triggers are optional for basic functionality
        }

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
