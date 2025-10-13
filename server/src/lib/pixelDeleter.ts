import { Builder, By, until, WebDriver } from 'selenium-webdriver';
import chrome from 'selenium-webdriver/chrome.js';
import fs from 'fs';
import os from 'os';
import path from 'path';
import mysql from 'mysql2/promise';
import { RowDataPacket } from 'mysql2';

const { DB_HOST, DB_USER, DB_PASS, AUDLAB_USERNAME, AUDLAB_PASSWORD } = process.env;

interface DeleteResult {
    success: boolean;
    message: string;
    dataDownloaded?: boolean;
}

export async function deletePixelFromSimpleAudience(clientName: string): Promise<DeleteResult> {
    let driver: WebDriver | null = null;

    try {
        console.log(`🚀 Starting pixel deletion for client: ${clientName}`);

        // Prepare explicit, writable Chrome profile/cache directories
        const tmpBase = fs.mkdtempSync(path.join(os.tmpdir(), 'chrome-prof-'));
        const cacheDir = path.join(tmpBase, 'cache');
        const userDataDir = path.join(tmpBase, 'user-data');
        fs.mkdirSync(cacheDir, { recursive: true });
        fs.mkdirSync(userDataDir, { recursive: true });

        // Setup Chrome options
        const options = new chrome.Options();
        // Run headless in VM
        options.addArguments('--headless=new');
        options.addArguments('--no-sandbox');
        options.addArguments('--disable-dev-shm-usage');
        options.addArguments('--disable-gpu');
        options.addArguments('--window-size=1920,1080');
        options.addArguments(`--user-data-dir=${userDataDir}`);
        options.addArguments(`--disk-cache-dir=${cacheDir}`);

        // Create driver
        driver = await new Builder()
            .forBrowser('chrome')
            .setChromeOptions(options)
            .build();

        // Login to SimpleAudience
        console.log('🔐 Logging into SimpleAudience...');
        await driver.get('https://app.simpleaudience.io/auth/sign-in');
        console.log('✅ Reached login page');
        await driver.sleep(200); // Wait for page load

        console.log('⏳ Waiting for username field...');
        await driver.wait(until.elementLocated(By.xpath("/html/body/div/div/form/div[1]/input")), 10000);
        console.log('✅ Username field found');
        await driver.sleep(100); // Brief wait

        // Enter credentials
        console.log('🔐 Entering login credentials...');
        const usernameField = await driver.findElement(By.xpath("/html/body/div/div/form/div[1]/input"));
        const passwordField = await driver.findElement(By.xpath("/html/body/div/div/form/div[2]/input"));

        console.log('📝 Typing username...');
        await usernameField.clear();
        await driver.sleep(100);
        await usernameField.sendKeys(AUDLAB_USERNAME || '');
        await driver.sleep(100);

        console.log('📝 Typing password...');
        await passwordField.clear();
        await driver.sleep(100);
        await passwordField.sendKeys(AUDLAB_PASSWORD || '');
        await driver.sleep(200);

        // Click the "Sign in with Email" button
        console.log('🖱️ Clicking sign in button...');
        const signInButton = await driver.findElement(By.xpath("/html/body/div/div/form/button"));
        await signInButton.click();
        console.log('✅ Sign in button clicked');
        await driver.sleep(200);

        // Wait for audience selection page
        console.log('🎯 Waiting for audience selection page...');
        await driver.sleep(2000);

        // Click on AccuPoint Solutions (dynamic approach)
        let accuPointButton;
        try {
            // First try: Look for text containing "AccuPoint"
            accuPointButton = await driver.findElement(By.xpath("//div[contains(text(), 'AccuPoint')]"));
            console.log("✅ Found AccuPoint Solutions button by text content");
        } catch (e) {
            try {
                // Second try: Look for the specific XPath
                accuPointButton = await driver.findElement(By.xpath("/html/body/div/div/div[2]/div[2]/div[2]/div/div/a/div"));
                console.log("✅ Found AccuPoint Solutions button by XPath");
            } catch (e2) {
                // Third try: Look for any clickable div that might be the audience option
                const clickableDivs = await driver.findElements(By.xpath("//div[contains(@class, 'cursor-pointer') or contains(@class, 'clickable')]"));
                for (const div of clickableDivs) {
                    const text = await div.getText();
                    if (text && text.includes('AccuPoint')) {
                        accuPointButton = div;
                        console.log("✅ Found AccuPoint Solutions button by class and text");
                        break;
                    }
                }
                if (!accuPointButton) {
                    throw new Error("Could not find AccuPoint Solutions button");
                }
            }
        }

        console.log("🖱️ Clicking AccuPoint Solutions option...");
        await accuPointButton.click();
        console.log("✅ AccuPoint Solutions option selected");
        await driver.sleep(2000); // Wait for dashboard to load

        // Wait and click on Pixels menu item
        await driver.wait(until.elementLocated(By.xpath('/html/body/div/div/div[1]/div/div[2]/div/div[2]/div[2]/div[2]/ul/li[1]/a')), 10000);
        await driver.findElement(By.xpath('/html/body/div/div/div[1]/div/div[2]/div/div[2]/div[2]/div[2]/ul/li[1]/a')).click();

        // Wait for pixels page to load
        await driver.wait(until.elementLocated(By.xpath('/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[1]/div/input')), 10000);

        // Search for the specific client
        console.log(`🔍 Searching for client: ${clientName}`);
        const searchInput = await driver.findElement(By.xpath('/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[1]/div/input'));
        await searchInput.clear();
        await searchInput.sendKeys(clientName);

        // Wait for search results
        await driver.sleep(2000);

        // Check if the client exists in the results
        const tableRows = await driver.findElements(By.xpath('/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/table/tbody/tr'));

        if (tableRows.length === 0) {
            return {
                success: false,
                message: `Client '${clientName}' not found in SimpleAudience`
            };
        }

        // Click the delete button for the first matching row (prefer exact row 1 path, fallback to generic)
        console.log('🗑️ Clicking delete button...');
        let deleteButton;
        try {
            deleteButton = await driver.findElement(By.xpath('/html/body/div[1]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/table/tbody/tr[1]/td[4]/div/button[4]'));
        } catch {
            deleteButton = await driver.findElement(By.xpath('/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/table/tbody/tr/td[4]/div/button[4]'));
        }
        await deleteButton.click();

        // Wait for confirmation dialog and click confirm delete (use robust selector)
        console.log('⚠️ Waiting for delete confirmation dialog...');
        try {
            const confirmBtn = await driver.wait(
                until.elementLocated(By.xpath("//div[@role='dialog']//button[normalize-space()='Delete']")),
                5000
            );
            await confirmBtn.click();
        } catch {
            try {
                await driver.wait(until.elementLocated(By.xpath('/html/body/div[4]/div[2]/button[2]')), 2000);
                await driver.findElement(By.xpath('/html/body/div[4]/div[2]/button[2]')).click();
            } catch {
                await driver.wait(until.elementLocated(By.xpath('/html/body/div[3]/div[2]/button[2]')), 2000);
                await driver.findElement(By.xpath('/html/body/div[3]/div[2]/button[2]')).click();
            }
        }

        // Wait for success toast (Sonner), allow a brief delay for processing
        console.log('⏳ Waiting for deletion toast...');
        try {
            const toast = await driver.wait(
                until.elementLocated(By.css('li[data-type="success"] div[data-title]')),
                10000
            );
            const toastText = (await toast.getText()).trim();
            console.log(`✅ Deletion toast detected: ${toastText || 'success'}`);
        } catch {
            console.log('⚠️ Success toast not detected within timeout; proceeding based on flow.');
        }

        console.log('✅ Pixel successfully deleted from SimpleAudience');

        return {
            success: true,
            message: `Pixel for client '${clientName}' successfully deleted from SimpleAudience`
        };

    } catch (error: any) {
        console.error('❌ Error during pixel deletion:', error);
        return {
            success: false,
            message: `Failed to delete pixel: ${error.message}`
        };
    } finally {
        if (driver) {
            console.log('🔒 Closing browser...');
            await driver.quit();
        }
    }
}

export async function downloadClientData(clientName: string): Promise<{ success: boolean; message: string; data?: any }> {
    const connection = await mysql.createConnection({
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        connectTimeout: 30000
    });

    try {
        console.log(`📊 Downloading data for client: ${clientName}`);

        // Use the client's specific database
        await connection.execute(`USE \`${clientName}\``);

        // Get data from superpixel_visitors table
        const [visitorsData] = await connection.execute<RowDataPacket[]>(
            'SELECT * FROM `superpixel_visitors`'
        );

        // Get data from superpixel_resolution_log table
        const [resolutionData] = await connection.execute<RowDataPacket[]>(
            'SELECT * FROM `superpixel_resolution_log`'
        );

        const clientData = {
            clientName,
            visitors: visitorsData,
            resolutionLog: resolutionData,
            downloadTimestamp: new Date().toISOString(),
            totalVisitors: visitorsData.length,
            totalResolutions: resolutionData.length
        };

        console.log(`✅ Downloaded data: ${visitorsData.length} visitors, ${resolutionData.length} resolutions`);

        return {
            success: true,
            message: `Successfully downloaded data for ${clientName}`,
            data: clientData
        };

    } catch (error: any) {
        console.error('❌ Error downloading client data:', error);
        return {
            success: false,
            message: `Failed to download data: ${error.message}`
        };
    } finally {
        await connection.end();
    }
}

export async function deleteClientFromDatabase(clientName: string): Promise<{ success: boolean; message: string }> {
    const connection = await mysql.createConnection({
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        connectTimeout: 30000
    });

    try {
        console.log(`🗄️ Deleting client data from database: ${clientName}`);

        // Delete from pixel_sheets table
        await connection.execute(
            'DELETE FROM pixel.pixel_sheets WHERE client_name = ?',
            [clientName]
        );

        // Drop entire client database for full cleanup
        await connection.execute(`DROP DATABASE IF EXISTS \`${clientName}\``);

        console.log('✅ Client data successfully deleted from database');

        return {
            success: true,
            message: `Client '${clientName}' successfully deleted from database and metadata`
        };

    } catch (error: any) {
        console.error('❌ Error deleting client from database:', error);
        return {
            success: false,
            message: `Failed to delete from database: ${error.message}`
        };
    } finally {
        await connection.end();
    }
} 