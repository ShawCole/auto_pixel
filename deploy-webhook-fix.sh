#!/bin/bash

# Deploy webhook fix to the server
# This script uploads the updated PHP files that handle webhook tests properly

echo "🚀 Deploying webhook fixes..."

# Server details (update these with your actual server info)
SERVER_USER="root"
SERVER_HOST="your-webhook-server.com"
REMOTE_PATH="/var/www/hook.thynkdata.com/"

# Files to deploy
FILES=(
    "pixel_import.php"
    "pixel_import_re.php"
    "pixel_import_webhook.php"
    "webhook_debug.php"
)

# Create backup of existing files on server
echo "📦 Creating backup of existing files..."
ssh ${SERVER_USER}@${SERVER_HOST} "cd ${REMOTE_PATH} && mkdir -p backups && cp pixel_import*.php backups/"

# Upload updated files
echo "📤 Uploading updated webhook files..."
for file in "${FILES[@]}"; do
    if [ -f "$file" ]; then
        echo "  - Uploading $file..."
        scp "$file" ${SERVER_USER}@${SERVER_HOST}:${REMOTE_PATH}
    fi
done

# Set proper permissions
echo "🔐 Setting file permissions..."
ssh ${SERVER_USER}@${SERVER_HOST} "cd ${REMOTE_PATH} && chmod 644 pixel_import*.php"

# Create log file if it doesn't exist
echo "📝 Creating log file..."
ssh ${SERVER_USER}@${SERVER_HOST} "touch ${REMOTE_PATH}pixel_webhook_debug.log && chmod 666 ${REMOTE_PATH}pixel_webhook_debug.log"

echo "✅ Webhook fixes deployed successfully!"
echo ""
echo "📋 Next steps:"
echo "1. Test the debug webhook first:"
echo "   curl -X GET https://hook.thynkdata.com/webhook_debug.php?client=test_client"
echo ""
echo "2. Use the debug webhook in SimpleAudience to capture request details:"
echo "   https://hook.thynkdata.com/webhook_debug.php?client=VettaFi"
echo ""
echo "3. Monitor the debug logs:"
echo "   ssh ${SERVER_USER}@${SERVER_HOST} 'tail -f ${REMOTE_PATH}webhook_debug_full.log'"
echo "   ssh ${SERVER_USER}@${SERVER_HOST} 'tail -f ${REMOTE_PATH}webhook_simple.log'"
echo ""
echo "4. After identifying the issue, test the real webhook:"
echo "   curl -X GET https://hook.thynkdata.com/pixel_import.php?client=test_client" 