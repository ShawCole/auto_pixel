import { Builder, By, until, WebDriver } from "selenium-webdriver";
import chrome from "selenium-webdriver/chrome";
import mysql from "mysql2/promise";

const { AUDLAB_USERNAME, AUDLAB_PASSWORD, DB_HOST, DB_USER, DB_PASS } = process.env;

// Enable verbose logging
const DEBUG = process.env.DEBUG === '*' || process.env.NODE_ENV === 'development';

function log(message: string, data?: any) {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] [WEBSITE_UPDATER] ${message}`);
    if (data && DEBUG) {
        console.log(JSON.stringify(data, null, 2));
    }
}

// Helper function to add delays for debugging
function delay(ms: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, ms));
}

interface PixelData {
    id: number;
    client_name: string;
    client_website: string | null;
}

export async function updateWebsiteUrls() {
    if (!AUDLAB_USERNAME || !AUDLAB_PASSWORD) {
        throw new Error("Missing AudienceLab credentials in environment variables");
    }

    // Chrome options: optimized for VM deployment
    const options = new chrome.Options()
        //.addArguments('--headless=new') // DISABLED for debugging - Enable headless mode for production
        .addArguments('--no-sandbox')
        .addArguments('--disable-dev-shm-usage')
        .addArguments('--disable-gpu')
        .addArguments('--window-size=1920,1080')
        .addArguments('--disable-web-security')
        .addArguments('--allow-running-insecure-content')
        .addArguments('--disable-extensions')
        .addArguments('--disable-background-timer-throttling')
        .addArguments('--disable-backgrounding-occluded-windows')
        .addArguments('--disable-renderer-backgrounding')
        .addArguments('--disable-features=VizDisplayCompositor')
        .addArguments(`--user-data-dir=/tmp/chrome-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`) // Unique user data directory
        .addArguments('--disable-background-networking')
        .addArguments('--disable-default-apps')
        .addArguments('--disable-sync')
        .addArguments('--metrics-recording-only')
        .addArguments('--no-first-run')
        .addArguments('--safebrowsing-disable-auto-update')
        .addArguments('--disable-component-update');

    // Only set Chrome path on macOS (development)
    if (process.platform === 'darwin') {
        const chromePath = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";
        options.setBinaryPath(chromePath);
    }

    log("🚀 Initializing Chrome WebDriver...");
    const driver = await new Builder()
        .forBrowser("chrome")
        .setChromeOptions(options as any)
        .build();

    try {
        log("✅ Chrome WebDriver initialized successfully");

        // Get pixels that need website URL updates
        const pixels = await getPixelsNeedingUpdates();
        if (pixels.length === 0) {
            log("✅ No pixels found that need website URL updates");
            return { updated: 0, failed: 0 };
        }

        log(`📊 Found ${pixels.length} pixels that need website URL updates`);

        // Step 1: Navigate to login page
        log("🌐 Navigating to SimpleAudience login page...");
        await driver.get("https://app.simpleaudience.io/auth/sign-in");
        log("✅ Reached login page");
        await delay(200); // Wait for page load

        log("⏳ Waiting for username field...");
        await driver.wait(until.elementLocated(By.xpath("/html/body/div/div/form/div[1]/input")), 10000);
        log("✅ Username field found");
        await delay(100); // Brief wait

        // Step 2: Enter credentials
        log("🔐 Entering login credentials...");
        const usernameXPath = "/html/body/div/div/form/div[1]/input";
        const usernameField = await driver.findElement(By.xpath(usernameXPath));
        log(`🔍 VERBOSE: Username field found with XPath: ${usernameXPath}`);

        const passwordXPath = "/html/body/div/div/form/div[2]/input";
        const passwordField = await driver.findElement(By.xpath(passwordXPath));
        log(`🔍 VERBOSE: Password field found with XPath: ${passwordXPath}`);

        log("📝 Typing username...");
        await usernameField.clear();
        await delay(100); // Brief wait
        await usernameField.sendKeys(AUDLAB_USERNAME as string);
        log(`🔍 VERBOSE: Username entered successfully`);
        await delay(100); // Brief wait

        log("📝 Typing password...");
        await passwordField.clear();
        await delay(100); // Brief wait
        await passwordField.sendKeys(AUDLAB_PASSWORD as string);
        log(`🔍 VERBOSE: Password entered successfully`);
        await delay(200); // Wait before clicking

        // Click the "Sign in with Email" button
        log("🖱️  Clicking sign in button...");
        const signInXPath = "/html/body/div/div/form/button";
        const signInButton = await driver.findElement(By.xpath(signInXPath));
        log(`🔍 VERBOSE: Sign in button found with XPath: ${signInXPath}`);

        await signInButton.click();
        log("✅ Sign in button clicked");
        await delay(200); // Wait for login to complete

        // Step 3: Select the AccuPoint Solutions option (dynamic approach)
        log("🎯 Waiting for audience selection page...");
        await delay(2000); // Wait for page to fully load

        // Try to find the AccuPoint Solutions button dynamically
        let accuPointButton;
        try {
            // First try: Look for text containing "AccuPoint"
            accuPointButton = await driver.findElement(By.xpath("//div[contains(text(), 'AccuPoint')]"));
            log("✅ Found AccuPoint Solutions button by text content");
        } catch (e) {
            try {
                // Second try: Look for the specific XPath
                accuPointButton = await driver.findElement(By.xpath("/html/body/div/div/div[2]/div[2]/div[2]/div/div/a/div"));
                log("✅ Found AccuPoint Solutions button by XPath");
            } catch (e2) {
                // Third try: Look for any clickable div that might be the audience option
                const clickableDivs = await driver.findElements(By.xpath("//div[contains(@class, 'cursor-pointer') or contains(@class, 'clickable')]"));
                for (const div of clickableDivs) {
                    const text = await div.getText();
                    if (text && text.includes('AccuPoint')) {
                        accuPointButton = div;
                        log("✅ Found AccuPoint Solutions button by class and text");
                        break;
                    }
                }
                if (!accuPointButton) {
                    throw new Error("Could not find AccuPoint Solutions button");
                }
            }
        }

        log("🖱️  Clicking AccuPoint Solutions option...");
        await accuPointButton.click();
        log("✅ AccuPoint Solutions option selected");
        await delay(2000); // Wait for dashboard to load

        // Step 4: Click the pixel menu item
        log("📊 Waiting for dashboard to load...");
        const pixelMenuXPath = "/html/body/div/div/div[1]/div/div[2]/div/div[2]/div[2]/div[2]/ul/li[1]/a";
        await driver.wait(until.elementLocated(By.xpath(pixelMenuXPath)), 15000);
        log("✅ Dashboard loaded");
        await delay(200); // Brief wait

        log("🖱️  Clicking pixels menu item...");
        const pixelMenuItem = await driver.findElement(By.xpath(pixelMenuXPath));
        log(`🔍 VERBOSE: Pixel menu item found with XPath: ${pixelMenuXPath}`);

        await pixelMenuItem.click();
        log("✅ Pixels section opened");
        await delay(1000); // Wait for pixels page to load

        // Step 5: Process each pixel
        let updatedCount = 0;
        let failedCount = 0;

        for (const pixel of pixels) {
            try {
                log(`🔍 Processing pixel: ${pixel.client_name} (ID: ${pixel.id})`);

                // Find and clear search input
                const searchInput = await driver.wait(
                    until.elementLocated(By.xpath("/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[1]/div/input")),
                    5000
                );

                // Clear existing search
                await searchInput.clear();
                await delay(100);

                // Enter client name
                await searchInput.sendKeys(pixel.client_name);
                await searchInput.sendKeys('\n'); // Press Enter
                log(`🔍 Searched for: ${pixel.client_name}`);

                // Wait for search results
                await delay(3000);

                // Try to find the website URL in the first row
                try {
                    const websiteCell = await driver.wait(
                        until.elementLocated(By.xpath("/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/table/tbody/tr[1]/td[2]")),
                        5000
                    );

                    const websiteUrl = await websiteCell.getText();
                    const trimmedUrl = websiteUrl.trim();

                    if (trimmedUrl && trimmedUrl !== "N/A" && trimmedUrl !== "" && trimmedUrl !== "-") {
                        log(`✅ Found website URL for ${pixel.client_name}: ${trimmedUrl}`);

                        // Update database
                        await updatePixelWebsite(pixel.id, trimmedUrl);
                        updatedCount++;
                        log(`✅ Updated pixel ID ${pixel.id} with website URL: ${trimmedUrl}`);
                    } else {
                        log(`⚠️ No valid website URL found for ${pixel.client_name} (value: "${trimmedUrl}")`);
                        failedCount++;
                    }
                } catch (searchError) {
                    log(`❌ No search results found for ${pixel.client_name}: ${searchError}`);
                    failedCount++;
                }

                // Small delay between searches
                await delay(2000);

            } catch (error) {
                log(`❌ Error processing pixel ${pixel.client_name}: ${error}`);
                failedCount++;
                continue;
            }
        }

        log(`✅ Update process completed. Updated: ${updatedCount}, Failed: ${failedCount}`);
        return { updated: updatedCount, failed: failedCount };

    } catch (err: any) {
        log(`❌ Update process failed: ${err.message}`);
        return { error: err.message || String(err) };
    } finally {
        try {
            await driver.quit(); // Enable proper browser cleanup
            log("🧹 Browser session cleaned up");
        } catch (cleanupError) {
            log("⚠️ Browser cleanup error:", cleanupError);
        }
    }
}

async function getPixelsNeedingUpdates(): Promise<PixelData[]> {
    const connection = await mysql.createConnection({
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        database: 'pixel',
        connectTimeout: 30000
    });

    try {
        const [rows] = await connection.execute(`
            SELECT id, client_name, client_website 
            FROM pixel_sheets 
            WHERE client_website IS NULL OR client_website = ''
            ORDER BY created_at DESC
        `);

        return rows as PixelData[];
    } finally {
        await connection.end();
    }
}

async function updatePixelWebsite(pixelId: number, websiteUrl: string): Promise<void> {
    const connection = await mysql.createConnection({
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        database: 'pixel',
        connectTimeout: 30000
    });

    try {
        await connection.execute(
            'UPDATE pixel_sheets SET client_website = ? WHERE id = ?',
            [websiteUrl, pixelId]
        );
    } finally {
        await connection.end();
    }
} 