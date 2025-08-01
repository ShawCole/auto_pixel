const fs = require('fs');
const path = require('path');

console.log('🔧 Running VM-specific fixes for selenium-webdriver...');

// Check if we're running on a Linux VM (not macOS/Windows dev environment)
const isVM = process.platform === 'linux';

if (!isVM) {
    console.log('🏠 Running on development machine - skipping VM fixes');
    process.exit(0);
}

console.log('🐧 Detected VM environment - applying selenium-webdriver fixes...');

const distDir = path.join(__dirname, '..', 'dist');

// Files that need selenium-webdriver fixes
const filesToFix = [
    path.join(distDir, 'lib', 'audienceLab.js'),
    path.join(distDir, 'lib', 'pixelDeleter.js'),
    path.join(distDir, 'lib', 'websiteUrlUpdater.js')
];

let totalFixed = 0;

filesToFix.forEach(filePath => {
    if (!fs.existsSync(filePath)) {
        console.log(`⚠️  ${path.basename(filePath)} not found - skipping`);
        return;
    }

    try {
        console.log(`🔍 Processing ${path.basename(filePath)}...`);

        // Read the compiled JavaScript file
        let content = fs.readFileSync(filePath, 'utf8');
        let modified = false;

        // Fix 1: Add .js extension to selenium-webdriver/chrome import
        const chromeImportRegex = /from ['"]selenium-webdriver\/chrome['"]/g;
        if (chromeImportRegex.test(content)) {
            content = content.replace(chromeImportRegex, 'from "selenium-webdriver/chrome.js"');
            console.log(`  ✅ Fixed chrome import in ${path.basename(filePath)}`);
            modified = true;
        }

        // Fix 2: Comment out hardcoded macOS Chrome path (if present)
        const chromePathRegex = /const chromePath = "\/Applications\/Google Chrome\.app\/Contents\/MacOS\/Google Chrome";/g;
        if (chromePathRegex.test(content)) {
            content = content.replace(chromePathRegex, '// const chromePath = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"; // Not needed on Linux');
            console.log(`  ✅ Commented out hardcoded Chrome path in ${path.basename(filePath)}`);
            modified = true;
        }

        // Fix 3: Comment out setBinaryPath for macOS Chrome
        const setBinaryRegex = /options\.setBinaryPath\(chromePath\);/g;
        if (setBinaryRegex.test(content)) {
            content = content.replace(setBinaryRegex, '// options.setBinaryPath(chromePath); // Not needed on Linux');
            console.log(`  ✅ Commented out setBinaryPath in ${path.basename(filePath)}`);
            modified = true;
        }

        if (modified) {
            // Write the fixed content back
            fs.writeFileSync(filePath, content, 'utf8');
            console.log(`  🎉 Fixed ${path.basename(filePath)}`);
            totalFixed++;
        } else {
            console.log(`  ℹ️  No fixes needed for ${path.basename(filePath)}`);
        }

    } catch (error) {
        console.error(`❌ Error processing ${path.basename(filePath)}:`, error.message);
    }
});

if (totalFixed > 0) {
    console.log(`\n🎉 VM fixes applied successfully to ${totalFixed} file(s)!`);
    console.log('📝 Summary of fixes:');
    console.log('   - Added .js extension to selenium-webdriver/chrome imports');
    console.log('   - Commented out macOS-specific Chrome paths');
} else {
    console.log('\nℹ️  No files needed fixing - they may already be compatible');
} 
