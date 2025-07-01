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
app.use(cors());
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
app.post("/generate", async (req, res) => {
    const client: string = (req.body.client || "").trim();
    log(`📝 Received generate request for client: "${client}"`);

    if (!client.match(/^[-_a-zA-Z0-9]+$/)) {
        log(`❌ Invalid client name: "${client}"`);
        return res.status(400).json({ error: "Invalid client string - only letters, numbers, hyphens, and underscores allowed" });
    }

    try {
        log(`🎯 Starting pixel generation for client: ${client}`);

        // 1️⃣  Create DB + table clone
        log("🗄️  Creating database schema...");
        await ensureClientSchema(client);
        log("✅ Database schema created successfully");

        // 2️⃣  Build webhook URL
        const webhookUrl = `https://hook.thynkdata.com/pixel_import.php?client=${client}`;
        log(`🔗 Webhook URL generated: ${webhookUrl}`);

        // 3️⃣  Create pixel (headless) & retrieve script
        log("🤖 Starting AudienceLab automation...");
        const website = (req.body.website || "").trim();
        const result = await createPixel({ client, website });
        if (result.pixelCode) {
            log("✅ Pixel snippet retrieved successfully");
            return res.json({
                message: "Pixel, DB, and webhook successfully created!",
                pixelSnippet: result.pixelCode,
                webhookUrl,
                client,
                timestamp: new Date().toISOString()
            });
        } else {
            throw new Error(result.error || "Pixel creation failed");
        }
    } catch (e: any) {
        log("💥 Error during pixel generation:", {
            error: e.message,
            stack: e.stack,
            client
        });
        return res.status(500).json({ error: e.message || "Internal error" });
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