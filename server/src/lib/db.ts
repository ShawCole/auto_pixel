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
