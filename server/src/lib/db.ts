import mysql from "mysql2/promise";
// Removed fs and path imports as they are no longer needed for SQL execution

const { DB_HOST, DB_USER, DB_PASS, TEMPLATE_DB } = process.env;

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

    if (!DB_HOST || !DB_USER || !DB_PASS || !TEMPLATE_DB) {
        const missingVars = ['DB_HOST', 'DB_USER', 'DB_PASS', 'TEMPLATE_DB'].filter(v => !process.env[v]);
        log(`❌ Missing required database environment variables: ${missingVars.join(', ')}`);
        throw new Error(`Missing required database environment variables: ${missingVars.join(', ')}`);
    }

    log("🔌 Connecting to MariaDB...");
    // multipleStatements: false might be sufficient now, but keeping it true is safe
    const root = await mysql.createConnection({
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        connectTimeout: 30000,
        multipleStatements: true
    });

    const clientDbName = client; // Use client name directly as DB name

    try {
        log("✅ Connected to MariaDB successfully");

        await root.query(`CREATE DATABASE IF NOT EXISTS \`${clientDbName}\``);
        log(`✅ Database '${clientDbName}' created/verified`);

        // No need to USE clientDbName here, the master procedure handles context

        const tablesToClone = ["superpixel_resolution_log", "superpixel_visitors", "superpixel_emails"];
        for (const table of tablesToClone) {
            log(`📋 Creating table '${table}' in database '${clientDbName}' if not exists...`);
            // Clone structure from template DB directly into the client DB
            await root.execute(`CREATE TABLE IF NOT EXISTS \`${clientDbName}\`.\`${table}\` LIKE \`${TEMPLATE_DB}\`.\`${table}\``);
            log(`✅ Table '${clientDbName}.${table}' created/verified.`);
        }

        // --- THIS IS THE KEY PART ---
        log(`🔧 Calling master procedure 'pixel.provision_client_objects' for client '${clientDbName}'...`);

        // Call the master stored procedure located in the 'pixel' db
        // Ensure the root user has EXECUTE privilege on pixel.provision_client_objects
        await root.query('CALL pixel.provision_client_objects(?)', [clientDbName]);

        log(`✅ Successfully called provisioning procedure for '${clientDbName}'.`);
        // --- END KEY PART ---

        log(`🎉 Database schema setup completed for client: ${clientDbName}`);

    } catch (error: any) {
        log(`💥 Database schema setup failed for client: ${clientDbName}`, {
            error: error.message,
            sqlMessage: error.sqlMessage, // Might be relevant if CALL fails
            sqlState: error.sqlState,
            errno: error.errno,
            code: error.code
        });
        throw error; // Re-throw to indicate failure to the caller
    } finally {
        log("🔌 Closing database connection...");
        await root.end();
        log("✅ Database connection closed");
    }
}