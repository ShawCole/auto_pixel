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

        // Create triggers for NPN/CRD lookup
        log(`🔧 Creating triggers for NPN/CRD lookup in database '${client}'...`);

        // Trigger for superpixel_resolution_log
        const resolutionTrigger = `
            CREATE TRIGGER \`${client}\`.before_resolution_log_insert
            BEFORE INSERT ON \`${client}\`.superpixel_resolution_log
            FOR EACH ROW
            BEGIN
                DECLARE vNPN VARCHAR(255);
                DECLARE vCRD VARCHAR(255);
                
                SELECT NPN, CRD INTO vNPN, vCRD
                FROM accupoint_solutions.hash_emails
                WHERE hash256 = NEW.hem_sha256
                LIMIT 1;
                
                SET NEW.npn = vNPN;
                SET NEW.crd = vCRD;
            END
        `;

        // Trigger for superpixel_visitors
        const visitorInsertTrigger = `
            CREATE TRIGGER \`${client}\`.before_visitors_insert
            BEFORE INSERT ON \`${client}\`.superpixel_visitors
            FOR EACH ROW
            BEGIN
                DECLARE vNPN VARCHAR(255);
                DECLARE vCRD VARCHAR(255);
                
                SELECT NPN, CRD INTO vNPN, vCRD
                FROM accupoint_solutions.hash_emails
                WHERE hash256 = NEW.hem_sha256
                LIMIT 1;
                
                SET NEW.npn = vNPN;
                SET NEW.crd = vCRD;
            END
        `;

        // Trigger for superpixel_visitors update
        const visitorUpdateTrigger = `
            CREATE TRIGGER \`${client}\`.before_visitors_update
            BEFORE UPDATE ON \`${client}\`.superpixel_visitors
            FOR EACH ROW
            BEGIN
                DECLARE vNPN VARCHAR(255);
                DECLARE vCRD VARCHAR(255);
                
                IF (NEW.npn IS NULL OR NEW.crd IS NULL) AND NEW.hem_sha256 IS NOT NULL THEN
                    SELECT NPN, CRD INTO vNPN, vCRD
                    FROM accupoint_solutions.hash_emails
                    WHERE hash256 = NEW.hem_sha256
                    LIMIT 1;
                    
                    IF vNPN IS NOT NULL THEN
                        SET NEW.npn = vNPN;
                    END IF;
                    IF vCRD IS NOT NULL THEN
                        SET NEW.crd = vCRD;
                    END IF;
                END IF;
            END
        `;

        try {
            await root.execute(resolutionTrigger);
            log(`✅ Resolution log trigger created for '${client}'`);

            await root.execute(visitorInsertTrigger);
            log(`✅ Visitor insert trigger created for '${client}'`);

            await root.execute(visitorUpdateTrigger);
            log(`✅ Visitor update trigger created for '${client}'`);
        } catch (triggerError: any) {
            log(`⚠️ Warning: Could not create triggers for '${client}':`, triggerError.message);
            // Don't throw - triggers are optional
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