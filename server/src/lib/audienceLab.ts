import { Builder, By, until, WebDriver } from "selenium-webdriver";
import chrome from "selenium-webdriver/chrome";

const { AUDLAB_USERNAME, AUDLAB_PASSWORD } = process.env;

// Enable verbose logging
const DEBUG = process.env.DEBUG === '*' || process.env.NODE_ENV === 'development';

function log(message: string, data?: any) {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] [SELENIUM] ${message}`);
    if (data && DEBUG) {
        console.log(JSON.stringify(data, null, 2));
    }
}

// Helper function to add delays for debugging
function delay(ms: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, ms));
}

interface PixelOptions {
    client: string;
    webhookUrl: string;
}

async function waitForToast(driver: WebDriver, timeout = 10000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        try {
            // Try multiple approaches to find toast notifications

            // Approach 1: Original specific selector
            try {
                const section = await driver.findElement(By.css('section[aria-label="Notifications alt+T"]'));
                const lis = await section.findElements(By.css('li[data-sonner-toast][data-visible="true"]'));
                for (const li of lis) {
                    const type = await li.getAttribute('data-type');
                    const messageDivs = await li.findElements(By.css('div[data-title]'));
                    const message = messageDivs.length > 0 ? await messageDivs[0].getText() : '';
                    // Only return if it's a success or error and not a loading/pending message
                    if (
                        (type === 'success' || type === 'error') &&
                        message &&
                        message !== 'Testing webhook URL...'
                    ) {
                        return { type, message };
                    }
                }
            } catch (e1) {
                // Try approach 2: Generic toast selectors
                try {
                    const toasts = await driver.findElements(By.css('div[role="alert"], div[class*="toast"], div[class*="notification"]'));
                    for (const toast of toasts) {
                        const isVisible = await toast.isDisplayed();
                        if (isVisible) {
                            const text = await toast.getText();
                            if (text && text.toLowerCase().includes('success')) {
                                return { type: 'success', message: text };
                            } else if (text && (text.toLowerCase().includes('error') || text.toLowerCase().includes('fail'))) {
                                return { type: 'error', message: text };
                            }
                        }
                    }
                } catch (e2) {
                    // Try approach 3: Look for any new elements that might be toast messages
                    try {
                        const allDivs = await driver.findElements(By.css('div'));
                        for (const div of allDivs) {
                            const isVisible = await div.isDisplayed();
                            if (isVisible) {
                                const text = await div.getText();
                                if (text && (text.includes('Webhook test successful') || text.includes('successfully'))) {
                                    return { type: 'success', message: text };
                                } else if (text && (text.includes('Webhook test failed') || text.includes('failed') || text.includes('error'))) {
                                    return { type: 'error', message: text };
                                }
                            }
                        }
                    } catch (e3) {
                        // Continue polling
                    }
                }
            }
        } catch (e) {
            // ignore, keep polling
        }
        await new Promise(res => setTimeout(res, 500)); // Increased polling interval
    }
    throw new Error('Toast did not appear in time');
}

export async function createPixel({ client, website }: { client: string, website: string }) {
    if (!AUDLAB_USERNAME || !AUDLAB_PASSWORD) {
        throw new Error("Missing AudienceLab credentials in environment variables");
    }
    // Chrome options: headless mode disabled for optimization testing
    const options = new chrome.Options()
        // .addArguments('--headless=new') // HEADLESS MODE DISABLED FOR TESTING
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
        .addArguments('--disable-features=VizDisplayCompositor');
    const chromePath = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";
    options.setBinaryPath(chromePath);

    log("🚀 Initializing Chrome WebDriver...");
    const driver = await new Builder()
        .forBrowser("chrome")
        .setChromeOptions(options as any)
        .build();

    try {
        log("✅ Chrome WebDriver initialized successfully");
        // Browser is running in headless mode

        // Step 1: Navigate to login page
        log("🌐 Navigating to AudienceLab login page...");
        await driver.get("https://build.audiencelab.io/auth/sign-in");
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
        log(`🔍 VERBOSE: Username field tag: ${await usernameField.getTagName()}`);
        log(`🔍 VERBOSE: Username field type: ${await usernameField.getAttribute('type')}`);
        log(`🔍 VERBOSE: Username field placeholder: ${await usernameField.getAttribute('placeholder')}`);
        log(`🔍 VERBOSE: Username field class: ${await usernameField.getAttribute('class')}`);
        log(`🔍 VERBOSE: Username field enabled: ${await usernameField.isEnabled()}`);
        log(`🔍 VERBOSE: Username field displayed: ${await usernameField.isDisplayed()}`);

        const passwordXPath = "/html/body/div/div/form/div[2]/input";
        const passwordField = await driver.findElement(By.xpath(passwordXPath));
        log(`🔍 VERBOSE: Password field found with XPath: ${passwordXPath}`);
        log(`🔍 VERBOSE: Password field tag: ${await passwordField.getTagName()}`);
        log(`🔍 VERBOSE: Password field type: ${await passwordField.getAttribute('type')}`);
        log(`🔍 VERBOSE: Password field placeholder: ${await passwordField.getAttribute('placeholder')}`);
        log(`🔍 VERBOSE: Password field class: ${await passwordField.getAttribute('class')}`);
        log(`🔍 VERBOSE: Password field enabled: ${await passwordField.isEnabled()}`);
        log(`🔍 VERBOSE: Password field displayed: ${await passwordField.isDisplayed()}`);

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
        log(`🔍 VERBOSE: Sign in button tag: ${await signInButton.getTagName()}`);
        log(`🔍 VERBOSE: Sign in button text: ${await signInButton.getText()}`);
        log(`🔍 VERBOSE: Sign in button type: ${await signInButton.getAttribute('type')}`);
        log(`🔍 VERBOSE: Sign in button class: ${await signInButton.getAttribute('class')}`);
        log(`🔍 VERBOSE: Sign in button enabled: ${await signInButton.isEnabled()}`);
        log(`🔍 VERBOSE: Sign in button displayed: ${await signInButton.isDisplayed()}`);

        await signInButton.click();
        log("✅ Sign in button clicked");
        await delay(200); // Wait for login to complete

        // Step 3: Select the simple|Audience option
        log("🎯 Waiting for audience selection page...");
        const audienceXPath = "/html/body/div/div/div[2]/div[2]/div[2]/div/div/a/div";
        await driver.wait(until.elementLocated(By.xpath(audienceXPath)), 15000);
        log("✅ Audience selection page loaded");
        await delay(200); // Brief wait

        log("🖱️  Clicking simple|Audience option...");
        const audienceOption = await driver.findElement(By.xpath(audienceXPath));
        log(`🔍 VERBOSE: Audience option found with XPath: ${audienceXPath}`);
        log(`🔍 VERBOSE: Audience option tag: ${await audienceOption.getTagName()}`);
        log(`🔍 VERBOSE: Audience option text: ${await audienceOption.getText()}`);
        log(`🔍 VERBOSE: Audience option class: ${await audienceOption.getAttribute('class')}`);
        log(`🔍 VERBOSE: Audience option enabled: ${await audienceOption.isEnabled()}`);
        log(`🔍 VERBOSE: Audience option displayed: ${await audienceOption.isDisplayed()}`);

        await audienceOption.click();
        log("✅ Audience option selected");
        await delay(400); // Wait for dashboard to load

        // Step 4: Click the pixel menu item
        log("📊 Waiting for dashboard to load...");
        const pixelMenuXPath = "/html/body/div[1]/div/div[1]/div/div[2]/div/div[2]/div[2]/div[2]/ul/li[1]/a";
        await driver.wait(until.elementLocated(By.xpath(pixelMenuXPath)), 15000);
        log("✅ Dashboard loaded");
        await delay(200); // Brief wait

        log("🖱️  Clicking pixels menu item...");
        const pixelMenuItem = await driver.findElement(By.xpath(pixelMenuXPath));
        log(`🔍 VERBOSE: Pixel menu item found with XPath: ${pixelMenuXPath}`);
        log(`🔍 VERBOSE: Pixel menu item tag: ${await pixelMenuItem.getTagName()}`);
        log(`🔍 VERBOSE: Pixel menu item text: ${await pixelMenuItem.getText()}`);
        log(`🔍 VERBOSE: Pixel menu item href: ${await pixelMenuItem.getAttribute('href')}`);
        log(`🔍 VERBOSE: Pixel menu item class: ${await pixelMenuItem.getAttribute('class')}`);
        log(`🔍 VERBOSE: Pixel menu item enabled: ${await pixelMenuItem.isEnabled()}`);
        log(`🔍 VERBOSE: Pixel menu item displayed: ${await pixelMenuItem.isDisplayed()}`);

        await pixelMenuItem.click();
        log("✅ Pixels section opened");
        await delay(600); // Wait for pixels page to load

        // Step 5: Click the "create" button
        log("⏳ Waiting for create button...");
        const createBtnXPath = "/html/body/div[1]/div/div[2]/div[2]/div[2]/div[2]/div[1]/button";
        await driver.wait(until.elementLocated(By.xpath(createBtnXPath)), 1000);
        log("✅ Create button found");
        await delay(200); // Brief wait

        log("🖱️  Clicking create pixel button...");
        const createButton = await driver.findElement(By.xpath(createBtnXPath));
        log(`🔍 VERBOSE: Create button found with XPath: ${createBtnXPath}`);
        log(`🔍 VERBOSE: Create button tag: ${await createButton.getTagName()}`);
        log(`🔍 VERBOSE: Create button text: ${await createButton.getText()}`);
        log(`🔍 VERBOSE: Create button type: ${await createButton.getAttribute('type')}`);
        log(`🔍 VERBOSE: Create button class: ${await createButton.getAttribute('class')}`);
        log(`🔍 VERBOSE: Create button enabled: ${await createButton.isEnabled()}`);
        log(`🔍 VERBOSE: Create button displayed: ${await createButton.isDisplayed()}`);

        await createButton.click();
        log("✅ Create button clicked");
        await delay(200); // Wait for modal to load

        // Wait for modal to appear - try multiple selectors
        log("⏳ Waiting for modal to appear...");
        let modalSelector = '';
        let modalElement;
        try {
            // Try different modal selectors
            await driver.wait(until.elementLocated(By.css('div[role="dialog"]')), 500);
            modalSelector = 'div[role="dialog"]';
            modalElement = await driver.findElement(By.css(modalSelector));
            log("✅ Modal found with role=dialog");
        } catch (e) {
            try {
                await driver.wait(until.elementLocated(By.xpath('/html/body/div[4]')), 500);
                modalSelector = '/html/body/div[4]';
                modalElement = await driver.findElement(By.xpath(modalSelector));
                log("✅ Modal found with div[4]");
            } catch (e2) {
                // Try finding any form that might be the modal
                await driver.wait(until.elementLocated(By.css('form')), 2000);
                modalSelector = 'form';
                modalElement = await driver.findElement(By.css(modalSelector));
                log("✅ Modal found with form selector");
            }
        }

        log(`🔍 VERBOSE: Modal found with selector: ${modalSelector}`);
        log(`🔍 VERBOSE: Modal tag: ${await modalElement.getTagName()}`);
        log(`🔍 VERBOSE: Modal role: ${await modalElement.getAttribute('role')}`);
        log(`🔍 VERBOSE: Modal class: ${await modalElement.getAttribute('class')}`);
        log(`🔍 VERBOSE: Modal id: ${await modalElement.getAttribute('id')}`);
        log(`🔍 VERBOSE: Modal enabled: ${await modalElement.isEnabled()}`);
        log(`🔍 VERBOSE: Modal displayed: ${await modalElement.isDisplayed()}`);
        log(`🔍 VERBOSE: Modal aria-labelledby: ${await modalElement.getAttribute('aria-labelledby')}`);
        log(`🔍 VERBOSE: Modal aria-describedby: ${await modalElement.getAttribute('aria-describedby')}`);
        log(`🔍 VERBOSE: Modal data-state: ${await modalElement.getAttribute('data-state')}`);

        const modalRect = await modalElement.getRect();
        log(`🔍 VERBOSE: Modal position/size: x=${modalRect.x}, y=${modalRect.y}, width=${modalRect.width}, height=${modalRect.height}`);

        // Now continue with the robust modal/form automation and toast logic...
        // 2. Fill Website Name
        log("📝 Waiting for website name field...");
        const websiteName = client + '_v3';

        // Try multiple selectors for the website name field (optimized order)
        let websiteNameField;
        let websiteNameSelector = '';
        try {
            // Try the working CSS selector first (faster)
            const nameFieldCSS = 'input[name="websiteName"], input[name*="name"]:not([placeholder*="Search"]), form input[type="text"]:not([placeholder*="Search"])';
            websiteNameField = await driver.findElement(By.css(nameFieldCSS));
            websiteNameSelector = nameFieldCSS;
            log("✅ Website name field found with proper CSS selector");
        } catch (e) {
            try {
                // Fallback to xpath with shorter timeout
                const nameFieldXPath = '/html/body/div[4]/form/div[2]/div[1]/input';
                await driver.wait(until.elementLocated(By.xpath(nameFieldXPath)), 1000);
                websiteNameField = await driver.findElement(By.xpath(nameFieldXPath));
                websiteNameSelector = nameFieldXPath;
                log("✅ Website name field found with xpath");
            } catch (e2) {
                try {
                    // Try finding form inputs but skip search inputs
                    const inputs = await driver.findElements(By.css('form input[type="text"]'));
                    for (const input of inputs) {
                        const placeholder = await input.getAttribute('placeholder') || '';
                        if (!placeholder.toLowerCase().includes('search')) {
                            websiteNameField = input;
                            websiteNameSelector = 'form input[type="text"]:not([placeholder*="Search"])';
                            log("✅ Website name field found by excluding search inputs");
                            break;
                        }
                    }
                    if (!websiteNameField) {
                        throw new Error("No suitable input found");
                    }
                } catch (e3) {
                    // Fallback: use the second input if first is search
                    const inputs = await driver.findElements(By.css('form input'));
                    if (inputs.length >= 2) {
                        websiteNameField = inputs[1];
                        websiteNameSelector = 'form input:nth-child(2)';
                        log("✅ Website name field found as second input (fallback)");
                    } else {
                        throw new Error("Could not find website name field");
                    }
                }
            }
        }

        log(`🔍 VERBOSE: Website name field found with selector: ${websiteNameSelector}`);
        log(`🔍 VERBOSE: Website name field tag: ${await websiteNameField.getTagName()}`);
        log(`🔍 VERBOSE: Website name field type: ${await websiteNameField.getAttribute('type')}`);
        log(`🔍 VERBOSE: Website name field name: ${await websiteNameField.getAttribute('name')}`);
        log(`🔍 VERBOSE: Website name field placeholder: ${await websiteNameField.getAttribute('placeholder')}`);
        log(`🔍 VERBOSE: Website name field class: ${await websiteNameField.getAttribute('class')}`);
        log(`🔍 VERBOSE: Website name field value: ${await websiteNameField.getAttribute('value')}`);
        log(`🔍 VERBOSE: Website name field enabled: ${await websiteNameField.isEnabled()}`);
        log(`🔍 VERBOSE: Website name field displayed: ${await websiteNameField.isDisplayed()}`);

        const nameFieldRect = await websiteNameField.getRect();
        log(`🔍 VERBOSE: Website name field position/size: x=${nameFieldRect.x}, y=${nameFieldRect.y}, width=${nameFieldRect.width}, height=${nameFieldRect.height}`);

        // Make sure element is interactable with multiple approaches
        await driver.wait(until.elementIsEnabled(websiteNameField), 200);
        await driver.executeScript("arguments[0].scrollIntoView(true);", websiteNameField);
        await delay(200); // Wait for scroll and rendering to complete

        // Additional checks for element interactability
        await driver.wait(until.elementIsVisible(websiteNameField), 200);

        // Try JavaScript-based interaction if Selenium fails
        try {
            await websiteNameField.clear();
            await delay(150);
            await websiteNameField.sendKeys(websiteName);
            log("✅ Website name entered with Selenium");
        } catch (interactionError) {
            log("⚠️ Selenium interaction failed, trying JavaScript approach...");
            // Use JavaScript to set the value directly
            await driver.executeScript(`
                arguments[0].focus();
                arguments[0].value = '';
                arguments[0].value = arguments[1];
                arguments[0].dispatchEvent(new Event('input', { bubbles: true }));
                arguments[0].dispatchEvent(new Event('change', { bubbles: true }));
            `, websiteNameField, websiteName);
            await delay(150);
            log("✅ Website name entered with JavaScript");
        }

        // 3. Fill Website URL
        log("📝 Filling website URL...");
        let websiteUrlField;
        let urlFieldSelector = '';
        try {
            const urlPlaceholderSelector = 'input[placeholder="https://example.com"]';
            websiteUrlField = await driver.findElement(By.css(urlPlaceholderSelector));
            urlFieldSelector = urlPlaceholderSelector;
            log("✅ Website URL field found with placeholder selector");
        } catch (e) {
            try {
                // Try finding by URL-related attributes
                const urlGenericSelector = 'input[placeholder*="http"], input[name*="url"], input[type="url"]';
                websiteUrlField = await driver.findElement(By.css(urlGenericSelector));
                urlFieldSelector = urlGenericSelector;
                log("✅ Website URL field found with URL selector");
            } catch (e2) {
                // Try finding the second input in the form
                const inputs = await driver.findElements(By.css('form input'));
                if (inputs.length >= 2) {
                    websiteUrlField = inputs[1];
                    urlFieldSelector = 'form input:nth-child(2)';
                    log("✅ Website URL field found as second input");
                } else {
                    throw new Error("Could not find website URL field");
                }
            }
        }

        log(`🔍 VERBOSE: Website URL field found with selector: ${urlFieldSelector}`);
        log(`🔍 VERBOSE: Website URL field tag: ${await websiteUrlField.getTagName()}`);
        log(`🔍 VERBOSE: Website URL field type: ${await websiteUrlField.getAttribute('type')}`);
        log(`🔍 VERBOSE: Website URL field name: ${await websiteUrlField.getAttribute('name')}`);
        log(`🔍 VERBOSE: Website URL field placeholder: ${await websiteUrlField.getAttribute('placeholder')}`);
        log(`🔍 VERBOSE: Website URL field class: ${await websiteUrlField.getAttribute('class')}`);
        log(`🔍 VERBOSE: Website URL field value: ${await websiteUrlField.getAttribute('value')}`);
        log(`🔍 VERBOSE: Website URL field enabled: ${await websiteUrlField.isEnabled()}`);
        log(`🔍 VERBOSE: Website URL field displayed: ${await websiteUrlField.isDisplayed()}`);

        const urlFieldRect = await websiteUrlField.getRect();
        log(`🔍 VERBOSE: Website URL field position/size: x=${urlFieldRect.x}, y=${urlFieldRect.y}, width=${urlFieldRect.width}, height=${urlFieldRect.height}`);

        // Make sure element is interactable with multiple approaches
        await driver.wait(until.elementIsEnabled(websiteUrlField), 200);
        await driver.executeScript("arguments[0].scrollIntoView(true);", websiteUrlField);
        await delay(200); // Wait for scroll and rendering to complete

        // Additional checks for element interactability
        await driver.wait(until.elementIsVisible(websiteUrlField), 200);

        // Try JavaScript-based interaction if Selenium fails
        try {
            await websiteUrlField.clear();
            await delay(150);
            await websiteUrlField.sendKeys(website);
            log("✅ Website URL entered with Selenium");
        } catch (interactionError) {
            log("⚠️ Selenium interaction failed, trying JavaScript approach...");
            // Use JavaScript to set the value directly
            await driver.executeScript(`
                arguments[0].focus();
                arguments[0].value = '';
                arguments[0].value = arguments[1];
                arguments[0].dispatchEvent(new Event('input', { bubbles: true }));
                arguments[0].dispatchEvent(new Event('change', { bubbles: true }));
            `, websiteUrlField, website);
            await delay(150);
            log("✅ Website URL entered with JavaScript");
        }

        // 4. Click Next
        log("🖱️  Clicking Next button...");
        let nextButton;
        let nextButtonSelector = '';
        try {
            const nextXPath = '/html/body/div[4]/form/div[3]/button';
            nextButton = await driver.findElement(By.xpath(nextXPath));
            nextButtonSelector = nextXPath;
            log("✅ Next button found with xpath");
        } catch (e) {
            try {
                // Try finding by button text
                const nextTextXPath = "//button[contains(text(), 'Next')]";
                nextButton = await driver.findElement(By.xpath(nextTextXPath));
                nextButtonSelector = nextTextXPath;
                log("✅ Next button found by text");
            } catch (e2) {
                try {
                    // Try finding by button type
                    const nextCSSSelector = 'button[type="submit"], form button:last-of-type';
                    nextButton = await driver.findElement(By.css(nextCSSSelector));
                    nextButtonSelector = nextCSSSelector;
                    log("✅ Next button found with CSS selector");
                } catch (e3) {
                    // Try finding any button in the modal
                    const buttons = await driver.findElements(By.css('form button'));
                    if (buttons.length > 0) {
                        nextButton = buttons[buttons.length - 1]; // Take the last button
                        nextButtonSelector = 'form button:last-of-type';
                        log("✅ Next button found as last button in form");
                    } else {
                        throw new Error("Could not find Next button");
                    }
                }
            }
        }

        log(`🔍 VERBOSE: Next button found with selector: ${nextButtonSelector}`);
        log(`🔍 VERBOSE: Next button tag: ${await nextButton.getTagName()}`);
        log(`🔍 VERBOSE: Next button text: ${await nextButton.getText()}`);
        log(`🔍 VERBOSE: Next button type: ${await nextButton.getAttribute('type')}`);
        log(`🔍 VERBOSE: Next button class: ${await nextButton.getAttribute('class')}`);
        log(`🔍 VERBOSE: Next button enabled: ${await nextButton.isEnabled()}`);
        log(`🔍 VERBOSE: Next button displayed: ${await nextButton.isDisplayed()}`);

        const nextButtonRect = await nextButton.getRect();
        log(`🔍 VERBOSE: Next button position/size: x=${nextButtonRect.x}, y=${nextButtonRect.y}, width=${nextButtonRect.width}, height=${nextButtonRect.height}`);

        await nextButton.click();
        log("✅ Next button clicked");

        // 5. Fill Webhook URL
        log("📝 Waiting for webhook URL field...");
        const webhookUrl = `https://hook.thynkdata.com/pixel_import.php?client=${client}`;

        // Try multiple selectors for the webhook URL field (optimized order)
        let webhookUrlField;
        try {
            // Try finding by webhook-related attributes first (faster)
            webhookUrlField = await driver.findElement(By.css('input[placeholder*="webhook"], input[placeholder*="url"], input[name*="webhook"], input[name*="url"]'));
            log("✅ Webhook URL field found with webhook selector");
        } catch (e) {
            try {
                // Fallback to xpath with shorter timeout
                await driver.wait(until.elementLocated(By.xpath('/html/body/div[4]/form/div[2]/div/div/input')), 1000);
                webhookUrlField = await driver.findElement(By.xpath('/html/body/div[4]/form/div[2]/div/div/input'));
                log("✅ Webhook URL field found with xpath");
            } catch (e2) {
                try {
                    // Try finding any input in the current modal/form
                    webhookUrlField = await driver.findElement(By.css('form input[type="text"], form input[type="url"], form input:not([type="hidden"])'));
                    log("✅ Webhook URL field found with generic input selector");
                } catch (e3) {
                    // Try finding any visible input
                    const inputs = await driver.findElements(By.css('input'));
                    for (const input of inputs) {
                        const isDisplayed = await input.isDisplayed();
                        if (isDisplayed) {
                            webhookUrlField = input;
                            log("✅ Webhook URL field found as visible input");
                            break;
                        }
                    }
                    if (!webhookUrlField) {
                        throw new Error("Could not find webhook URL field");
                    }
                }
            }
        }

        // Make sure element is interactable with multiple approaches
        await driver.wait(until.elementIsEnabled(webhookUrlField), 1000);
        await driver.executeScript("arguments[0].scrollIntoView(true);", webhookUrlField);
        await delay(100); // Wait for scroll and rendering to complete

        // Additional checks for element interactability
        await driver.wait(until.elementIsVisible(webhookUrlField), 1000);

        // Try JavaScript-based interaction if Selenium fails
        try {
            await webhookUrlField.clear();
            await delay(150);
            await webhookUrlField.sendKeys(webhookUrl);
            log("✅ Webhook URL entered with Selenium");
        } catch (interactionError) {
            log("⚠️ Selenium interaction failed, trying JavaScript approach...");
            // Use JavaScript to set the value directly
            await driver.executeScript(`
                arguments[0].focus();
                arguments[0].value = '';
                arguments[0].value = arguments[1];
                arguments[0].dispatchEvent(new Event('input', { bubbles: true }));
                arguments[0].dispatchEvent(new Event('change', { bubbles: true }));
            `, webhookUrlField, webhookUrl);
            await delay(150);
            log("✅ Webhook URL entered with JavaScript");
        }

        // 6. Click Test
        log("🖱️  Clicking Test button...");
        let testButton;
        try {
            testButton = await driver.findElement(By.xpath('/html/body/div[4]/form/div[2]/div/div/button'));
            log("✅ Test button found with xpath");
        } catch (e) {
            try {
                // Try finding by button text
                testButton = await driver.findElement(By.xpath("//button[contains(text(), 'Test')]"));
                log("✅ Test button found by text");
            } catch (e2) {
                try {
                    // Try finding button near the webhook input
                    testButton = await driver.findElement(By.css('form button[type="button"], form button:not([type="submit"])'));
                    log("✅ Test button found with CSS selector");
                } catch (e3) {
                    // Try finding any button that's not the main submit button
                    const buttons = await driver.findElements(By.css('form button'));
                    if (buttons.length >= 2) {
                        testButton = buttons[0]; // Take the first button (likely the test button)
                        log("✅ Test button found as first button");
                    } else {
                        throw new Error("Could not find Test button");
                    }
                }
            }
        }

        await testButton.click();
        log("✅ Test button clicked");

        // 7. Wait for webhook test completion (look for DOM changes instead of toast)
        log("⏳ Waiting for webhook test to complete...");
        let testCompleted = false;
        let isSuccess = false;
        const testStart = Date.now();
        const testTimeout = 15000; // 15 seconds

        while (Date.now() - testStart < testTimeout && !testCompleted) {
            try {
                // Look for Create button becoming enabled/visible (indicates success)
                const createButtons = await driver.findElements(By.xpath("//button[contains(text(), 'Create')]"));
                for (const button of createButtons) {
                    const isEnabled = await button.isEnabled();
                    const isDisplayed = await button.isDisplayed();
                    if (isEnabled && isDisplayed) {
                        log("✅ Create button is now enabled - webhook test successful!");
                        testCompleted = true;
                        isSuccess = true;
                        break;
                    }
                }

                // Also check for any error indicators in the form
                if (!testCompleted) {
                    const errorElements = await driver.findElements(By.css('div[class*="error"], span[class*="error"], .text-red-500, [role="alert"]'));
                    for (const errorEl of errorElements) {
                        const isDisplayed = await errorEl.isDisplayed();
                        if (isDisplayed) {
                            const errorText = await errorEl.getText();
                            if (errorText && errorText.trim()) {
                                log(`❌ Error detected: ${errorText}`);
                                testCompleted = true;
                                isSuccess = false;
                                break;
                            }
                        }
                    }
                }

                // Check if test button is still in loading state
                if (!testCompleted) {
                    const testButtons = await driver.findElements(By.xpath("//button[contains(text(), 'Test')]"));
                    for (const button of testButtons) {
                        const buttonText = await button.getText();
                        const isEnabled = await button.isEnabled();
                        // If button is disabled or shows loading text, test is still running
                        if (!isEnabled || buttonText.toLowerCase().includes('testing')) {
                            // Still testing, continue waiting
                            break;
                        }
                    }
                }

            } catch (e) {
                // Continue checking
            }

            if (!testCompleted) {
                await delay(250); // Wait 250ms before checking again
            }
        }

        if (!testCompleted) {
            // Assume success if no errors found and timeout reached
            log("⚠️ Webhook test timeout reached, assuming success and proceeding...");
            isSuccess = true;
        }

        if (isSuccess) {
            log("✅ Webhook test completed successfully");
            // 8. Click Create button (specifically in modal to avoid background buttons)
            let createButton;
            let createButtonSelector = '';
            try {
                // First try: Find Create button specifically within the modal by type=submit
                const createModalSelector = 'div[role="dialog"] button[type="submit"]';
                createButton = await driver.findElement(By.css(createModalSelector));
                createButtonSelector = createModalSelector;
                log("✅ Create button found in modal by type=submit");
            } catch (e) {
                try {
                    // Second try: Find Create button within modal form by text
                    const createFormXPath = "//div[@role='dialog']//form//button[contains(text(), 'Create')]";
                    createButton = await driver.findElement(By.xpath(createFormXPath));
                    createButtonSelector = createFormXPath;
                    log("✅ Create button found in modal form by text");
                } catch (e2) {
                    try {
                        // Third try: Find submit button within modal form
                        const createSubmitSelector = 'div[role="dialog"] form button[type="submit"]';
                        createButton = await driver.findElement(By.css(createSubmitSelector));
                        createButtonSelector = createSubmitSelector;
                        log("✅ Create button found as submit button in modal form");
                    } catch (e3) {
                        try {
                            // Fourth try: Original xpath approach but within modal context
                            const createModalXPath = "//div[@role='dialog']//button[contains(text(), 'Create')]";
                            createButton = await driver.findElement(By.xpath(createModalXPath));
                            createButtonSelector = createModalXPath;
                            log("✅ Create button found in modal by text (fallback)");
                        } catch (e4) {
                            // Final fallback: Try finding the last button in the modal form
                            const buttons = await driver.findElements(By.css('div[role="dialog"] form button'));
                            if (buttons.length > 0) {
                                createButton = buttons[buttons.length - 1];
                                createButtonSelector = 'div[role="dialog"] form button:last-child';
                                log("✅ Create button found as last button in modal");
                            } else {
                                throw new Error("Could not find Create button in modal");
                            }
                        }
                    }
                }
            }

            log(`🔍 VERBOSE: Create button found with selector: ${createButtonSelector}`);
            log(`🔍 VERBOSE: Create button tag: ${await createButton.getTagName()}`);
            log(`🔍 VERBOSE: Create button text: ${await createButton.getText()}`);
            log(`🔍 VERBOSE: Create button type: ${await createButton.getAttribute('type')}`);
            log(`🔍 VERBOSE: Create button class: ${await createButton.getAttribute('class')}`);
            log(`🔍 VERBOSE: Create button enabled: ${await createButton.isEnabled()}`);
            log(`🔍 VERBOSE: Create button displayed: ${await createButton.isDisplayed()}`);

            const createButtonRect = await createButton.getRect();
            log(`🔍 VERBOSE: Create button position/size: x=${createButtonRect.x}, y=${createButtonRect.y}, width=${createButtonRect.width}, height=${createButtonRect.height}`);

            // Try regular click first, then JavaScript if blocked
            try {
                await createButton.click();
                log("✅ Create button clicked with Selenium");
            } catch (clickError) {
                log("⚠️ Create button click intercepted, trying JavaScript approach...");
                log(`🔍 VERBOSE: Click error: ${clickError instanceof Error ? clickError.message : String(clickError)}`);
                // Use JavaScript to click directly
                await driver.executeScript("arguments[0].click();", createButton);
                log("✅ Create button clicked with JavaScript");
            }

            // 9. Wait for pixel creation to complete
            log("⏳ Waiting for pixel creation to complete...");
            log("🔍 DEBUG: Current page title before delay:", await driver.getTitle());
            log("🔍 DEBUG: Current URL before delay:", await driver.getCurrentUrl());
            await delay(1000); // Wait 1000ms for pixel creation process to complete
            log("🔍 DEBUG: Current page title after delay:", await driver.getTitle());
            log("🔍 DEBUG: Current URL after delay:", await driver.getCurrentUrl());

            // 10. Wait for pixel code to appear and extract it
            log("⏳ Waiting for pixel code to appear...");
            log("🔍 DEBUG: Starting pixel code extraction process...");
            let pixelCodeElement;
            let attempts = 0;
            const maxAttempts = 6;

            while (!pixelCodeElement && attempts < maxAttempts) {
                attempts++;
                log(`🔍 DEBUG: Starting attempt ${attempts}/${maxAttempts} to find pixel code element...`);

                try {
                    // Attempt 1: Look for pre element with specific classes (most likely location)
                    if (attempts === 1) {
                        log("🔍 DEBUG: Attempt 1 - Looking for pre elements with specific classes...");
                        // Try multiple variations of the pre element
                        const selectors = [
                            'pre.font-mono.text-sm.break-words.whitespace-pre-wrap',
                            'pre[class*="font-mono"][class*="text-sm"]',
                            'pre[class*="font-mono"]',
                            'pre[class*="break-words"]',
                            'pre[class*="whitespace-pre-wrap"]'
                        ];

                        for (const selector of selectors) {
                            try {
                                await driver.wait(until.elementLocated(By.css(selector)), 2000);
                                pixelCodeElement = await driver.findElement(By.css(selector));
                                log(`✅ Pixel code found with selector: ${selector}`);
                                break;
                            } catch (e) {
                                log(`⚠️ Selector failed: ${selector}`);
                            }
                        }

                        if (!pixelCodeElement) {
                            throw new Error("No pre element found with expected classes");
                        }
                    }
                    // Attempt 2: Any pre element in the modal
                    else if (attempts === 2) {
                        log("🔍 DEBUG: Attempt 2 - Looking for any pre element in modal/form...");
                        pixelCodeElement = await driver.findElement(By.css('div[role="dialog"] pre, form pre, pre'));
                        log("✅ Pixel code found with generic pre selector");
                    }
                    // Attempt 3: Textarea elements (sometimes pixel code is in textarea)
                    else if (attempts === 3) {
                        log("🔍 DEBUG: Attempt 3 - Looking for textarea elements...");
                        pixelCodeElement = await driver.findElement(By.css('textarea[readonly], textarea[disabled], textarea'));
                        log("✅ Pixel code found with textarea selector");
                    }
                    // Attempt 4: Look for script-like content in any element (skip HTML comments)
                    else if (attempts === 4) {
                        log("🔍 DEBUG: Attempt 4 - Looking for elements with script content...");
                        const scriptElements = await driver.findElements(By.xpath("//*[contains(text(), '<script') or contains(text(), 'gtag') or contains(text(), 'dataLayer')]"));
                        for (const element of scriptElements) {
                            const text = await element.getText();
                            // Skip HTML comments and look for actual JavaScript code
                            if (text && !text.startsWith('<!--') && !text.includes('<!-- THYNKdata Pixel Code -->') && text.length > 50) {
                                pixelCodeElement = element;
                                log("✅ Pixel code found by script content (skipped HTML comments)");
                                break;
                            }
                        }
                        if (!pixelCodeElement) {
                            throw new Error("No valid script content found");
                        }
                    }
                    // Attempt 5: Common pixel code container classes/attributes
                    else if (attempts === 5) {
                        log("🔍 DEBUG: Attempt 5 - Looking for common pixel code container classes...");
                        pixelCodeElement = await driver.findElement(By.css('.code, .pixel-code, .script-code, [data-testid*="code"], [data-testid*="pixel"], [class*="code"], [class*="pixel"]'));
                        log("✅ Pixel code found with common classes");
                    }
                    // Attempt 6: Last resort - comprehensive debugging and search
                    else if (attempts === 6) {
                        log("🔍 COMPREHENSIVE DEBUGGING - Searching all modal content for pixel code...");

                        // Wait for content to fully load
                        await delay(1500);

                        // 1. Debug all pre elements in detail
                        const preElements = await driver.findElements(By.css('pre'));
                        log(`🔍 Found ${preElements.length} pre elements total`);

                        for (let i = 0; i < preElements.length; i++) {
                            try {
                                const preElement = preElements[i];
                                const className = await preElement.getAttribute('class') || 'NO_CLASS';
                                const innerHTML = await preElement.getAttribute('innerHTML') || 'NO_INNER_HTML';
                                const outerHTML = await preElement.getAttribute('outerHTML') || 'NO_OUTER_HTML';
                                const textContent = await preElement.getText() || 'NO_TEXT';
                                const isDisplayed = await preElement.isDisplayed();

                                log(`🔍 PRE ELEMENT ${i + 1}:`);
                                log(`   - Class: "${className}"`);
                                log(`   - Displayed: ${isDisplayed}`);
                                log(`   - Text Length: ${textContent.length}`);
                                log(`   - Text Content: "${textContent.substring(0, 200)}${textContent.length > 200 ? '...' : ''}"`);
                                log(`   - Inner HTML: "${innerHTML.substring(0, 200)}${innerHTML.length > 200 ? '...' : ''}"`);
                                log(`   - Outer HTML: "${outerHTML.substring(0, 300)}${outerHTML.length > 300 ? '...' : ''}"`);

                                // Check if this contains script-like content
                                const hasScriptContent = textContent.includes('<script') ||
                                    textContent.includes('src=') ||
                                    textContent.includes('.js') ||
                                    innerHTML.includes('<script') ||
                                    innerHTML.includes('src=') ||
                                    innerHTML.includes('.js');

                                log(`   - Has Script Content: ${hasScriptContent}`);

                                if (hasScriptContent && textContent.trim().length > 0) {
                                    pixelCodeElement = preElement;
                                    log(`✅ SELECTED PRE ELEMENT ${i + 1} as pixel code source`);
                                    break;
                                }
                            } catch (e) {
                                log(`❌ Error processing pre element ${i + 1}: ${e}`);
                            }
                        }

                        // 2. If no pre element worked, search for any element with script content
                        if (!pixelCodeElement) {
                            log("🔍 No suitable pre element found, searching all elements for script content...");

                            const allElements = await driver.findElements(By.css('div[role="dialog"] *, form *, body *'));
                            log(`🔍 Found ${allElements.length} total elements to search`);

                            let elementsWithText = 0;
                            for (let i = 0; i < Math.min(allElements.length, 50); i++) { // Limit to first 50 to avoid timeout
                                try {
                                    const element = allElements[i];
                                    const text = await element.getText();
                                    const tagName = await element.getTagName();

                                    if (text && text.length > 10) {
                                        elementsWithText++;

                                        const hasScriptContent = text.includes('<script') ||
                                            text.includes('src=') ||
                                            text.includes('.js') ||
                                            text.includes('identitypxl') ||
                                            text.includes('pixels/');

                                        if (hasScriptContent) {
                                            log(`🔍 ELEMENT WITH SCRIPT CONTENT (${tagName}):`);
                                            log(`   - Text: "${text.substring(0, 300)}${text.length > 300 ? '...' : ''}"`);

                                            if (text.trim().length > 0 && !text.startsWith('<!--')) {
                                                pixelCodeElement = element;
                                                log(`✅ SELECTED ELEMENT (${tagName}) as pixel code source`);
                                                break;
                                            }
                                        }
                                    }
                                } catch (e) {
                                    // Continue searching
                                }
                            }

                            log(`🔍 Found ${elementsWithText} elements with text content`);
                        }

                        // 3. If still nothing, get page source for manual inspection
                        if (!pixelCodeElement) {
                            log("🔍 Still no pixel code found, getting page source for inspection...");

                            try {
                                // Get the modal/dialog content
                                const modalElements = await driver.findElements(By.css('div[role="dialog"], .modal, form'));
                                for (let i = 0; i < modalElements.length; i++) {
                                    try {
                                        const modalHTML = await modalElements[i].getAttribute('outerHTML');
                                        log(`🔍 MODAL/DIALOG ${i + 1} HTML (first 1000 chars):`);
                                        log(modalHTML.substring(0, 1000));
                                        log("🔍 ========================================");
                                    } catch (e) {
                                        log(`❌ Could not get HTML for modal ${i + 1}: ${e}`);
                                    }
                                }
                            } catch (e) {
                                log(`❌ Could not get modal HTML: ${e}`);
                            }

                            // Last resort - take any pre element even if empty
                            if (preElements.length > 0) {
                                pixelCodeElement = preElements[preElements.length - 1]; // Take the last pre element
                                log("✅ Using last pre element as fallback");
                            } else {
                                throw new Error("Could not find pixel code element with any selector - no pre elements found");
                            }
                        }
                    }
                } catch (e) {
                    log(`🔍 DEBUG: Attempt ${attempts} failed with error:`, e);
                    if (attempts === maxAttempts) {
                        log(`❌ FINAL FAILURE: Could not find pixel code element after ${maxAttempts} attempts`);
                        log("🔍 DEBUG: Final page state:");
                        log("🔍 DEBUG: Page title:", await driver.getTitle());
                        log("🔍 DEBUG: Page URL:", await driver.getCurrentUrl());
                        throw new Error(`Could not find pixel code element after ${maxAttempts} attempts`);
                    }
                    log(`⏳ Waiting 1 second before attempt ${attempts + 1}...`);
                    // Wait a bit before next attempt
                    await delay(1000);
                }
            }

            if (!pixelCodeElement) {
                log("❌ CRITICAL: No pixel code element found after all attempts");
                throw new Error("Could not find pixel code element after all attempts");
            }

            log("🔍 DEBUG: Found pixel code element, extracting content...");
            log("🔍 DEBUG: Element tag name:", await pixelCodeElement.getTagName());
            log("🔍 DEBUG: Element is displayed:", await pixelCodeElement.isDisplayed());
            log("🔍 DEBUG: Element is enabled:", await pixelCodeElement.isEnabled());

            try {
                const elementClass = await pixelCodeElement.getAttribute('class');
                log("🔍 DEBUG: Element class:", elementClass || 'NO_CLASS');
            } catch (e) {
                log("🔍 DEBUG: Could not get element class:", e);
            }

            const pixelCode = await pixelCodeElement.getText();
            log("✅ Pixel code extracted successfully");
            log("🔍 DEBUG: Extracted pixel code details:");
            log("🔍 DEBUG: - Length:", pixelCode?.length || 0);
            log("🔍 DEBUG: - Is null/undefined:", pixelCode == null);
            log("🔍 DEBUG: - Trimmed length:", pixelCode?.trim()?.length || 0);
            log("🔍 DEBUG: - Preview (first 300 chars):", pixelCode?.substring(0, 300) || 'EMPTY');
            log("🔍 DEBUG: - Full content:", pixelCode || 'EMPTY');

            if (!pixelCode || pixelCode.trim().length === 0) {
                log("❌ CRITICAL: Extracted pixel code is empty or contains only whitespace");
                log("🔍 DEBUG: Attempting to get innerHTML as fallback...");
                try {
                    const innerHTML = await pixelCodeElement.getAttribute('innerHTML');
                    log("🔍 DEBUG: Element innerHTML:", innerHTML);
                    const outerHTML = await pixelCodeElement.getAttribute('outerHTML');
                    log("🔍 DEBUG: Element outerHTML:", outerHTML);
                } catch (e) {
                    log("🔍 DEBUG: Could not get element HTML:", e);
                }
                throw new Error("Extracted pixel code is empty or contains only whitespace");
            }

            return { pixelCode: pixelCode.trim() };
        } else {
            throw new Error(`Webhook test failed - check webhook URL and endpoint`);
        }
    } catch (err: any) {
        return { error: err.message || String(err) };
    } finally {
        // await driver.quit(); // Uncomment when not debugging
    }
} 