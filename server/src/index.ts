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
async function createGoogleSheet(client: string, pixelId: string): Promise<{ sheetUrl?: string; error?: string }> {
    try {
        log(`📊 Creating Google Sheet for client: ${client}`);

        // Use different command based on environment
        const isDevelopment = process.env.NODE_ENV === 'development';
        const phpCommand = isDevelopment
            ? `php ../web/create_client_sheet.php "${client}" "${pixelId}"`
            : `sudo -u www-data php /opt/auto-pixel/create_client_sheet.php "${client}" "${pixelId}"`;

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

// Function to trigger immediate sync for a specific client
async function triggerImmediateSync(client: string): Promise<void> {
    try {
        log(`🔄 Triggering immediate sync for client: ${client}`);

        // Use different command based on environment
        const isDevelopment = process.env.NODE_ENV === 'development';
        const syncCommand = isDevelopment
            ? `php ../web/dynamic_sync.php --client="${client}"`
            : `sudo -u www-data php /opt/auto-pixel/dynamic_sync.php --client="${client}"`;

        // Execute sync in background (don't wait for completion)
        exec(syncCommand, (error, stdout, stderr) => {
            if (error) {
                log(`⚠️ Immediate sync error for ${client}:`, error);
            } else {
                log(`✅ Immediate sync completed for ${client}`);
            }
            if (stdout) log(`Sync stdout for ${client}:`, stdout);
            if (stderr) log(`Sync stderr for ${client}:`, stderr);
        });

        log(`🚀 Immediate sync triggered for ${client} (running in background)`);
    } catch (error: any) {
        log(`💥 Error triggering immediate sync for ${client}:`, error);
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

    if (!client.match(/^[-_a-zA-Z0-9]+$/)) {
        return res.status(400).json({ error: 'Client name can only contain letters, numbers, hyphens, and underscores' });
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
        const sheetResult = await createGoogleSheet(client, pixelId);
        if (sheetResult.sheetUrl) {
            sheetUrl = sheetResult.sheetUrl;

            // Trigger immediate sync after 5 seconds to populate the sheet with test data
            log(`⏰ Scheduling immediate sync for ${client} in 5 seconds...`);
            setTimeout(() => {
                triggerImmediateSync(client);
            }, 5000);
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