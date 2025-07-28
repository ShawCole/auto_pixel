import "dotenv/config";
import { updateWebsiteUrls } from "./server/src/lib/websiteUrlUpdater.js";
import readline from 'readline';

// Set up readline interface for keyboard input
const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
});

function waitForKey(key: string): Promise<void> {
    return new Promise((resolve) => {
        console.log(`\n⏸️  Press '${key}' to continue...`);

        const onKeypress = (str: string) => {
            if (str === key) {
                process.stdin.removeListener('data', onKeypress);
                rl.close();
                resolve();
            }
        };

        process.stdin.on('data', onKeypress);
        process.stdin.setRawMode(true);
        process.stdin.resume();
    });
}

async function testWebsiteUrlUpdater() {
    console.log("🧪 Testing Website URL Updater...");
    console.log("Environment variables loaded:");
    console.log({
        DB_HOST: process.env.DB_HOST,
        DB_USER: process.env.DB_USER ? "***" : "NOT_SET",
        DB_PASS: process.env.DB_PASS ? "***" : "NOT_SET",
        AUDLAB_USERNAME: process.env.AUDLAB_USERNAME ? "***" : "NOT_SET",
        AUDLAB_PASSWORD: process.env.AUDLAB_PASSWORD ? "***" : "NOT_SET",
        NODE_ENV: process.env.NODE_ENV,
        DEBUG: process.env.DEBUG
    });

    console.log("\n🔍 The script will pause after reaching the pixels page.");
    console.log("📝 You can then inspect the page structure to find the correct search input XPath.");
    console.log("⏸️  Press '`' (backtick) to continue when ready...");

    try {
        const result = await updateWebsiteUrls();
        console.log("✅ Test completed with result:", result);
    } catch (error) {
        console.error("❌ Test failed with error:", error);
    }
}

testWebsiteUrlUpdater(); 