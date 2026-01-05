#!/bin/bash

# Script to fix the selenium-webdriver import issue on Google Cloud VM
# Run this on your local machine to update the VM

echo "🔧 Fixing selenium-webdriver import on Google Cloud VM..."

# SSH into the VM and fix the import
ssh scole@pixel-php << 'EOF'
    # Navigate to the project directory
    cd /opt/auto-pixel/server/src/lib/

    # Fix the import statement
    sed -i 's|import chrome from "selenium-webdriver/chrome";|import { Options as ChromeOptions } from "selenium-webdriver/chrome.js";|g' audienceLab.ts

    # Fix the usage of chrome.Options()
    sed -i 's|new chrome.Options()|new ChromeOptions()|g' audienceLab.ts

    # Remove the hardcoded Chrome path for Linux deployment
    sed -i 's|const chromePath = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";|// Chrome will be auto-detected on Linux|g' audienceLab.ts
    sed -i 's|options.setBinaryPath(chromePath);|// options.setBinaryPath(chromePath); // Not needed on Linux|g' audienceLab.ts

    echo "✅ Fixed import statements"

    # Rebuild the project
    cd /opt/auto-pixel/server
    npm run build

    echo "✅ Project rebuilt"

    # Create a simple environment file if it doesn't exist
    if [ ! -f /opt/auto-pixel/server/.env ]; then
        cat > /opt/auto-pixel/server/.env << 'ENVEOF'
# Database Configuration
DB_HOST=34.26.61.148
DB_USER=root
DB_PASS=AccuPoint01!
TEMPLATE_DB=template
TEMPLATE_TABLE=superpixel_resolution_log

# AudienceLab Credentials - UPDATE THESE
AUDLAB_USERNAME=shaw@accupointsolutions.com
AUDLAB_PASSWORD=AccuPoint01!

# Server Configuration
PORT=4000
NODE_ENV=production
ENVEOF
        echo "✅ Created environment file"
    fi

    # Try to start the application
    echo "🚀 Starting the application..."
    cd /opt/auto-pixel/server
    npm run start

EOF

echo "🎉 VM update complete!" 