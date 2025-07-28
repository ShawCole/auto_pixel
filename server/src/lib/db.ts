import mysql from "mysql2/promise";
import { RowDataPacket } from "mysql2";

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

function getConnectionConfig() {
    if (!DB_HOST || !DB_USER || !DB_PASS) {
        throw new Error("Database connection configuration missing.");
    }
    return {
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        connectTimeout: 30000 // 30 seconds
    };
}

export async function ensureClientSchema(clientName: string): Promise<void> {
    const connection = await mysql.createConnection(getConnectionConfig());

    try {
        // Create database if it doesn't exist
        await connection.execute(`CREATE DATABASE IF NOT EXISTS \`${clientName}\``);

        // Use the client database
        await connection.execute(`USE \`${clientName}\``);

        // Check if tables exist
        const [tables] = await connection.execute<RowDataPacket[]>(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('superpixel_visitors', 'superpixel_resolution_log')",
            [clientName]
        );

        const existingTables = tables.map((row: any) => row.TABLE_NAME);

        if (!existingTables.includes('superpixel_visitors') || !existingTables.includes('superpixel_resolution_log')) {
            // Copy schema from template database
            const templateDb = process.env.TEMPLATE_DB || 'pixel';

            if (!existingTables.includes('superpixel_visitors')) {
                await connection.execute(`CREATE TABLE superpixel_visitors LIKE ${templateDb}.superpixel_visitors`);
            }

            if (!existingTables.includes('superpixel_resolution_log')) {
                await connection.execute(`CREATE TABLE superpixel_resolution_log LIKE ${templateDb}.superpixel_resolution_log`);
            }

            // Create triggers for NPN/CRD enrichment
            await connection.execute(`
                CREATE TRIGGER before_resolution_log_insert BEFORE INSERT ON superpixel_resolution_log 
                FOR EACH ROW 
                BEGIN 
                    DECLARE vNPN VARCHAR(255); 
                    DECLARE vCRD VARCHAR(255); 
                    SELECT NPN, CRD INTO vNPN, vCRD FROM accupoint_solutions.hash_emails WHERE hash256 = NEW.hem_sha256 LIMIT 1; 
                    SET NEW.npn = vNPN; 
                    SET NEW.crd = vCRD; 
                END
            `);

            await connection.execute(`
                CREATE TRIGGER before_visitors_insert BEFORE INSERT ON superpixel_visitors 
                FOR EACH ROW 
                BEGIN 
                    DECLARE vNPN VARCHAR(255); 
                    DECLARE vCRD VARCHAR(255); 
                    SELECT NPN, CRD INTO vNPN, vCRD FROM accupoint_solutions.hash_emails WHERE hash256 = NEW.hem_sha256 LIMIT 1; 
                    SET NEW.npn = vNPN; 
                    SET NEW.crd = vCRD; 
                END
            `);
        }

        // Ensure pixel_sheets table exists in main pixel database
        await connection.execute(`USE pixel`);
        await connection.execute(`
            CREATE TABLE IF NOT EXISTS pixel_sheets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_name VARCHAR(255) NOT NULL UNIQUE,
                website VARCHAR(500),
                sheet_url VARCHAR(1000),
                pixel_id VARCHAR(255),
                industry VARCHAR(100),
                deletion_scheduled DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_sync_at TIMESTAMP DEFAULT NULL,
                INDEX idx_client_name (client_name),
                INDEX idx_deletion_scheduled (deletion_scheduled)
            )
        `);

        // Add industry column if it doesn't exist
        const [columns] = await connection.execute<RowDataPacket[]>(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'pixel' AND TABLE_NAME = 'pixel_sheets' AND COLUMN_NAME = 'industry'"
        );

        if (columns.length === 0) {
            await connection.execute(`ALTER TABLE pixel_sheets ADD COLUMN industry VARCHAR(100) DEFAULT NULL`);
        }

    } finally {
        await connection.end();
    }
} 