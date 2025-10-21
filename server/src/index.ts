import "dotenv/config";
import express from "express";
import bodyParser from "body-parser";
import cors from "cors";
import { exec } from "child_process";
import { promisify } from "util";
import { ensureClientSchema } from "./lib/db.js";
import { createPixel } from "./lib/audienceLab.js";
import fs from "fs";
import path from "path";

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

// Sync helpers and configuration
const isProduction = process.env.NODE_ENV === 'production';
const PHP_BIN = process.env.PHP_BIN || 'php';
const SYNC_LOG_DIR = process.env.SYNC_LOG_DIR || "/var/log/auto-pixel";
const SYNC_LOCK_DIR = process.env.SYNC_LOCK_DIR || "/opt/auto-pixel/.sync-locks";

function ensureDir(p: string) {
    try { fs.mkdirSync(p, { recursive: true }); } catch { }
}

function isPidAlive(pidStr: string): boolean {
    const n = Number(pidStr);
    if (!n || Number.isNaN(n)) return false;
    try {
        process.kill(n, 0);
        return true;
    } catch {
        return false;
    }
}

function watchPidAndCleanupLock(pidStr: string, lockPath: string, clientName: string) {
    const n = Number(pidStr);
    if (!n || Number.isNaN(n)) return;
    const interval = setInterval(() => {
        if (!isPidAlive(pidStr) || !fs.existsSync(`/proc/${pidStr}`)) {
            try {
                if (fs.existsSync(lockPath)) {
                    fs.unlinkSync(lockPath);
                    log(`🧹 Cleared sync lock for ${clientName} (process ${pidStr} ended)`);
                }
            } catch { }
            clearInterval(interval);
        }
    }, 5000);
}

function readTail(filePath: string, maxBytes = 2048): string {
    try {
        const stats = fs.statSync(filePath);
        const start = Math.max(0, stats.size - maxBytes);
        const fd = fs.openSync(filePath, 'r');
        const buf = Buffer.alloc(stats.size - start);
        fs.readSync(fd, buf, 0, buf.length, start);
        fs.closeSync(fd);
        return buf.toString('utf8');
    } catch {
        return "";
    }
}

// Ensure admin metadata schema (adds deletable column if missing)
async function ensureDeletableColumn(connection: any): Promise<void> {
    try {
        const [cols] = await connection.execute(
            "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'pixel_sheets' AND COLUMN_NAME = 'deletable'",
            ['pixel']
        );
        const cnt = Number((cols as any[])[0].cnt ?? (cols as any[])[0].CNT ?? 0);
        if (cnt === 0) {
            await connection.execute(
                "ALTER TABLE pixel_sheets ADD COLUMN deletable TINYINT(1) NOT NULL DEFAULT 1 AFTER client_website"
            );
            log("🛠️ Added 'deletable' column to pixel.pixel_sheets (default: 1)");
        }
    } catch (err: any) {
        log("⚠️ ensureDeletableColumn error:", { message: err.message });
    }
}

// Function to create Google Sheet for client
async function createGoogleSheet(client: string, pixelId: string, website: string): Promise<{ sheetUrl?: string; error?: string }> {
    try {
        log(`📊 Creating Google Sheet for client: ${client} with website: ${website}`);

        // Use different command based on environment
        const isProduction = process.env.NODE_ENV === 'production';
        const phpBin = process.env.PHP_BIN || 'php';
        log('🔧 PHP binary for sheet creation:', { phpBin });
        // In dev (mac/local), run local script without sudo; in prod (VM), run system path under www-data
        const phpCommand = isProduction
            ? `sudo -u www-data ${phpBin} /opt/auto-pixel/create_client_sheet.php "${client}" "${pixelId}" "${website}"`
            : `${phpBin} ../create_client_sheet.php "${client}" "${pixelId}" "${website}"`;

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
        const isProduction = process.env.NODE_ENV === 'production';
        const phpBin = process.env.PHP_BIN || 'php';
        log('🔧 PHP binary for dynamic sync:', { phpBin });
        const syncCommand = isProduction
            ? `sudo -u www-data ${phpBin} /opt/auto-pixel/dynamic_sync.php`
            : `${phpBin} ../dynamic_sync.php`;

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

        // Guard: prevent duplicate client_name entries in pixel.pixel_sheets
        try {
            const mysql = await import("mysql2/promise");
            const connection = await mysql.createConnection({
                host: process.env.DB_HOST,
                user: process.env.DB_USER,
                password: process.env.DB_PASS,
                database: 'pixel',
                connectTimeout: 30000
            });
            try {
                const [rows] = await connection.execute<any[]>(
                    'SELECT id FROM pixel_sheets WHERE client_name = ? LIMIT 1',
                    [client]
                );
                if ((rows as any[]).length) {
                    log('⛔ Client already exists in pixel_sheets', { client, existingId: (rows as any[])[0].id });
                    return res.status(409).json({ error: 'Client already exists in pixel_sheets', client, existingId: (rows as any[])[0].id });
                }
            } finally {
                await connection.end();
            }
        } catch (dupErr: any) {
            log('⚠️ Duplicate check failed (continuing):', { message: dupErr?.message });
        }

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
        if (typeof (result as any).webhookVerified !== 'undefined') {
            log(`📗 Webhook verification status: ${(result as any).webhookVerified ? 'VERIFIED' : 'NOT VERIFIED'}`);
        }
        if (typeof (result as any).webhookTestUuidVerified !== 'undefined') {
            log(`📘 Test UUID verification status: ${(result as any).webhookTestUuidVerified ? 'VERIFIED' : 'NOT VERIFIED'}`);
        }

        // 3) Post canonical webhook test payload and verify in DB (before pixel_sheets upsert)
        let finalWebhookVerified = (result as any).webhookVerified === true;
        let finalWebhookTestUuidVerified = (result as any).webhookTestUuidVerified === true;
        let finalWebhookRowSample: any = (result as any).webhookRowSample || null;

        try {
            function extractPixelIdFromScript(snippet: string): string | null {
                try {
                    const m = snippet.match(/\bhttps?:\/\/(?:cdn\.)?v3\.identitypxl\.app\/pixels\/([^\/'"\s]+)\/p\.js/i);
                    return m && m[1] ? m[1] : null;
                } catch { return null; }
            }

            const TEST_UUID = 'dc0016d3803db4912441edb1b0';
            const hookUrl = `https://hook.thynkdata.com/pixel_import.php?client=${encodeURIComponent(client)}`;
            const now = new Date();
            const nowIso = now.toISOString();
            const endIso = new Date(now.getTime() + 60_000).toISOString();
            const parsedPixelId = extractPixelIdFromScript(result.pixelCode || '') || '';
            const randomTag = `${now.toISOString().replace(/\D/g, '').slice(0, 14)}-${Math.random().toString(36).slice(2, 8)}`;

            const payload: any = {
                events: [
                    {
                        pixel_id: parsedPixelId,
                        hem_sha256: '1458ee23320e30d920f099f57b11000b89ab82a7456bf39dd663d9d0858fd88d',
                        event_timestamp: nowIso,
                        event_type: 'page_view',
                        ip_address: '35.191.85.117',
                        activity_start_date: nowIso,
                        activity_end_date: endIso,
                        referrer_url: '',
                        resolution: {
                            UUID: TEST_UUID,
                            FIRST_NAME: 'Margaret',
                            LAST_NAME: 'Faz',
                            PERSONAL_ADDRESS: '547 Pinewood Ln',
                            PERSONAL_CITY: 'San Antonio',
                            PERSONAL_STATE: 'TX',
                            PERSONAL_ZIP: '78216',
                            PERSONAL_ZIP4: '6911',
                            AGE_RANGE: '65 and older',
                            CHILDREN: 'Y',
                            GENDER: 'F',
                            HOMEOWNER: 'Y',
                            MARRIED: 'Y',
                            NET_WORTH: '$75,000 to $99,999',
                            INCOME_RANGE: 'Less than $20,000',
                            DIRECT_NUMBER: '+12104382427, +12108237899, +17137836220, +14322144256',
                            DIRECT_NUMBER_DNC: 'Y, Y, Y, N',
                            MOBILE_PHONE: '+12104382427, +14322144256, +12108237899',
                            MOBILE_PHONE_DNC: 'Y, N, Y',
                            PERSONAL_PHONE: '+12104382427, +12108237899, +14322144256',
                            PERSONAL_PHONE_DNC: 'Y, Y, N',
                            BUSINESS_EMAIL: '',
                            PERSONAL_EMAILS: 'margaretfaz@gmail.com, mf7476439@gmail.com, mflores8589@gmail.com',
                            DEEP_VERIFIED_EMAILS: '',
                            SHA256_PERSONAL_EMAIL: '1458ee23320e30d920f099f57b11000b89ab82a7456bf39dd663d9d0858fd88d, 38e1e9e2bd652af38d5af129a5a763cd89dfaf0f84db99fdf9c1d4265bb56ecf, 2b863da80b0df29ce907336f4a55c91ef9b70fdc4c3f0b05846600dd2554d56f',
                            SHA256_BUSINESS_EMAIL: '',
                            JOB_TITLE: '',
                            HEADLINE: '',
                            DEPARTMENT: '',
                            SENIORITY_LEVEL: '',
                            INFERRED_YEARS_EXPERIENCE: '',
                            COMPANY_NAME_HISTORY: '',
                            JOB_TITLE_HISTORY: '',
                            EDUCATION_HISTORY: '',
                            COMPANY_ADDRESS: '',
                            COMPANY_DESCRIPTION: '',
                            COMPANY_DOMAIN: '',
                            COMPANY_EMPLOYEE_COUNT: '',
                            COMPANY_LINKEDIN_URL: null,
                            COMPANY_NAME: '',
                            COMPANY_PHONE: '',
                            COMPANY_REVENUE: '',
                            COMPANY_SIC: '',
                            COMPANY_NAICS: '',
                            COMPANY_CITY: '',
                            COMPANY_STATE: '',
                            COMPANY_ZIP: '',
                            COMPANY_INDUSTRY: '',
                            LINKEDIN_URL: '',
                            TWITTER_URL: '',
                            FACEBOOK_URL: '',
                            SOCIAL_CONNECTIONS: '',
                            SKILLS: '',
                            INTERESTS: '',
                            SKIPTRACE_MATCH_SCORE: '11',
                            SKIPTRACE_NAME: 'MARGARET FLORES',
                            SKIPTRACE_ADDRESS: '547 Pinewood Ln',
                            SKIPTRACE_CITY: 'San Antonio',
                            SKIPTRACE_STATE: 'TX',
                            SKIPTRACE_ZIP: '78216',
                            SKIPTRACE_LANDLINE_NUMBERS: '',
                            SKIPTRACE_WIRELESS_NUMBERS: '',
                            SKIPTRACE_CREDIT_RATING: 'B',
                            SKIPTRACE_DNC: 'Y',
                            SKIPTRACE_EXACT_AGE: '76',
                            SKIPTRACE_ETHNIC_CODE: '',
                            SKIPTRACE_LANGUAGE_CODE: 'UX',
                            SKIPTRACE_IP: '172.204.161.145',
                            SKIPTRACE_B2B_ADDRESS: '',
                            SKIPTRACE_B2B_PHONE: '',
                            SKIPTRACE_B2B_SOURCE: '',
                            SKIPTRACE_B2B_WEBSITE: '',
                            VALID_PHONES: ''
                        },
                        event_data: {
                            url: `https://example.com/?wldup=${randomTag}&fbclid=t`,
                            referrer: 'http://m.facebook.com/',
                            title: 'example.com',
                            percentage: 94,
                            element: '{"attributes":{"class":"elementor-button elementor-button-link elementor-size-sm","href":"#gallery"},"classes":"elementor-button elementor-button-link elementor-size-sm","id":null,"tag":"A","text":"View custom cabinets\\n"}'
                        }
                    }
                ]
            };

            try {
                log('🛰️ Posting canonical webhook test payload...');
                const resp = await (fetch as any)(hookUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                log('🛰️ Webhook POST status:', { status: resp.status });
            } catch (e: any) {
                log('⚠️ Webhook POST failed:', { message: e?.message });
            }

            // Poll client DB for TEST_UUID up to 15s
            const mysql = await import('mysql2/promise');
            const conn = await mysql.createConnection({
                host: process.env.DB_HOST,
                user: process.env.DB_USER,
                password: process.env.DB_PASS,
                database: client,
                connectTimeout: 30000
            });
            try {
                for (let i = 0; i < 15; i++) {
                    const [rows] = await conn.execute<any[]>(
                        'SELECT id, uuid, event_timestamp, url FROM superpixel_resolution_log WHERE uuid = ? ORDER BY id DESC LIMIT 1',
                        [TEST_UUID]
                    );
                    if ((rows as any[]).length) {
                        finalWebhookTestUuidVerified = true;
                        finalWebhookVerified = true;
                        finalWebhookRowSample = (rows as any[])[0];
                        log('✅ Canonical webhook test verified in DB');
                        break;
                    }
                    await new Promise(r => setTimeout(r, 1000));
                }
                if (!finalWebhookTestUuidVerified) {
                    log('⚠️ Canonical webhook test not verified within timeout');
                }
            } finally {
                await conn.end();
            }
        } catch (e: any) {
            log('⚠️ Error during canonical webhook test + verify:', { message: e?.message });
        }

        // 4) Create Google Sheet for the client (optional, may fail) and upsert pixel_sheets LAST
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

        // Upsert central pixel.metadata row in pixel.pixel_sheets (LAST)
        try {
            async function upsertPixelSheetRow(params: { client: string; website: string; pixelScript: string; sheetUrl?: string }) {
                const { client, website, pixelScript, sheetUrl } = params;

                function extractPixelIdFromScript(snippet: string): string | null {
                    try {
                        const m = snippet.match(/\bhttps?:\/\/(?:cdn\.)?v3\.identitypxl\.app\/pixels\/([^\/'"\s]+)\/p\.js/i);
                        return m && m[1] ? m[1] : null;
                    } catch { return null; }
                }

                function extractSheetId(url?: string): string | null {
                    if (!url) return null;
                    try {
                        const m = url.match(/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/);
                        return m && m[1] ? m[1] : null;
                    } catch { return null; }
                }

                const pixelIdFromScript = extractPixelIdFromScript(pixelScript) || '';
                // Always provide a non-null sheet_id to satisfy NOT NULL constraint
                const sheetId = extractSheetId(sheetUrl) || `PENDING_${Date.now()}`;

                log("🧾 Preparing pixel_sheets upsert", { client, website });
                const mysql = await import("mysql2/promise");
                const connection = await mysql.createConnection({
                    host: process.env.DB_HOST,
                    user: process.env.DB_USER,
                    password: process.env.DB_PASS,
                    database: 'pixel',
                    connectTimeout: 30000
                });
                try {
                    log("🧾 Executing pixel_sheets upsert", { pixelIdFromScript, sheetId, hasSheetUrl: !!sheetUrl });
                    const sql = `
                        INSERT INTO pixel_sheets
                            (client_name, client_website, pixel_id, pixel_script, sheet_id, sheet_url, deletable)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE
                            client_website = VALUES(client_website),
                            pixel_id = IF(VALUES(pixel_id) <> '', VALUES(pixel_id), pixel_id),
                            pixel_script = VALUES(pixel_script),
                            sheet_id = IF(VALUES(sheet_id) <> '', VALUES(sheet_id), sheet_id),
                            sheet_url = IF(VALUES(sheet_url) <> '', VALUES(sheet_url), sheet_url)
                    `;
                    await connection.execute(sql, [client, website, pixelIdFromScript, pixelScript, sheetId, sheetUrl || '']);
                    log("✅ Upserted pixel_sheets row", { client, website, pixelIdFromScript, sheetId, hasSheetUrl: !!sheetUrl });
                } finally {
                    await connection.end();
                }
            }

            await upsertPixelSheetRow({ client, website, pixelScript: result.pixelCode, sheetUrl });
        } catch (metaErr: any) {
            log("⚠️ Failed to upsert pixel_sheets row:", { message: metaErr?.message });
        }

        res.json({
            pixelSnippet: result.pixelCode,
            sheetUrl: sheetUrl,
            message: `Pixel generated successfully for ${client}`,
            databaseSetup: !skipDatabase,
            webhookVerified: finalWebhookVerified,
            webhookTestUuidVerified: finalWebhookTestUuidVerified,
            webhookRowSample: finalWebhookRowSample
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

// Smart Sync helpers
async function startSmartSyncForClient(clientName: string): Promise<{ started: boolean; logPath: string; command: string }> {
    ensureDir(SYNC_LOG_DIR);
    ensureDir(SYNC_LOCK_DIR);

    const lockPath = path.join(SYNC_LOCK_DIR, `${clientName}.lock`);
    if (fs.existsSync(lockPath)) {
        const m = fs.readFileSync(lockPath, 'utf8').trim();
        // If PID is stale, remove the lock and continue; otherwise deny
        if (!m || !isPidAlive(m) || !fs.existsSync(`/proc/${m}`)) {
            try { fs.unlinkSync(lockPath); } catch { }
            log(`🧹 Removed stale sync lock for ${clientName}${m ? ` (pid ${m})` : ''}`);
        } else {
            throw new Error(`Sync already in progress for ${clientName}${m ? ` (pid ${m})` : ''}`);
        }
    }

    const logPath = path.join(SYNC_LOG_DIR, `sync-${clientName}.log`);
    const phpScript = isProduction ? "/opt/auto-pixel/smart_sync.php" : "../smart_sync.php";
    const cmd = isProduction
        ? `sudo -u www-data ${PHP_BIN} ${phpScript} --client=${clientName}`
        : `${PHP_BIN} ${phpScript} --client=${clientName}`;

    const spawnCmd = `nohup ${cmd} >> ${logPath} 2>&1 & echo $!`;

    return await new Promise((resolve, reject) => {
        exec(spawnCmd, (err, stdout) => {
            if (err) return reject(err);
            const pid = (stdout || "").toString().trim();
            try { fs.writeFileSync(lockPath, pid); } catch { }
            // Start watcher to clean the lock once the process ends
            watchPidAndCleanupLock(pid, lockPath, clientName);
            resolve({ started: true, logPath, command: cmd });
        });
    });
}

async function getClientByPixelId(pixelId: string): Promise<{ clientName: string; sheetId: string | null }> {
    const mysql = await import("mysql2/promise");
    const connection = await mysql.createConnection({
        host: process.env.DB_HOST,
        user: process.env.DB_USER,
        password: process.env.DB_PASS,
        database: 'pixel',
        connectTimeout: 30000
    });
    try {
        const [rows] = await connection.execute<any[]>(
            "SELECT client_name AS clientName, sheet_id AS sheetId FROM pixel_sheets WHERE id = ?",
            [pixelId]
        );
        if (!(rows as any[]).length) throw new Error("Pixel not found");
        return { clientName: (rows as any[])[0].clientName, sheetId: (rows as any[])[0].sheetId || null };
    } finally {
        await connection.end();
    }
}

async function getClientByName(clientName: string): Promise<{ clientName: string; sheetId: string | null }> {
    const mysql = await import("mysql2/promise");
    const connection = await mysql.createConnection({
        host: process.env.DB_HOST,
        user: process.env.DB_USER,
        password: process.env.DB_PASS,
        database: 'pixel',
        connectTimeout: 30000
    });
    try {
        const [rows] = await connection.execute<any[]>(
            "SELECT client_name AS clientName, sheet_id AS sheetId FROM pixel_sheets WHERE client_name = ?",
            [clientName]
        );
        if (!(rows as any[]).length) {
            // No sheet row yet; allow sync to run anyway but report sheetId null
            return { clientName, sheetId: null };
        }
        return { clientName: (rows as any[])[0].clientName, sheetId: (rows as any[])[0].sheetId || null };
    } finally {
        await connection.end();
    }
}

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
            // Ensure schema has deletable column
            await ensureDeletableColumn(connection);
            // Query pixel_sheets table with stats
            const query = `
                SELECT 
                    ps.id,
                    ps.client_name as clientName,
                    ps.pixel_id as pixelId,
                    ps.sheet_id as sheetId,
                    ps.sheet_url as sheetUrl,
                    ps.client_website as clientWebsite,
                    COALESCE(ps.deletable, 1) as deletable,
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
                lastSyncAt: row.lastSyncAt ? row.lastSyncAt.toISOString() : null,
                deleteLocked: !(row.deletable === 1 || row.deletable === '1')
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
            // Check DB-backed deletable flag
            const [lockRows] = await connection.execute<RowDataPacket[]>(
                'SELECT COALESCE(deletable,1) AS deletable FROM pixel_sheets WHERE id = ?',
                [pixelId]
            );
            const deletable = Number((lockRows as any[])[0]?.deletable) === 1;
            if (!deletable) {
                return res.status(423).json({ error: "Deletion is locked for this pixel" });
            }
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

// Combined deletion: SimpleAudience + DROP DATABASE + remove pixel_sheets row
app.post("/admin/pixels/:pixelId/delete", async (req, res) => {
    try {
        const { pixelId } = req.params;

        log(`🧹 Combined delete for pixel: ${pixelId}`);

        // Get client name from pixel_sheets
        const mysql = await import("mysql2/promise");
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        let clientName: string | null = null;
        try {
            const [rows] = await connection.execute<RowDataPacket[]>(
                'SELECT client_name FROM pixel_sheets WHERE id = ?',
                [pixelId]
            );
            if ((rows as any[]).length === 0) {
                return res.status(404).json({ error: "Pixel not found" });
            }
            clientName = (rows as any[])[0].client_name as string;
        } finally {
            await connection.end();
        }

        // Enforce DB-backed deletable flag
        {
            const mysql = await import("mysql2/promise");
            const conn = await mysql.createConnection({
                host: process.env.DB_HOST,
                user: process.env.DB_USER,
                password: process.env.DB_PASS,
                database: 'pixel',
                connectTimeout: 30000
            });
            try {
                const [lockRows] = await conn.execute<RowDataPacket[]>(
                    'SELECT COALESCE(deletable,1) AS deletable FROM pixel_sheets WHERE id = ?',
                    [pixelId]
                );
                const deletable = Number((lockRows as any[])[0]?.deletable) === 1;
                if (!deletable) {
                    await conn.end();
                    return res.status(423).json({ error: "Deletion is locked for this pixel" });
                }
            } finally {
                await conn.end();
            }
        }

        // Step 1: Delete in SimpleAudience
        const sa = await deletePixelFromSimpleAudience(clientName!);
        if (!sa.success) {
            return res.status(502).json({ error: sa.message });
        }

        // Step 2: Drop DB and remove metadata
        const db = await deleteClientFromDatabase(clientName!);
        if (!db.success) {
            return res.status(500).json({ error: db.message, simpleAudience: sa });
        }

        res.json({ success: true, simpleAudience: sa, database: db });
    } catch (error: any) {
        log("❌ Error in combined delete:", error);
        res.status(500).json({ error: error.message || 'Failed combined delete' });
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
            // Enforce DB-backed deletable flag
            const [lockRows] = await connection.execute<RowDataPacket[]>(
                'SELECT COALESCE(deletable,1) AS deletable FROM pixel_sheets WHERE id = ?',
                [pixelId]
            );
            const deletable = Number((lockRows as any[])[0]?.deletable) === 1;
            if (!deletable) {
                return res.status(423).json({ error: "Deletion is locked for this pixel" });
            }
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

// Update deletable flag for a pixel (lock/unlock deletion)
app.post("/admin/pixels/:pixelId/deletable", async (req, res) => {
    try {
        const { pixelId } = req.params;
        const { deletable, lock } = req.body as { deletable?: any; lock?: any };

        function parseToDeletable(value: any): number | null {
            if (typeof value === 'boolean') return value ? 1 : 0;
            if (typeof value === 'number') return value ? 1 : 0;
            if (typeof value === 'string') {
                const v = value.trim().toLowerCase();
                if (["1", "true", "yes", "y"].includes(v)) return 1;
                if (["0", "false", "no", "n"].includes(v)) return 0;
            }
            return null;
        }

        let desired: number | null = parseToDeletable(deletable);
        const lockParsed = parseToDeletable(lock);
        if (desired === null && lockParsed !== null) {
            desired = lockParsed === 1 ? 0 : 1; // lock=true => deletable=0
        }

        if (desired === null) {
            return res.status(400).json({ error: "Provide 'deletable' (true/false/1/0) or 'lock' (true/false)" });
        }

        log(`🔒 Setting deletable for pixel ${pixelId} to ${desired}`);

        const mysql = await import("mysql2/promise");
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        try {
            await ensureDeletableColumn(connection);

            const [rows] = await connection.execute<RowDataPacket[]>(
                'SELECT id FROM pixel_sheets WHERE id = ?',
                [pixelId]
            );
            if ((rows as any[]).length === 0) {
                return res.status(404).json({ error: "Pixel not found" });
            }

            await connection.execute(
                'UPDATE pixel_sheets SET deletable = ? WHERE id = ?',
                [desired, pixelId]
            );

            return res.json({
                success: true,
                pixelId,
                deletable: desired === 1,
                deleteLocked: desired !== 1
            });

        } finally {
            await connection.end();
        }

    } catch (error: any) {
        log("❌ Error updating deletable flag:", error);
        res.status(500).json({ error: "Failed to update deletable flag" });
    }
});

// POST /admin/pixels/:pixelId/sync -> start smart_sync.php --client=<client>
app.post("/admin/pixels/:pixelId/sync", async (req, res) => {
    try {
        const { pixelId } = req.params;
        const { clientName, sheetId } = await getClientByPixelId(pixelId);
        // Allow sync even if no sheet is connected; the PHP script will handle/log
        if (!sheetId) {
            log(`ℹ️ No sheet connected for ${clientName}; starting sync anyway`);
        }

        if (isProduction) {
            if (!fs.existsSync("/opt/auto-pixel/smart_sync.php")) {
                return res.status(500).json({ error: "smart_sync.php not found on VM (/opt/auto-pixel/smart_sync.php)" });
            }
            if (!fs.existsSync("/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json")) {
                return res.status(500).json({ error: "Google credentials missing at /etc/auto-pixel/..." });
            }
        }

        const { started, logPath, command } = await startSmartSyncForClient(clientName);
        res.json({ started, client: clientName, sheetId: sheetId || null, logPath, command });
    } catch (e: any) {
        res.status(500).json({ error: e.message || "Failed to start sync" });
    }
});

// POST /admin/sheets/sync { client: "ClientName" } -> start smart_sync for explicit client
app.post("/admin/sheets/sync", async (req, res) => {
    try {
        const { client } = req.body || {};
        if (!client || typeof client !== 'string') {
            return res.status(400).json({ error: "Provide body { client: <ClientName> }" });
        }

        const { clientName, sheetId } = await getClientByName(client);

        if (isProduction) {
            if (!fs.existsSync("/opt/auto-pixel/smart_sync.php")) {
                return res.status(500).json({ error: "smart_sync.php not found on VM (/opt/auto-pixel/smart_sync.php)" });
            }
            if (!fs.existsSync("/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json")) {
                return res.status(500).json({ error: "Google credentials missing at /etc/auto-pixel/..." });
            }
        }

        const { started, logPath, command } = await startSmartSyncForClient(clientName);
        res.json({ started, client: clientName, sheetId, logPath, command });
    } catch (e: any) {
        res.status(500).json({ error: e.message || "Failed to start client sync" });
    }
});

// GET /admin/pixels/:pixelId/sync/status -> inProgress, lastSyncAt, log tail
app.get("/admin/pixels/:pixelId/sync/status", async (req, res) => {
    try {
        const { pixelId } = req.params;
        const { clientName } = await getClientByPixelId(pixelId);

        const lockPath = path.join(SYNC_LOCK_DIR, `${clientName}.lock`);
        const inProgress = fs.existsSync(lockPath);
        const logPath = path.join(SYNC_LOG_DIR, `sync-${clientName}.log`);
        const logsPreview = readTail(logPath, 4000);

        const mysql = await import("mysql2/promise");
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        let lastSyncAt: string | null = null;
        try {
            const [rows] = await connection.execute<any[]>(
                "SELECT last_sync_at FROM pixel_sheets WHERE id = ?",
                [pixelId]
            );
            if ((rows as any[]).length && (rows as any[])[0].last_sync_at) {
                lastSyncAt = new Date((rows as any[])[0].last_sync_at).toISOString();
            }
        } finally {
            await connection.end();
        }

        res.json({ client: clientName, inProgress, lastSyncAt, logPath, logsPreview });

        // Best-effort cleanup: if pid no longer exists, remove lock
        if (inProgress) {
            try {
                const pid = fs.readFileSync(lockPath, 'utf8').trim();
                if (pid && !fs.existsSync(`/proc/${pid}`)) fs.unlinkSync(lockPath);
            } catch { }
        }
    } catch (e: any) {
        res.status(500).json({ error: e.message || "Failed to read sync status" });
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