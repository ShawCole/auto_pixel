import { Builder, By, until, Key } from "selenium-webdriver";
import chrome from "selenium-webdriver/chrome.js";
import fs from "fs";
import os from "os";
import path from "path";

const {
    AUDLAB_BUILD_USERNAME,
    AUDLAB_BUILD_PASSWORD,
    AUDLAB_IMPERSONATE_TARGET
} = process.env;

function log(message: string, data?: any) {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] [SEGMENT_CREATION] ${message}`);
    if (data) {
        console.log(JSON.stringify(data, null, 2));
    }
}

function delay(ms: number) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function cleanupTempDir(tmpDir: string) {
    try {
        if (fs.existsSync(tmpDir)) {
            fs.rmSync(tmpDir, { recursive: true, force: true });
            log(`🧹 Cleaned up temporary directory: ${tmpDir}`);
        }
    } catch (err: any) {
        log(`⚠️ Failed to cleanup temp directory: ${err.message}`);
    }
}

export async function createSegmentForPixel({ client, pixelName }: { client: string, pixelName: string }) {
    log(`🎯 Starting Segment creation for pixel: ${pixelName} (client: ${client})`);

    if (!AUDLAB_BUILD_USERNAME || !AUDLAB_BUILD_PASSWORD || !AUDLAB_IMPERSONATE_TARGET) {
        throw new Error("Missing AudienceLab Build credentials in environment variables");
    }

    let segmentName = `Last 3 Days - ${client}`;
    let segmentApi = "";

    const tmpBase = fs.mkdtempSync(path.join(os.tmpdir(), 'chrome-prof-segment-'));
    const cacheDir = path.join(tmpBase, 'cache');
    const userDataDir = path.join(tmpBase, 'user-data');
    fs.mkdirSync(cacheDir, { recursive: true });
    fs.mkdirSync(userDataDir, { recursive: true });

    const RUN_HEADLESS = (process.env.HEADLESS !== 'false' && process.env.VISIBLE_BROWSER !== 'true');

    const options = new chrome.Options()
        .addArguments('--no-sandbox')
        .addArguments('--disable-dev-shm-usage')
        .addArguments('--disable-gpu')
        .addArguments('--window-size=2560,1440')
        .addArguments(`--user-data-dir=${userDataDir}`)
        .addArguments(`--disk-cache-dir=${cacheDir}`);

    if (RUN_HEADLESS) {
        options.addArguments('--headless=new');
        log('🧪 Running in HEADLESS mode');
    }

    const driver = await new Builder().forBrowser("chrome").setChromeOptions(options as any).build();

    try {
        log("🌐 Navigating to build.audiencelab.io login...");
        await driver.get("https://build.audiencelab.io/auth/sign-in");
        await delay(2000);

        log("🔐 Logging in...");
        const usernameField = await driver.wait(until.elementLocated(By.css('input[type="email"], input[name="email"]')), 15000);
        await usernameField.sendKeys(AUDLAB_BUILD_USERNAME);
        const passwordField = await driver.findElement(By.css('input[type="password"]'));
        await passwordField.sendKeys(AUDLAB_BUILD_PASSWORD);
        const submitBtn = await driver.findElement(By.css('button[type="submit"]'));
        await driver.executeScript("arguments[0].click();", submitBtn);
        log("✅ Login submitted");
        await delay(3000);

        log(`👤 Navigating directly to user profile...`);
        await driver.get("https://build.audiencelab.io/home/simple-audience/white-label/users/9753c2f5-c917-4272-b96e-8aa59957f670");
        await delay(5000);

        log("🎭 Clicking 'Impersonate' button...");
        const impBtn = await driver.wait(until.elementLocated(By.xpath("//button[contains(., 'Impersonate')]")), 15000);
        await driver.executeScript("arguments[0].click();", impBtn);
        await delay(3000);

        log("✍️ Typing 'CONFIRM' into modal...");
        const confirmInput = await driver.wait(until.elementLocated(By.css('input[name="confirmation"]')), 10000);
        await confirmInput.clear();
        await delay(200);
        await confirmInput.sendKeys("CONFIRM");
        await delay(500);

        log("🚀 Clicking final 'Impersonate User' button...");
        const finalBtn = await driver.findElement(By.xpath("//button[contains(., 'Impersonate User')] | //form//button[@type='submit']"));
        await driver.executeScript("arguments[0].click();", finalBtn);
        await delay(12000);

        log("🎨 Navigating to AudienceLab Studio...");
        await driver.get("https://build.audiencelab.io/home/accupoint-solutions/studio");
        await delay(5000);

        // DATASET SELECTION
        log(`🔍 Searching for dataset: ${client}...`);
        const datasetSearchXPath = "/html/body/div[2]/div/div[2]/div[2]/div[3]/div[1]/div[2]/div/input";
        const datasetInput = await driver.wait(until.elementLocated(By.xpath(datasetSearchXPath)), 15000);
        await datasetInput.clear();
        await datasetInput.sendKeys(client, Key.RETURN);
        await delay(5000);

        log("🖱️ Selecting the dataset card...");
        const datasetCardXPath = "/html/body/div[2]/div/div[2]/div[2]/div[3]/div[1]/div[3]/div/div";
        try {
            const datasetCard = await driver.wait(until.elementLocated(By.xpath(datasetCardXPath)), 10000);
            await driver.executeScript("arguments[0].click();", datasetCard);
            log("✅ Selected dataset card via primary XPath (div[3])");
        } catch (cardErr) {
            log("⚠️ Primary card XPath failed, attempting fallback...");
            const fallbackXPath = `//div[contains(text(), '${client}')] | //div[contains(@class, 'card')]//div[contains(text(), '${client}')]`;
            const fallbackCard = await driver.wait(until.elementLocated(By.xpath(fallbackXPath)), 10000);
            await driver.executeScript("arguments[0].click();", fallbackCard);
            log("✅ Found card via fallback text selector");
        }
        await delay(3000);

        // FILTER LOGIC
        log("📅 Setting up filter logic...");
        const addRuleBtnXPath = "//button[contains(., 'Add First Filter')] | //button[contains(., 'Add Rule')]";
        try {
            const addRuleBtn = await driver.wait(until.elementLocated(By.xpath(addRuleBtnXPath)), 15000);
            await driver.executeScript("arguments[0].click();", addRuleBtn);
            log("✅ Clicked 'Add First Filter' / 'Add Rule' button");
        } catch (e: any) {
            log(`❌ Failed to find Add Rule/Filter button.`);
            const html = await driver.getPageSource();
            fs.writeFileSync('/tmp/studio_filter_fail.html', html);
            throw e;
        }
        await delay(2000);

        // Select Field: [Pixel] Event Timestamp
        log("⏱️ Selecting '[Pixel] Event Timestamp'...");
        try {
            const fieldInput = await driver.wait(until.elementLocated(By.id("react-select-2-input")), 10000);
            await fieldInput.sendKeys("Event Timestamp");
            await delay(1000);
            await fieldInput.sendKeys(Key.ENTER);
            log("✅ Selected 'Event Timestamp' via input type + Enter");
        } catch (e: any) {
            log(`⚠️ Failed to select field via ID: ${e.message}`);
        }
        await delay(2000);

        // Operator: Is Within Last
        log("🔄 Selecting 'Is Within Last' operator...");
        try {
            // Find button with role='combobox' and text 'equals' (default)
            const operatorBtn = await driver.wait(until.elementLocated(By.xpath("//button[@role='combobox'][.//span[contains(text(), 'equals')]]")), 5000);
            await driver.executeScript("arguments[0].click();", operatorBtn);
            await delay(1000);

            // Select 'is within last' from the portal dropdown (div[role='option'])
            const isWithinLastOpt = await driver.wait(until.elementLocated(By.xpath("//div[@role='option']//span[normalize-space()='is within last']")), 5000);
            await driver.executeScript("arguments[0].click();", isWithinLastOpt);
            log("✅ Clicked 'is within last' option");
        } catch (opErr: any) {
            log(`⚠️ Operator selection failed: ${opErr.message}`);
        }
        await delay(1000);

        // Value: 3
        log("🔢 Entering '3'...");
        try {
            const valueInput = await driver.wait(until.elementLocated(By.xpath("//input[@type='number' and @min='1']")), 5000);
            await valueInput.clear();
            await valueInput.sendKeys("3");
            log("✅ Entered value 3");
        } catch (valErr: any) {
            log(`⚠️ Value input not found: ${valErr.message}`);
        }
        await delay(1000);

        // Unit: Days
        log("🗓️ Ensuring 'Days' unit is selected...");
        try {
            const unitBtn = await driver.wait(until.elementLocated(By.xpath("//button[@role='combobox'][.//span[contains(text(), 'minutes') or contains(text(), 'days') or contains(text(), 'hours')]]")), 5000);
            const unitText = await unitBtn.getText();

            if (!unitText.includes("days") && !unitText.includes("Days")) {
                await driver.executeScript("arguments[0].click();", unitBtn);
                await delay(500);
                const daysOpt = await driver.wait(until.elementLocated(By.xpath("//div[@role='option']//span[normalize-space()='days']")), 3000);
                await driver.executeScript("arguments[0].click();", daysOpt);
                log("✅ Switched unit to Days");
            } else {
                log("✅ Unit is already Days");
            }
        } catch (unitErr: any) {
            log(`⚠️ Unit selection failed: ${unitErr.message}`);
        }
        await delay(2000);

        // Select All Fields
        log("📋 Selecting all visible fields...");
        const selectAllFieldsXPath = "//button[contains(text(), 'Select All Visible')]";
        const selectAllBtn = await driver.wait(until.elementLocated(By.xpath(selectAllFieldsXPath)), 15000);
        await driver.executeScript("arguments[0].scrollIntoView(true);", selectAllBtn);
        await delay(500);
        await driver.executeScript("arguments[0].click();", selectAllBtn);
        await delay(2000);

        // -------------------------------------------------------------------------
        // SAVE SEGMENT + EXTRACT API (Updated per user instruction)
        // -------------------------------------------------------------------------

        // 1. Click "Save Current" button
        log("💾 Clicking 'Save Current' button...");
        try {
            // User provided absolute XPath: /html/body/div[2]/div/div[2]/div[2]/div[3]/div[4]/div[1]/button
            // Also text "Save Current"
            const saveCurrentBtnXPath = "//button[contains(text(), 'Save Current')] | /html/body/div[2]/div/div[2]/div[2]/div[3]/div[4]/div[1]/button";
            const saveCurrentBtn = await driver.wait(until.elementLocated(By.xpath(saveCurrentBtnXPath)), 15000);
            await driver.executeScript("arguments[0].click();", saveCurrentBtn);
            log("✅ Clicked 'Save Current'");
        } catch (e: any) {
            log(`❌ Failed to find 'Save Current' button: ${e.message}`);
            throw e;
        }
        await delay(2000);

        // 2. Type Segment Name into Modal
        log("📝 Typing segment name into modal...");
        try {
            // User provided: /html/body/div[8]/form/div[1]/input
            // Found input has name="name" and placeholder="Enter segment name..."
            const modalInput = await driver.wait(until.elementLocated(By.css('input[name="name"]')), 10000);
            await modalInput.clear();
            await modalInput.sendKeys(segmentName);
            log("✅ Segment name entered");
        } catch (e: any) {
            log(`❌ Failed to find Segment Name modal input: ${e.message}`);
            throw e;
        }
        await delay(1000);

        // 3. Click "Save Segment" in Modal
        log("✅ Confirming 'Save Segment' in modal...");
        try {
            // User provided: /html/body/div[8]/form/div[3]/button[2]
            // Found button text "Save Segment" and type="submit"
            const saveModalBtn = await driver.findElement(By.xpath("//button[@type='submit'][contains(., 'Save Segment')]"));
            await driver.executeScript("arguments[0].click();", saveModalBtn);
            log("✅ Clicked 'Save Segment' modal button");
        } catch (e: any) {
            log(`❌ Failed to find 'Save Segment' modal button: ${e.message}`);
            throw e;
        }
        await delay(5000); // Wait for save

        // 4. Find "Show API" button in Recent Segments
        log("🔑 Looking for 'Show API' button...");
        try {
            // User provided: /html/body/div[2]/div/div[2]/div[2]/div[3]/div[4]/div[2]/div[2]/div/div/div[3]/button[2]
            // Text "Show API"
            const showApiBtn = await driver.wait(until.elementLocated(By.xpath("//button[contains(., 'Show API')]")), 15000);
            await driver.executeScript("arguments[0].click();", showApiBtn);
            log("✅ Clicked 'Show API'");
        } catch (e: any) {
            log(`❌ Failed to find 'Show API' button: ${e.message}`);
            throw e;
        }
        await delay(2000);

        // 5. Extract URL from green text div
        log("📥 Extracting URL from API div...");
        try {
            // User provided: /html/body/div[2]/div/div[2]/div[2]/div[3]/div[4]/div[2]/div[2]/div/div/div[4]/div/div[1]/div[2]
            // Class "text-green-400"
            const apiDiv = await driver.wait(until.elementLocated(By.css("div.text-green-400")), 10000);
            const rawUrl = await apiDiv.getText();
            log(`📄 Raw Text Found: ${rawUrl}`);

            const match = rawUrl.match(/segments\/([a-f0-9-]+)/i);
            if (match && match[1]) {
                segmentApi = `https://api.audiencelab.io/segments/${match[1]}`;
                log(`✅ API URL successfully parsed: ${segmentApi}`);
            } else {
                segmentApi = rawUrl; // Fallback
                log(`⚠️ Could not parse regex, using full text: ${segmentApi}`);
            }

        } catch (e: any) {
            log(`❌ Failed to extract API text: ${e.message}`);
            const html = await driver.getPageSource();
            fs.writeFileSync('/tmp/studio_api_extract_fail.html', html);
        }

        return { segmentName, segmentApi };
    } catch (err: any) {
        log(`❌ createSegmentForPixel failed: ${err.message}`);
        return { segmentName, segmentApi: "", error: err.message };
    } finally {
        await driver.quit();
        cleanupTempDir(tmpBase);
    }
}
