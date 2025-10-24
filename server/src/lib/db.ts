import mysql from "mysql2/promise";
import fs from "fs/promises";
import path from "path";

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
    // Keep multipleStatements: true - it's required for CREATE TRIGGER
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

        // Switch context permanently for subsequent commands in this connection
        await root.execute(`USE \`${clientDbName}\``);
        log(`✅ Switched context to database '${clientDbName}'`);

        const tablesToClone = ["superpixel_resolution_log", "superpixel_visitors", "superpixel_emails"];
        for (const table of tablesToClone) {
            log(`📋 Creating table '${table}' if not exists...`);
            // Clone structure from template DB into the current client DB context
            await root.execute(`CREATE TABLE IF NOT EXISTS \`${table}\` LIKE \`${TEMPLATE_DB}\`.\`${table}\``);
            log(`✅ Table '${table}' created/verified.`);
        }

        log(`🔧 Creating procedures and triggers for '${clientDbName}' from SQL file...`);
        const sqlFilePath = path.join(__dirname, '..', 'provision_triggers.sql'); // Assumes file is in src/
        const sqlFileContent = await fs.readFile(sqlFilePath, 'utf-8');

        // Split the SQL file by the custom comment separator into individual statements
        const sqlStatements = sqlFileContent.split('-- COMMAND_SEPARATOR --')
            .map(cmd => cmd.trim()) // Trim whitespace from each part
            .filter(cmd => cmd.length > 0); // Remove empty strings

        for (const statement of sqlStatements) {
            // Log the beginning of the statement being executed
            const statementStart = statement.substring(0, 100).replace(/\s+/g, ' ');
            log(`Executing SQL statement starting with: ${statementStart}...`);
            try {
                // Execute each trimmed SQL statement individually
                await root.query(statement);
                log(`✅ Successfully executed SQL statement.`);
            } catch (sqlError: any) {
                // Log the specific statement that failed more clearly
                log(`💥 Failed to execute SQL statement starting with: ${statementStart}...`, {
                    error: sqlError.message,
                    sqlMessage: sqlError.sqlMessage,
                    sqlState: sqlError.sqlState,
                    errno: sqlError.errno,
                    code: sqlError.code,
                });
                // Re-throw the error to stop the provisioning process immediately
                throw sqlError;
            }
        }

        log(`✅ Successfully executed all provisioning SQL for '${clientDbName}'.`);
        log(`🎉 Database schema setup completed for client: ${clientDbName}`);

    } catch (error: any) {
        log("💥 Database operation failed:", {
            // Error details are logged within the loop for SQL failures
            // If error is from connection/DB creation/table cloning, it will log here
            errorMessage: error.message
        });
        throw error; // Re-throw to indicate failure to the caller
    } finally {
        log("🔌 Closing database connection...");
        await root.end();
        log("✅ Database connection closed");
    }
}

