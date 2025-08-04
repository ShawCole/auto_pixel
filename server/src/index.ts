import "dotenv/config";
import express from "express";
import bodyParser from "body-parser";
import cors from "cors";
import { exec } from "child_process";
import { promisify } from "util";
import { ensureClientSchema } from "./lib/db.js";
import { createPixel } from "./lib/audienceLab.js";

// Enable verbose logging
const DEBUG = process.env.DEBUG === '*' || process.env.NODE_ENV === 'development';
const execAsync = promisify(exec);

function log(message: string, data?: any) {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] ${message}`);
    if (data && DEBUG) {
        console.log(JSON.stringify(data, null, 2));
    }
}

// Function to create Google Sheet for client
async function createGoogleSheet(client: string, pixelId: string, website: string): Promise<{ sheetUrl?: string; error?: string }> {
    try {
        log(`📊 Creating Google Sheet for client: ${client} with website: ${website}`);

        // Use different command based on environment
        const isDevelopment = process.env.NODE_ENV === 'development';
        const phpCommand = isDevelopment
            ? `php ../web/create_client_sheet.php "${client}" "${pixelId}" "${website}"`
            : `sudo -u www-data php /opt/auto-pixel/create_client_sheet.php "${client}" "${pixelId}" "${website}"`;

        const { stdout, stderr } = await execAsync(phpCommand);

        if (stderr) {
            log("⚠️ PHP stderr output:", stderr);
        }

        const result = JSON.parse(stdout);

        if (result.success) {
            log(`✅ Google Sheet created successfully: ${result.sheetUrl}`);
            return { sheetUrl: result.sheetUrl };
        } else {
            log(`❌ Failed to create Google Sheet: ${result.error}`);
            return { error: result.error };
        }
    } catch (error: any) {
        log(`💥 Error creating Google Sheet:`, error);
        return { error: error.message || 'Failed to create Google Sheet' };
    }
}

// Function to trigger full dynamic sync after new pixel creation
async function triggerFullSync(): Promise<void> {
    try {
        log(`🔄 Triggering full dynamic sync to include new sheets`);

        // Use different command based on environment
        const isDevelopment = process.env.NODE_ENV === 'development';
        const syncCommand = isDevelopment
            ? `php ../web/dynamic_sync.php`
            : `sudo -u www-data php /opt/auto-pixel/dynamic_sync.php`;

        // Execute sync in background (don't wait for completion)
        exec(syncCommand, (error, stdout, stderr) => {
            if (error) {
                log(`⚠️ Full sync error:`, error);
            } else {
                log(`✅ Full sync completed`);
            }
            if (stdout) log(`Sync stdout:`, stdout);
            if (stderr) log(`Sync stderr:`, stderr);
        });

        log(`🚀 Full dynamic sync triggered (running in background)`);
    } catch (error: any) {
        log(`💥 Error triggering full sync:`, error);
    }
}

const app = express();

// Configure CORS to allow Netlify domain and pixel.thynkdata.com
const corsOptions = {
    origin: [
        'https://autopixel.netlify.app',
        'https://pixel.thynkdata.com',
        'http://pixel.thynkdata.com',
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',
        'http://localhost:3000'
    ],
    credentials: true,
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With']
};

app.use(cors(corsOptions));
app.use(bodyParser.json());

// Add request logging middleware
app.use((req, res, next) => {
    log(`[${req.method}] ${req.path}`, {
        headers: req.headers,
        body: req.body,
        query: req.query
    });
    next();
});

const PORT = process.env.PORT ? Number(process.env.PORT) : 4000;

log(`🚀 Starting server on port ${PORT}`);
log('Environment variables loaded:', {
    DB_HOST: process.env.DB_HOST,
    DB_USER: process.env.DB_USER ? '***' : 'NOT_SET',
    DB_PASS: process.env.DB_PASS ? '***' : 'NOT_SET',
    TEMPLATE_DB: process.env.TEMPLATE_DB,
    TEMPLATE_TABLE: process.env.TEMPLATE_TABLE,
    AUDLAB_USERNAME: process.env.AUDLAB_USERNAME ? '***' : 'NOT_SET',
    AUDLAB_PASSWORD: process.env.AUDLAB_PASSWORD ? '***' : 'NOT_SET',
    NODE_ENV: process.env.NODE_ENV,
    DEBUG: process.env.DEBUG
});

// POST /generate { client: "strategy_simple" }
app.post('/generate', async (req, res) => {
    const { client, website } = req.body;

    log(`📝 Received generate request for client: "${client}"`, {
        headers: req.headers,
        body: req.body,
        query: req.query
    });

    if (!client || !website) {
        return res.status(400).json({ error: 'Client name and website URL are required' });
    }

    if (!client.match(/^[_a-zA-Z0-9]+$/)) {
        return res.status(400).json({ error: 'Client name can only contain letters, numbers, and underscores (no hyphens)' });
    }

    try {
        log(`🎯 Starting pixel generation for client: ${client}`);

        // Skip database setup if explicitly requested or if database env vars are missing
        const { DB_HOST, DB_USER, DB_PASS, TEMPLATE_DB, TEMPLATE_TABLE } = process.env;
        const skipDatabase = process.env.SKIP_DATABASE === 'true' ||
            !DB_HOST || !DB_USER || !DB_PASS || !TEMPLATE_DB || !TEMPLATE_TABLE;

        if (skipDatabase) {
            log("⚠️  Skipping database setup (missing env vars or SKIP_DATABASE=true)");
        } else {
            // IMPORTANT: Create database FIRST, before SimpleAudience automation
            // This ensures the webhook test will succeed
            log("🗄️  Creating database schema BEFORE pixel creation...");
            await ensureClientSchema(client);
            log("✅ Database schema ready - webhook should now work");
        }

        log("🤖 Starting AudienceLab automation...");
        const result = await createPixel({ client, website });

        // Check if pixel generation had errors
        if (result.error) {
            log("❌ Pixel generation failed:", result.error);
            throw new Error(result.error);
        }

        if (!result.pixelCode) {
            log("❌ Pixel generation completed but no pixel code was extracted");
            throw new Error("Pixel generation completed but no pixel code was extracted");
        }

        log("✅ Pixel generated successfully with code");

        // Generate a unique pixel ID for tracking
        const pixelId = `${client.toLowerCase()}-pixel-${Date.now()}`;

        // Create Google Sheet for the client
        let sheetUrl: string | undefined;
        const sheetResult = await createGoogleSheet(client, pixelId, website);
        log(`🔍 Sheet creation result:`, sheetResult); // Debug log

        if (sheetResult.sheetUrl) {
            sheetUrl = sheetResult.sheetUrl;
            log(`✅ Sheet URL obtained: ${sheetUrl}`); // Debug log

            // Trigger full dynamic sync after 10 seconds to include the new sheet
            log(`⏰ Scheduling full dynamic sync in 10 seconds to include new sheet...`);
            setTimeout(() => {
                log(`🚀 Executing triggerFullSync now...`); // Debug log
                triggerFullSync();
            }, 10000);
        } else {
            log("⚠️ Failed to create Google Sheet but continuing:", sheetResult.error);
        }

        res.json({
            pixelSnippet: result.pixelCode,
            sheetUrl: sheetUrl,
            message: `Pixel generated successfully for ${client}`,
            databaseSetup: !skipDatabase
        });
    } catch (error: any) {
        log("💥 Error during pixel generation:", {
            error: error.message,
            stack: error.stack,
            client
        });

        res.status(500).json({
            error: error.message || 'Unknown error occurred',
            details: DEBUG ? error.stack : undefined
        });
    }
});

// Admin endpoints

// Update website URLs for all pixels
app.post("/admin/update-website-urls", async (req, res) => {
    try {
        log("🔄 Starting website URL update process");

        const { updateWebsiteUrls } = await import("./lib/websiteUrlUpdater.js");
        const result = await updateWebsiteUrls();

        if (result.error) {
            log("❌ Website URL update failed:", result.error);
            res.status(500).json({ error: result.error });
        } else {
            log(`✅ Website URL update completed. Updated: ${result.updated}, Failed: ${result.failed}`);
            res.json({
                success: true,
                updated: result.updated,
                failed: result.failed
            });
        }
    } catch (error: any) {
        log("💥 Error during website URL update:", error);
        res.status(500).json({
            error: error.message || 'Unknown error occurred'
        });
    }
});

// Get all pixels with stats
app.get("/admin/pixels", async (req, res) => {
    try {
        log("📊 Fetching all pixels for admin panel");

        // Import database connection
        const { ensureClientSchema } = await import("./lib/db.js");
        const mysql = await import("mysql2/promise");

        // Log database connection details (without password)
        log("🔌 Connecting to database:", {
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            database: 'pixel'
        });

        // Create connection to pixel database
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        log("✅ Database connection established");

        try {
            // Query pixel_sheets table with stats
            const query = `
                SELECT 
                    ps.id,
                    ps.client_name as clientName,
                    ps.pixel_id as pixelId,
                    ps.sheet_id as sheetId,
                    ps.sheet_url as sheetUrl,
                    ps.client_website as clientWebsite,
                    ps.created_at as createdAt,
                    ps.last_sync_at as lastSyncAt,
                    'Uncategorized' as industry,
                    NULL as deletionScheduled,
                    COALESCE(v.visitor_count, 0) as visitorCount,
                    COALESCE(e.event_count, 0) as eventCount
                FROM pixel_sheets ps
                LEFT JOIN (
                    SELECT 
                        TABLE_SCHEMA as database_name,
                        COUNT(*) as visitor_count
                    FROM information_schema.TABLES 
                    WHERE TABLE_NAME = 'superpixel_visitors'
                    GROUP BY TABLE_SCHEMA
                ) v ON v.database_name = ps.client_name
                LEFT JOIN (
                    SELECT 
                        TABLE_SCHEMA as database_name,
                        COUNT(*) as event_count
                    FROM information_schema.TABLES 
                    WHERE TABLE_NAME = 'superpixel_resolution_log'
                    GROUP BY TABLE_SCHEMA
                ) e ON e.database_name = ps.client_name
                ORDER BY ps.created_at DESC
            `;

            const [rows] = await connection.execute(query);

            // Transform the data to match the frontend interface
            const pixels = (rows as any[]).map((row: any) => ({
                id: row.id.toString(),
                clientName: row.clientName,
                website: row.clientWebsite || (row.pixelId ? `https://${row.pixelId.replace('-', '.')}.com` : 'N/A'),
                sheetUrl: row.sheetUrl,
                createdAt: row.createdAt.toISOString(),
                industry: row.industry,
                eventCount: parseInt(row.eventCount) || 0,
                visitorCount: parseInt(row.visitorCount) || 0,
                deletionScheduled: row.deletionScheduled ? row.deletionScheduled.toISOString() : null,
                lastSyncAt: row.lastSyncAt ? row.lastSyncAt.toISOString() : null
            }));

            log(`✅ Fetched ${pixels.length} pixels from database`);
            res.json({ pixels });

        } finally {
            await connection.end();
        }

    } catch (error: any) {
        log("❌ Error fetching pixels:", {
            message: error.message,
            code: error.code,
            errno: error.errno,
            sqlState: error.sqlState,
            sqlMessage: error.sqlMessage
        });
        res.status(500).json({
            error: "Failed to fetch pixels",
            details: error.message
        });
    }
});

// Delete pixel(s)
app.post("/admin/pixels/delete", async (req, res) => {
    try {
        const { pixelIds } = req.body;

        if (!Array.isArray(pixelIds) || pixelIds.length === 0) {
            return res.status(400).json({ error: "pixelIds must be a non-empty array" });
        }

        log(`🗑️ Scheduling deletion for pixels:`, pixelIds);

        // Import database connection
        const mysql = await import("mysql2/promise");

        // Create connection to pixel database
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        try {
            // Mark pixels as scheduled for deletion (30 days from now)
            const deletionDate = new Date();
            deletionDate.setDate(deletionDate.getDate() + 30);

            const updateQuery = `
                UPDATE pixel_sheets 
                SET deletion_scheduled = ? 
                WHERE id IN (${pixelIds.map(() => '?').join(',')})
            `;

            await connection.execute(updateQuery, [deletionDate, ...pixelIds]);

            log(`✅ Scheduled ${pixelIds.length} pixels for deletion on ${deletionDate.toISOString()}`);

            res.json({
                success: true,
                message: `Scheduled ${pixelIds.length} pixel(s) for deletion on ${deletionDate.toISOString()}`
            });

        } finally {
            await connection.end();
        }

    } catch (error: any) {
        log("❌ Error deleting pixels:", error);
        res.status(500).json({ error: "Failed to delete pixels" });
    }
});

// Update pixel industry
app.patch("/admin/pixels/:pixelId", async (req, res) => {
    try {
        const { pixelId } = req.params;
        const { industry } = req.body;

        log(`📝 Updating pixel ${pixelId} industry to: ${industry}`);

        // Import database connection
        const mysql = await import("mysql2/promise");

        // Create connection to pixel database
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        try {
            // Update the industry for the pixel
            const updateQuery = `
                UPDATE pixel_sheets 
                SET industry = ? 
                WHERE id = ?
            `;

            await connection.execute(updateQuery, [industry, pixelId]);

            log(`✅ Updated pixel ${pixelId} industry to: ${industry}`);

            res.json({ success: true });

        } finally {
            await connection.end();
        }

    } catch (error: any) {
        log("❌ Error updating pixel:", error);
        res.status(500).json({ error: "Failed to update pixel" });
    }
});

// Import pixel deletion functions
import { deletePixelFromSimpleAudience, downloadClientData, deleteClientFromDatabase } from './lib/pixelDeleter.js';
import { RowDataPacket } from 'mysql2';

// Download client data endpoint
app.post("/admin/pixels/:pixelId/download", async (req, res) => {
    try {
        const { pixelId } = req.params;

        log(`📊 Downloading data for pixel: ${pixelId}`);

        // Get client name from pixel_sheets table
        const mysql = await import("mysql2/promise");
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        try {
            const [rows] = await connection.execute<RowDataPacket[]>(
                'SELECT client_name FROM pixel_sheets WHERE id = ?',
                [pixelId]
            );

            if (rows.length === 0) {
                return res.status(404).json({ error: "Pixel not found" });
            }

            const clientName = rows[0].client_name;
            const result = await downloadClientData(clientName);

            if (result.success) {
                res.json({
                    success: true,
                    message: result.message,
                    data: result.data
                });
            } else {
                res.status(500).json({ error: result.message });
            }

        } finally {
            await connection.end();
        }

    } catch (error: any) {
        log("❌ Error downloading client data:", error);
        res.status(500).json({ error: "Failed to download client data" });
    }
});

// Delete pixel from SimpleAudience endpoint
app.post("/admin/pixels/:pixelId/delete-from-simpleaudience", async (req, res) => {
    try {
        const { pixelId } = req.params;

        log(`🗑️ Deleting pixel from SimpleAudience: ${pixelId}`);

        // Get client name from pixel_sheets table
        const mysql = await import("mysql2/promise");
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        try {
            const [rows] = await connection.execute<RowDataPacket[]>(
                'SELECT client_name FROM pixel_sheets WHERE id = ?',
                [pixelId]
            );

            if (rows.length === 0) {
                return res.status(404).json({ error: "Pixel not found" });
            }

            const clientName = rows[0].client_name;
            const result = await deletePixelFromSimpleAudience(clientName);

            if (result.success) {
                res.json({
                    success: true,
                    message: result.message
                });
            } else {
                res.status(500).json({ error: result.message });
            }

        } finally {
            await connection.end();
        }

    } catch (error: any) {
        log("❌ Error deleting pixel from SimpleAudience:", error);
        res.status(500).json({ error: "Failed to delete pixel from SimpleAudience" });
    }
});

// Delete client from database endpoint
app.post("/admin/pixels/:pixelId/delete-from-database", async (req, res) => {
    try {
        const { pixelId } = req.params;

        log(`🗄️ Deleting client from database: ${pixelId}`);

        // Get client name from pixel_sheets table
        const mysql = await import("mysql2/promise");
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        try {
            const [rows] = await connection.execute<RowDataPacket[]>(
                'SELECT client_name FROM pixel_sheets WHERE id = ?',
                [pixelId]
            );

            if (rows.length === 0) {
                return res.status(404).json({ error: "Pixel not found" });
            }

            const clientName = rows[0].client_name;
            const result = await deleteClientFromDatabase(clientName);

            if (result.success) {
                res.json({
                    success: true,
                    message: result.message
                });
            } else {
                res.status(500).json({ error: result.message });
            }

        } finally {
            await connection.end();
        }

    } catch (error: any) {
        log("❌ Error deleting client from database:", error);
        res.status(500).json({ error: "Failed to delete client from database" });
    }
});

// Health check endpoint
app.get("/health", (req, res) => {
    log("🏥 Health check requested");
    const healthData = {
        status: "ok",
        timestamp: new Date().toISOString(),
        uptime: process.uptime(),
        memory: process.memoryUsage(),
        env: process.env.NODE_ENV
    };
    log("📊 Health check response", healthData);
    res.json(healthData);
});

// Error handling middleware
app.use((err: any, req: express.Request, res: express.Response, next: express.NextFunction) => {
    log("💥 Unhandled error:", {
        error: err.message,
        stack: err.stack,
        url: req.url,
        method: req.method
    });
    res.status(500).json({ error: "Internal server error" });
});

app.listen(PORT, () => {
    log(`🚀 API ready on :${PORT}`);
    log("📋 Available endpoints:");
    log("   POST /generate - Generate pixel and webhook");
    log("   GET  /health   - Health check");
}); 