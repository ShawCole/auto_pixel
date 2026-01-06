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
const PYTHON_BIN = process.env.PYTHON_BIN || (isProduction ? '/opt/auto-pixel/pixel-bandaid/venv/bin/python3' : 'python3');
const SYNC_LOG_DIR = process.env.SYNC_LOG_DIR || (isProduction ? "/opt/auto-pixel/logs" : "./logs");
const SYNC_LOCK_DIR = process.env.SYNC_LOCK_DIR || (isProduction ? "/opt/auto-pixel/.sync-locks" : "./locks");
const DAILY_SYNC_SCRIPT = isProduction ? "/opt/auto-pixel/pixel-bandaid/daily_sync.py" : path.resolve(__dirname, "../../pixel-bandaid/daily_sync.py");

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

async function watchPidAndCleanupLock(pidStr: string, lockPath: string, clientName: string) {
    const n = Number(pidStr);
    if (!n || Number.isNaN(n)) return;
    const interval = setInterval(async () => {
        if (!isPidAlive(pidStr)) {
            try {
                if (fs.existsSync(lockPath)) {
                    fs.unlinkSync(lockPath);
                    log(`🧹 Cleared sync lock for ${clientName} (process ${pidStr} ended)`);

                    // Update DB status to idle
                    const mysql = await import("mysql2/promise");
                    const connection = await mysql.createConnection({
                        host: process.env.DB_HOST,
                        user: process.env.DB_USER,
                        password: process.env.DB_PASS,
                        database: 'pixel'
                    });
                    await connection.execute("UPDATE pixel_sheets SET sync_status = 'idle' WHERE client_name = ?", [clientName]);
                    await connection.end();
                }
            } catch (err: any) {
                log(`⚠️ cleanup error for ${clientName}:`, err.message);
            }
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

async function ensureSyncStatusColumn(connection: any): Promise<void> {
    try {
        const [cols] = await connection.execute(
            "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'pixel_sheets' AND COLUMN_NAME = 'sync_status'",
            ['pixel']
        );
        const cnt = Number((cols as any[])[0].cnt ?? (cols as any[])[0].CNT ?? 0);
        if (cnt === 0) {
            await connection.execute(
                "ALTER TABLE pixel_sheets ADD COLUMN sync_status VARCHAR(50) DEFAULT 'idle' AFTER poll_segments"
            );
            log("🛠️ Added 'sync_status' column to pixel.pixel_sheets (default: idle)");
        }
    } catch (err: any) {
        log("⚠️ ensureSyncStatusColumn error:", { message: err.message });
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
async function triggerFullSync(clientNameForImmediate?: string): Promise<void> {
    try {
        log(`🔄 Triggering full dynamic sync to include new sheets`);

        // Use different command based on environment
        const isProduction = process.env.NODE_ENV === 'production';
        const phpBin = process.env.PHP_BIN || 'php';
        log('🔧 PHP binary for dynamic sync:', { phpBin });
        // Prefer smart_sync (supports new headers and both tabs) and allow immediate single-client sync
        const syncCommand = isProduction
            ? (clientNameForImmediate
                ? `sudo -u www-data ${phpBin} /opt/auto-pixel/smart_sync.php --client=${clientNameForImmediate}`
                : `sudo -u www-data ${phpBin} /opt/auto-pixel/smart_sync.php`)
            : (clientNameForImmediate
                ? `${phpBin} ../smart_sync.php --client=${clientNameForImmediate}`
                : `${phpBin} ../smart_sync.php`);

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
    const { client, website, pixelName: pixelNameRaw } = req.body;

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
        // Determine pixel_name (preferred: website hostname; fallback: client)
        const derivePixelName = (site: string, fallback: string): string => {
            try {
                const u = new URL(site);
                const host = (u.hostname || '').replace(/^www\./i, '').trim();
                return host || fallback;
            } catch {
                return fallback;
            }
        };
        const pixelName: string = (typeof pixelNameRaw === 'string' && pixelNameRaw.trim())
            ? pixelNameRaw.trim()
            : derivePixelName(website, client);

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
                    'SELECT id FROM pixel_sheets WHERE client_name = ? AND pixel_name = ? LIMIT 1',
                    [client, pixelName]
                );
                if ((rows as any[]).length) {
                    log('⛔ Pixel already exists for client/pixel_name', { client, pixelName, existingId: (rows as any[])[0].id });
                    return res.status(409).json({ error: 'Pixel already exists for this client and pixel_name', client, pixelName, existingId: (rows as any[])[0].id });
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

        if (!finalWebhookTestUuidVerified) {
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
                                UUID: TEST_UUID
                            },
                            event_data: {
                                url: `https://example.com/?wldup=${randomTag}&fbclid=t`,
                                referrer: 'http://m.facebook.com/',
                                title: 'example.com'
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

                // Poll client DB for TEST_UUID up to 10s (shorter since Selenium already tried)
                const mysql = await import('mysql2/promise');
                const conn = await mysql.createConnection({
                    host: process.env.DB_HOST,
                    user: process.env.DB_USER,
                    password: process.env.DB_PASS,
                    database: client,
                    connectTimeout: 30000
                });
                try {
                    for (let i = 0; i < 10; i++) {
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
        } else {
            log('⏭️ Skipping canonical webhook verify (already verified by Selenium)');
        }

        // 4) Create Google Sheet for the client (ASYNC after response) and upsert pixel_sheets LAST
        // Respond fast: sheet creation will run in background; store empty sheet URL for now
        let sheetUrl: string | undefined;

        // Upsert central pixel.metadata row in pixel.pixel_sheets (LAST)
        try {
            async function upsertPixelSheetRow(params: { client: string; pixelName: string; website: string; pixelScript: string; sheetUrl?: string }) {
                const { client, pixelName, website, pixelScript, sheetUrl } = params;

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

                log("🧾 Preparing pixel_sheets upsert", { client, pixelName, website });
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
                            (client_name, pixel_name, client_website, pixel_id, pixel_script, sheet_id, sheet_url, deletable)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE
                            client_website = VALUES(client_website),
                            pixel_id = IF(VALUES(pixel_id) <> '', VALUES(pixel_id), pixel_id),
                            pixel_script = VALUES(pixel_script),
                            sheet_id = IF(VALUES(sheet_id) <> '', VALUES(sheet_id), sheet_id),
                            sheet_url = IF(VALUES(sheet_url) <> '', VALUES(sheet_url), sheet_url)
                    `;
                    await connection.execute(sql, [client, pixelName, website, pixelIdFromScript, pixelScript, sheetId, sheetUrl || '']);
                    log("✅ Upserted pixel_sheets row", { client, pixelName, website, pixelIdFromScript, sheetId, hasSheetUrl: !!sheetUrl });
                } finally {
                    await connection.end();
                }
            }

            await upsertPixelSheetRow({ client, pixelName, website, pixelScript: result.pixelCode, sheetUrl });
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

        // Background: immediately refresh metrics for this client (fast path via superpixel_visitors)
        (async () => {
            try {
                const mysql = await import('mysql2/promise');
                // Extract pixel id from snippet
                const m = (result.pixelCode || '').match(/pixels\/([^/'"\s]+)\/p\.js/i);
                const pixelId = m && m[1] ? m[1] : '';
                if (!pixelId) return;

                // Compute metrics from visitor snapshot (no DISTINCT)
                const clientConn = await mysql.createConnection({
                    host: process.env.DB_HOST,
                    user: process.env.DB_USER,
                    password: process.env.DB_PASS,
                    database: client,
                    connectTimeout: 30000
                });
                let visitors = 0; let events = 0; let lastEventAt: any = null;
                try {
                    const [r1] = await clientConn.execute<any[]>("SELECT COUNT(*) AS v FROM superpixel_visitors WHERE pixel_id = ?", [pixelId]);
                    visitors = Number((r1 as any[])[0]?.v || 0);
                    const [r2] = await clientConn.execute<any[]>("SELECT COALESCE(SUM(event_count),0) AS e FROM superpixel_visitors WHERE pixel_id = ?", [pixelId]);
                    events = Number((r2 as any[])[0]?.e || 0);
                    const [r3] = await clientConn.execute<any[]>("SELECT MAX(last_seen_at) AS last FROM superpixel_visitors WHERE pixel_id = ?", [pixelId]);
                    lastEventAt = (r3 as any[])[0]?.last || null;
                } catch { }
                try { await clientConn.end(); } catch { }

                // Update central row
                const central = await mysql.createConnection({
                    host: process.env.DB_HOST,
                    user: process.env.DB_USER,
                    password: process.env.DB_PASS,
                    database: 'pixel',
                    connectTimeout: 30000
                });
                try {
                    await central.execute(
                        "UPDATE pixel_sheets SET visitors = ?, events = ?, last_event_at = COALESCE(?, last_event_at) WHERE client_name = ?",
                        [visitors, events, lastEventAt, client]
                    );
                    log('✅ [bg] Immediate metrics updated', { client, visitors, events, lastEventAt });
                } finally {
                    await central.end();
                }
            } catch (e: any) {
                log('⚠️ [bg] Metrics refresh failed:', { message: e?.message });
            }
        })();

        // Background: create the Google Sheet, update pixel_sheets, then trigger sync
        (async () => {
            try {
                const pixelIdForSheet = `${client.toLowerCase()}-pixel-${Date.now()}`;
                const sheetResult = await createGoogleSheet(client, pixelIdForSheet, website);
                log(`🔍 [bg] Sheet creation result:`, sheetResult);
                if (sheetResult.sheetUrl) {
                    const createdSheetUrl = sheetResult.sheetUrl;
                    try {
                        const mysql = await import('mysql2/promise');
                        const connection = await mysql.createConnection({
                            host: process.env.DB_HOST,
                            user: process.env.DB_USER,
                            password: process.env.DB_PASS,
                            database: 'pixel',
                            connectTimeout: 30000
                        });
                        try {
                            const extractPixelIdFromScript = (snippet: string): string | null => {
                                try {
                                    const m = (result.pixelCode || '').match(/\bhttps?:\/\/(?:cdn\.)?v3\.identitypxl\.app\/pixels\/([^\/'"\s]+)\/p\.js/i);
                                    return m && m[1] ? m[1] : null;
                                } catch { return null; }
                            };
                            const extractSheetId = (url?: string): string | null => {
                                if (!url) return null;
                                try { const m = url.match(/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/); return m && m[1] ? m[1] : null; } catch { return null; }
                            };
                            const pixelIdFromScript = extractPixelIdFromScript(result.pixelCode || '') || '';
                            const bgSheetId = extractSheetId(createdSheetUrl) || `PENDING_${Date.now()}`;
                            const sql = `
                                INSERT INTO pixel_sheets
                                    (client_name, pixel_name, client_website, pixel_id, pixel_script, sheet_id, sheet_url, deletable)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                                ON DUPLICATE KEY UPDATE
                                    client_website = VALUES(client_website),
                                    pixel_id = IF(VALUES(pixel_id) <> '', VALUES(pixel_id), pixel_id),
                                    pixel_script = VALUES(pixel_script),
                                    sheet_id = IF(VALUES(sheet_id) <> '', VALUES(sheet_id), sheet_id),
                                    sheet_url = IF(VALUES(sheet_url) <> '', VALUES(sheet_url), sheet_url)
                            `;
                            await connection.execute(sql, [client, pixelName, website, pixelIdFromScript, result.pixelCode, bgSheetId, createdSheetUrl]);
                            log('✅ [bg] Updated pixel_sheets with sheet URL', { client, pixelName, bgSheetId });
                        } finally {
                            await connection.end();
                        }

                        // Schedule full sync shortly after sheet creation
                        log('[bg] ⏰ Scheduling full dynamic sync in 10 seconds...');
                        setTimeout(() => {
                            log('[bg] 🚀 Executing triggerFullSync now...');
                            triggerFullSync();
                        }, 10000);
                    } catch (e: any) {
                        log('⚠️ [bg] Failed to upsert pixel_sheets with sheet URL:', { message: e?.message });
                    }
                } else {
                    log('⚠️ [bg] Sheet creation failed:', sheetResult.error);
                }
            } catch (bgErr: any) {
                log('⚠️ [bg] Error during async sheet creation:', { message: bgErr?.message });
            }
        })();
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
    // Run directly as the PM2 user (auto-pixel) to avoid sudo/NOPASSWD issues
    const cmd = `${PHP_BIN} ${phpScript} --client=${clientName}`;

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

/**
 * Triggers a targeted daily_sync.py run for a single pixel.
 * This includes data download, hashing, visitor enrichment, sheet sync, and oplet update.
 */
async function startDailySyncForPixel(params: { pixelName: string, clientName: string }): Promise<{ started: boolean; logPath: string; command: string }> {
    const { pixelName, clientName } = params;

    log(`🚀 Preparing targeted sync for ${clientName} (${pixelName})...`);
    log(`🔧 Environment: isProduction=${isProduction}`);

    ensureDir(SYNC_LOG_DIR);
    ensureDir(SYNC_LOCK_DIR);

    const lockPath = path.join(SYNC_LOCK_DIR, `${clientName}.lock`);
    if (fs.existsSync(lockPath)) {
        const m = fs.readFileSync(lockPath, 'utf8').trim();
        if (!m || !isPidAlive(m)) {
            try { fs.unlinkSync(lockPath); } catch { }
            log(`🧹 Removed stale sync lock for ${clientName}`);
        } else {
            throw new Error(`Sync already in progress for ${clientName} (pid ${m})`);
        }
    }

    const logPath = path.join(SYNC_LOG_DIR, `sync-${clientName}.log`);

    // Command translates to: python3 daily_sync.py --days 1 --manual-pixel <pixel> --manual-client <client> --local-db
    const pythonBin = isProduction ? "/opt/auto-pixel/pixel-bandaid/venv/bin/python3" : "python3";
    const scriptPath = isProduction ? "/opt/auto-pixel/pixel-bandaid/daily_sync.py" : path.resolve("../pixel-bandaid/daily_sync.py");

    const cmd = `${pythonBin} ${scriptPath} --days 1 --manual-pixel ${pixelName} --manual-client ${clientName} --local-db`;
    const spawnCmd = `nohup ${cmd} >> ${logPath} 2>&1 & echo $!`;
    const cwd = isProduction ? "/opt/auto-pixel/pixel-bandaid" : path.resolve(__dirname, "../../pixel-bandaid");

    log(`💻 Executing: ${cmd}`);
    log(`📝 Logs at: ${logPath}`);
    log(`📂 Working Directory: ${cwd}`);

    // Update DB status to syncing
    const mysql = await import("mysql2/promise");
    const connection = await mysql.createConnection({
        host: process.env.DB_HOST,
        user: process.env.DB_USER,
        password: process.env.DB_PASS,
        database: 'pixel'
    });
    await connection.execute("UPDATE pixel_sheets SET sync_status = 'syncing' WHERE client_name = ?", [clientName]);
    await connection.end();

    return await new Promise((resolve, reject) => {
        exec(spawnCmd, { cwd, shell: '/bin/bash' }, (err, stdout) => {
            if (err) {
                log(`❌ Spawn Error for ${clientName}:`, err);
                return reject(err);
            }
            const pid = (stdout || "").toString().trim();
            log(`✅ Started sync process with PID: ${pid}`);
            if (pid) {
                try { fs.writeFileSync(lockPath, pid); } catch { }
                watchPidAndCleanupLock(pid, lockPath, clientName);
            }
            resolve({ started: true, logPath, command: cmd });
        });
    });
}

async function getClientByPixelId(pixelId: string): Promise<{ clientName: string; pixelName: string; sheetId: string | null }> {
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
            "SELECT client_name AS clientName, pixel_name AS pixelName, sheet_id AS sheetId FROM pixel_sheets WHERE id = ?",
            [pixelId]
        );
        if (!(rows as any[]).length) throw new Error("Pixel not found");
        return {
            clientName: (rows as any[])[0].clientName,
            pixelName: (rows as any[])[0].pixelName,
            sheetId: (rows as any[])[0].sheetId || null
        };
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
    let connection: any = null;
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
        connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel',
            connectTimeout: 30000
        });

        log("✅ Database connection established");

        try {
            // Ensure schema has metadata columns
            await ensureDeletableColumn(connection);
            await ensureSyncStatusColumn(connection);
            // Query pixel_sheets table and return live metrics (visitors/events) from central columns
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
                    ps.last_event_at as lastEventAt,
                    ps.oplet as oplet,
                    ps.sync_status as syncStatus,
                    ps.industry as industry,
                    ps.paused as paused,
                    ps.paused_reason as pausedReason,
                    ps.segment_api as segmentApi,
                    ps.segment_name as segmentName,
                    NULL as deletionScheduled,
                    COALESCE(ps.visitors, 0) as visitorCount,
                    COALESCE(ps.events, 0) as eventCount
                FROM pixel_sheets ps
                ORDER BY ps.created_at DESC
            `;

            const [rows] = await connection.execute(query);

            // Transform the data to match the frontend interface
            const pixels = (rows as any[]).map((row: any) => ({
                id: row.id.toString(),
                clientName: row.clientName,
                website: row.clientWebsite || (row.pixelId ? `https://${row.pixelId.replace('-', '.')}.com` : 'N/A'),
                sheetUrl: row.sheetUrl,
                segmentApi: row.segmentApi,
                segmentName: row.segmentName,
                createdAt: row.createdAt.toISOString(),
                industry: row.industry || 'Uncategorized',
                status: (() => {
                    const now = new Date();
                    const created = new Date(row.createdAt);

                    // Priority: If eventCount > 0, we have valid server events. 
                    // (Ignoring lastEventAt if eventCount is 0 to exclude test UUID events)
                    const lastServerEvent = row.eventCount > 0 && row.lastEventAt ? new Date(row.lastEventAt) : null;

                    // Parse oplet as a date if it's a timestamp
                    let opletDate: Date | null = null;
                    if (row.oplet && !row.oplet.includes("No Resolutions Found") && row.oplet.length > 5) {
                        const parsed = new Date(row.oplet);
                        if (!isNaN(parsed.getTime())) {
                            opletDate = parsed;
                        }
                    }

                    // Priority Rule: Server date is the Source of Truth for the bubble.
                    // Fallback to A_Lab only if Server has NO data (eventCount 0).
                    let bubbleTimestamp = lastServerEvent;
                    let isFallback = false;

                    if (!bubbleTimestamp && opletDate) {
                        bubbleTimestamp = opletDate;
                        isFallback = true;
                    }

                    const isNew = (now.getTime() - created.getTime()) < 24 * 60 * 60 * 1000;
                    const hasNoResolutions = !row.oplet || row.oplet.includes("No Resolutions Found");
                    const hasNoEvents = (row.eventCount || 0) === 0;

                    let primary = 'Active';

                    if (row.paused === 1) {
                        primary = 'Paused';
                    } else if (bubbleTimestamp) {
                        const hoursSinceLastEvent = (now.getTime() - bubbleTimestamp.getTime()) / (1000 * 60 * 60);
                        if (hoursSinceLastEvent > 24) {
                            const days = Math.floor(hoursSinceLastEvent / 24);
                            primary = `Last: ${days}d ago`;
                        } else if (isFallback && !isNew) {
                            // Platform sees events, but Server remains at 0.
                            primary = 'Sync Pending';
                        } else {
                            primary = 'Active';
                        }
                    } else if (row.eventCount > 0) {
                        // Events exist but no timestamp found
                        primary = 'Active';
                    } else if (hasNoResolutions && hasNoEvents) {
                        // Explicitly requested: both empty => Not Installed
                        primary = 'Not Installed';
                    } else if (isNew) {
                        primary = 'Awaiting Data';
                    } else {
                        primary = 'Not Installed';
                    }

                    return {
                        primary,
                        badges: [],
                        reason: row.pausedReason || '',
                        nextAction: row.paused === 1 ? 'Resume' : 'Pause'
                    };
                })(),
                metrics: {
                    lastRealEventAt: row.lastEventAt ? row.lastEventAt.toISOString() : null
                },
                eventCount: parseInt(row.eventCount) || 0,
                visitorCount: parseInt(row.visitorCount) || 0,
                oplet: row.oplet,
                syncStatus: row.syncStatus || 'idle',
                deletionScheduled: row.deletionScheduled ? row.deletionScheduled.toISOString() : null,
                lastSyncAt: row.lastSyncAt ? row.lastSyncAt.toISOString() : null,
                deleteLocked: !(row.deletable === 1 || row.deletable === '1')
            }));

            log(`✅ Fetched ${pixels.length} pixels from database`);
            res.json({
                version: "1.0.2-oplet-status-v7",
                pixels
            });

        } finally {
            if (connection) await connection.end();
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
        const { clientName, pixelName, sheetId } = await getClientByPixelId(pixelId);
        // Allow sync even if no sheet is connected
        if (!sheetId) {
            log(`ℹ️ No sheet connected for ${clientName}; starting targeted sync anyway`);
        }

        const { started, logPath, command } = await startDailySyncForPixel({ pixelName, clientName });
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
        const clientInfo = await getClientByPixelId(pixelId);
        const { clientName, pixelName } = clientInfo;

        const logPath = path.join(SYNC_LOG_DIR, `sync-${clientName}.log`);
        const lockPath = path.join(SYNC_LOCK_DIR, `${clientName}.lock`);

        let inProgress = false;
        if (fs.existsSync(lockPath)) {
            const pid = fs.readFileSync(lockPath, 'utf8').trim();
            inProgress = isPidAlive(pid);

            // Cleanup stale lock if pid is dead
            if (!inProgress) {
                try { fs.unlinkSync(lockPath); } catch { }
            }
        }

        const logs = readTail(logPath, 5000); // Get last 5KB

        const mysql = await import("mysql2/promise");
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: 'pixel'
        });

        let statusData: any = {};
        try {
            const [rows] = await connection.execute<any[]>(
                "SELECT last_sync_at, sync_status FROM pixel_sheets WHERE id = ?",
                [pixelId]
            );
            if ((rows as any[]).length) {
                statusData = (rows as any[])[0];
            }
        } finally {
            await connection.end();
        }

        res.json({
            inProgress,
            logs,
            clientName,
            pixelName,
            lastSyncAt: statusData.last_sync_at,
            syncStatus: statusData.sync_status
        });
    } catch (err: any) {
        res.status(500).json({ error: err.message || "Failed to read sync status" });
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