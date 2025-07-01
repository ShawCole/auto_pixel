import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Check if we're running on a Linux VM (not macOS/Windows dev environment)
const isVM = process.platform === 'linux';

if (!isVM) {
    console.log('🏠 Running on development machine - skipping VM fixes');
    process.exit(0);
}

console.log('🔧 Detected VM environment - applying selenium-webdriver fixes...');

const distDir = path.join(__dirname, '..', 'dist');
const audienceLabPath = path.join(distDir, 'lib', 'audienceLab.js');

// Check if the compiled file exists
if (!fs.existsSync(audienceLabPath)) {
    console.log('❌ audienceLab.js not found in dist/lib/ - build may have failed');
    process.exit(1);
}

try {
    // Read the compiled JavaScript file
    let content = fs.readFileSync(audienceLabPath, 'utf8');

    // Apply the fixes
    let modified = false;

    // Fix 1: Update the import statement
    const oldImport = 'import chrome from "selenium-webdriver/chrome";';
    const newImport = 'import { Options as ChromeOptions } from "selenium-webdriver/chrome.js";';

    if (content.includes(oldImport)) {
        content = content.replace(oldImport, newImport);
        console.log('✅ Fixed selenium-webdriver import');
        modified = true;
    }

    // Fix 2: Update chrome.Options() usage
    const oldUsage = /new chrome\.Options\(\)/g;
    const newUsage = 'new ChromeOptions()';

    if (oldUsage.test(content)) {
        content = content.replace(oldUsage, newUsage);
        console.log('✅ Fixed ChromeOptions usage');
        modified = true;
    }

    // Fix 3: Comment out hardcoded Chrome path (if present)
    const chromePathRegex = /const chromePath = "\/Applications\/Google Chrome\.app\/Contents\/MacOS\/Google Chrome";/g;
    const chromeBinaryRegex = /options\.setBinaryPath\(chromePath\);/g;

    if (chromePathRegex.test(content)) {
        content = content.replace(chromePathRegex, '// const chromePath = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"; // Not needed on Linux');
        console.log('✅ Commented out hardcoded Chrome path');
        modified = true;
    }

    if (chromeBinaryRegex.test(content)) {
        content = content.replace(chromeBinaryRegex, '// options.setBinaryPath(chromePath); // Not needed on Linux');
        console.log('✅ Commented out setBinaryPath');
        modified = true;
    }

    if (modified) {
        // Write the fixed content back
        fs.writeFileSync(audienceLabPath, content, 'utf8');
        console.log('🎉 VM fixes applied successfully!');
    } else {
        console.log('ℹ️  No fixes needed - code already compatible');
    }

} catch (error) {
    console.error('❌ Error applying VM fixes:', error.message);
    process.exit(1);
} 