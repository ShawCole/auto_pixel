# Webhook Debugging Guide

## The Issue
SimpleAudience webhook test is failing with "Failed to reach webhook URL" even though:
- The database is created before the pixel
- The webhook works when tested via curl from SSH
- The endpoint returns success for the same client

This suggests either:
1. **IP Whitelisting/Firewall** - SimpleAudience servers are blocked
2. **Request Format** - SimpleAudience sends different data than expected
3. **Headers/Authentication** - Missing required headers
4. **SSL/TLS Issues** - Certificate or protocol mismatch

## Step-by-Step Debugging Process

### Step 1: Deploy Debug Webhook
```bash
# Update server details in deploy-webhook-fix.sh first
nano deploy-webhook-fix.sh  # Update SERVER_HOST

# Deploy the debug files
./deploy-webhook-fix.sh
```

### Step 2: Test Debug Webhook
```bash
# Test that debug webhook works
curl -X GET https://hook.thynkdata.com/webhook_debug.php?client=test
curl -X POST https://hook.thynkdata.com/webhook_debug.php?client=test \
  -H "Content-Type: application/json" \
  -d '{"test": "data"}'
```

### Step 3: Use Debug Webhook in SimpleAudience
1. Go to SimpleAudience webhook settings
2. Change the webhook URL to:
   ```
   https://hook.thynkdata.com/webhook_debug.php?client=VettaFi
   ```
3. Click "Test" button
4. Note the exact error message (if any)

### Step 4: Monitor Logs
```bash
# In a new terminal, run:
./monitor-webhook-logs.sh

# Choose option 1 or 2 to monitor in real-time
# Or SSH directly:
ssh root@your-server 'tail -f /var/www/hook.thynkdata.com/webhook_debug_full.log'
```

### Step 5: Analyze the Captured Data
Look for:
1. **IP Address** - What IP is SimpleAudience using?
2. **Headers** - Any special headers required?
3. **Request Method** - GET, POST, or something else?
4. **Body Format** - JSON structure differences
5. **User Agent** - Identify SimpleAudience's bot

### Step 6: Common Fixes

#### If NO request appears in logs:
**It's a network/firewall issue**
```bash
# Check server firewall
sudo ufw status
sudo iptables -L

# Check nginx/apache logs
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# Check fail2ban
sudo fail2ban-client status
```

#### If request appears but with different format:
**Update PHP script to handle SimpleAudience format**
```php
// Add to pixel_import.php after the GET handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    
    // Handle SimpleAudience test data
    if (isset($data['test']) || isset($data['_test'])) {
        echo json_encode(['status' => 'success', 'message' => 'Test received']);
        exit;
    }
}
```

#### If specific headers are required:
**Add required headers to response**
```php
header('X-Webhook-Version: 1.0');
header('Access-Control-Allow-Origin: *');
```

### Step 7: Test with Local Webhook Server
If you need more control:
```bash
# Install ngrok if not already installed
brew install ngrok  # on macOS

# Start local webhook server
cd server
node webhook-test-server.js

# In another terminal, expose it publicly
ngrok http 3001

# Use the ngrok URL in SimpleAudience
# Example: https://abc123.ngrok.io/pixel_import.php?client=VettaFi
```

### Step 8: SimpleAudience IP Whitelist
Once you identify SimpleAudience IPs from the logs, whitelist them:

```bash
# Add to server firewall
sudo ufw allow from SIMPLEAUDIENCE_IP to any port 443
sudo ufw reload

# Or add to .htaccess
echo "Allow from SIMPLEAUDIENCE_IP" >> /var/www/hook.thynkdata.com/.htaccess
```

## Quick Commands Reference

```bash
# Test original webhook
curl -X GET https://hook.thynkdata.com/pixel_import.php?client=VettaFi

# Test debug webhook
curl -X GET https://hook.thynkdata.com/webhook_debug.php?client=VettaFi

# View logs
ssh root@server 'tail -f /var/www/hook.thynkdata.com/webhook_*.log'

# Clear logs
ssh root@server 'cd /var/www/hook.thynkdata.com && > webhook_debug_full.log'

# Check PHP errors
ssh root@server 'tail -f /var/log/php/*.log'
```

## Expected SimpleAudience Test Data
Based on documentation, SimpleAudience likely sends:
```json
{
  "events": [{
    "pixel_id": "test_pixel_id",
    "event_type": "test",
    "event_timestamp": "2024-01-01T00:00:00Z",
    "test": true,
    "resolution": {
      "test_data": "This is a test"
    }
  }]
}
```

## Next Steps After Debugging
1. Update `pixel_import.php` to handle the exact format SimpleAudience uses
2. Ensure all required headers are returned
3. Whitelist SimpleAudience IPs if needed
4. Test the complete pixel creation flow end-to-end 