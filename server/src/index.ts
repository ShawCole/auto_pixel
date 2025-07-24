import "dotenv/config";
import express from "express";
import bodyParser from "body-parser";
import cors from "cors";
import { ensureClientSchema } from "./lib/db.js";
import { createPixel } from "./lib/audienceLab.js";

// Enable verbose logging
const DEBUG = process.env.DEBUG === '*' || process.env.NODE_ENV === 'development';

function log(message: string, data?: any) {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] ${message}`);
    if (data && DEBUG) {
        console.log(JSON.stringify(data, null, 2));
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

        res.json({
            pixelSnippet: result.pixelCode,
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