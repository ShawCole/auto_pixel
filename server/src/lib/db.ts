import mysql from "mysql2/promise";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from 'url'; // <-- Import fileURLToPath
import { dirname } from 'path'; // <-- Import dirname

// --- ESM way to get __dirname ---
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename); // This will be /opt/auto-pixel/server/dist/lib when running
// --- End ESM fix ---

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

    const clientDbName = client; // Use client name directly as DB name

    let root: mysql.Connection | null = null; // Declare root connection variable
    let clientConn: mysql.Connection | null = null; // Declare client connection variable

    try {
        log("🔌 Connecting to MariaDB (root)...");
        root = await mysql.createConnection({ // Assign to root
            host: DB_HOST,
            user: DB_USER,
            password: DB_PASS,
            connectTimeout: 30000,
            // No multipleStatements needed here initially
        });
        log("✅ Connected to MariaDB successfully (root)");

        // Create DB
        await root.query(`CREATE DATABASE IF NOT EXISTS \`${clientDbName}\``);
        log(`✅ Database '${clientDbName}' created/verified`);

        // Close root connection
        log("🔌 Closing root database connection...");
        await root.end();
        root = null; // Mark as closed
        log("✅ Root database connection closed");

        // --- Connect DIRECTLY to the new client DB ---
        log(`🔌 Connecting directly to client DB '${clientDbName}'...`);
        clientConn = await mysql.createConnection({ // Assign to clientConn
            host: DB_HOST,
            user: DB_USER, // Or a specific user if you have one
            password: DB_PASS,
            database: clientDbName, // Connect directly to this DB
            connectTimeout: 30000,
            multipleStatements: true // <<< NEEDED HERE for CREATE TRIGGER
        });
        log(`✅ Connected directly to client DB '${clientDbName}'`);

        // --- Run table cloning and trigger creation using clientConn ---
        const tablesToClone = ["superpixel_resolution_log", "superpixel_visitors", "superpixel_emails"];
        for (const table of tablesToClone) {
            log(`📋 Creating table '${table}' if not exists...`);
            // Clone structure from template DB (Needs fully qualified name now)
            await clientConn.execute(`CREATE TABLE IF NOT EXISTS \`${table}\` LIKE \`${TEMPLATE_DB}\`.\`${table}\``);
            log(`✅ Table '${table}' created/verified.`);
        }

        log(`🔧 Creating procedures and triggers for '${clientDbName}' from SQL file...`);
        // --- FIX: Go up TWO levels from dist/lib to server/, then into src/ ---
        const sqlFilePath = path.join(__dirname, '..', '..', 'src', 'provision_triggers.sql');
        // --- End path fix ---
        const sqlFileContent = await fs.readFile(sqlFilePath, 'utf-8');
        const sqlStatements = sqlFileContent.split('-- COMMAND_SEPARATOR --')
            .map(cmd => cmd.trim())
            .filter(cmd => cmd.length > 0);

        for (const statement of sqlStatements) {
            const statementStart = statement.substring(0, 100).replace(/\s+/g, ' ');
            log(`Executing SQL statement starting with: ${statementStart}...`);
            try {
                // Use query() on the client connection
                await clientConn.query(statement);
                log(`✅ Successfully executed SQL statement.`);
            } catch (sqlError: any) {
                // Use regular quotes for this log message to avoid template literal issues
                log("💥 Failed to execute SQL statement. Details:", {
                    statementStart: statementStart,
                    error: sqlError.message,
                    sqlMessage: sqlError.sqlMessage,
                    sqlState: sqlError.sqlState,
                    errno: sqlError.errno,
                    code: sqlError.code,
                });
                throw sqlError;
            }
        }

        log(`✅ Successfully executed all provisioning SQL for '${clientDbName}'.`);
        log(`🎉 Database schema setup completed for client: ${clientDbName}`);

    } catch (error: any) {
        log("💥 Database operation failed:", { errorMessage: error.message });
        throw error;
    } finally {
        // Ensure both connections are closed if they were opened
        if (root) {
            try {
              log("🔌 Closing leftover root database connection...");
              await root.end();
              log("✅ Leftover root database connection closed");
            } catch (closeErr: any) {
              // Use regular quotes here too
              log("⚠️ Error closing leftover root connection:", closeErr.message);
            }
        }
        if (clientConn) {
            try {
              log("🔌 Closing client database connection...");
              await clientConn.end();
              log("✅ Client database connection closed");
            } catch (closeErr: any) {
              // Use regular quotes here too
              log("⚠️ Error closing client connection:", closeErr.message);
            }
        }
    }
}
