# Google Sheets Integration - Quick Setup Guide

## Overview
This integration automatically creates a Google Sheet for each new pixel and syncs data every 5 minutes.

## Quick Setup Steps

### 1. Set Up Google API Access (One Time - 10 minutes)

1. **Create Google Cloud Project**
   - Go to https://console.cloud.google.com
   - Create new project named "Pixel Tracking"
   - Enable Google Sheets API

2. **Create Service Account**
   ```bash
   # Download the JSON key file from Google Console
   # Then on your server:
   sudo mkdir -p /etc/auto-pixel
   sudo mv ~/google-credentials.json /etc/auto-pixel/
   sudo chown www-data:www-data /etc/auto-pixel/google-credentials.json
   sudo chmod 600 /etc/auto-pixel/google-credentials.json
   ```

3. **Install Dependencies**
   ```bash
   # PHP Google Client
   cd /opt/auto-pixel
   composer require google/apiclient:^2.12
   
   # Node.js packages
   cd /opt/auto-pixel/server
   npm install googleapis google-auth-library
   ```

### 2. Create Database Table
```bash
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' pixel << 'EOF'
CREATE TABLE IF NOT EXISTS `pixel_sheets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_name` varchar(100) NOT NULL,
  `pixel_id` varchar(100) NOT NULL,
  `sheet_id` varchar(100) NOT NULL,
  `sheet_url` text,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_client` (`client_name`),
  KEY `idx_pixel` (`pixel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF
```

### 3. Deploy Sync Script
```bash
# Copy the sync script to server
sudo cp ~/sheets_sync.php /opt/auto-pixel/sheets_sync.php
sudo chown www-data:www-data /opt/auto-pixel/sheets_sync.php
sudo chmod 755 /opt/auto-pixel/sheets_sync.php
```

### 4. Set Up Cron Job (Runs every 5 minutes)
```bash
# Add to crontab
sudo crontab -e

# Add this line:
*/5 * * * * /usr/bin/php /opt/auto-pixel/sheets_sync.php >> /var/log/sheets_sync.log 2>&1
```

### 5. Simple Node.js Integration
Add this to your pixel creation process in `server/src/index.ts`:

```typescript
// After creating pixel, create Google Sheet
async function createGoogleSheet(client: string, pixelId: string) {
  try {
    // For now, just log it - we'll manually create sheets
    console.log(`TODO: Create Google Sheet for ${client} with pixel ${pixelId}`);
    
    // Insert record for sync script to use
    await query(
      `INSERT INTO pixel.pixel_sheets (client_name, pixel_id, sheet_id, sheet_url) 
       VALUES (?, ?, 'PENDING', 'PENDING')`,
      [client, pixelId]
    );
  } catch (error) {
    console.error('Error recording sheet creation:', error);
  }
}
```

## Manual Sheet Creation (Temporary Process)

Until the automatic creation is set up:

1. **Create Sheet Manually**
   - Go to Google Sheets
   - Create new spreadsheet
   - Name it: "[Client Name] - Pixel Tracking Data"
   - Create two tabs: "Visitors" and "Events Log"

2. **Share with Service Account**
   - Click Share button
   - Add the service account email (from your JSON file)
   - Give "Editor" permission

3. **Update Database**
   ```sql
   UPDATE pixel.pixel_sheets 
   SET sheet_id = 'YOUR_SHEET_ID_HERE',
       sheet_url = 'YOUR_SHEET_URL_HERE'
   WHERE client_name = 'CLIENT_NAME';
   ```

   (Sheet ID is in the URL: https://docs.google.com/spreadsheets/d/SHEET_ID_HERE/edit)

## Testing

1. **Test Sync Script Manually**
   ```bash
   php /opt/auto-pixel/sheets_sync.php
   ```

2. **Check Logs**
   ```bash
   tail -f /var/log/sheets_sync.log
   ```

3. **Verify Sheet Updates**
   - Open the Google Sheet
   - Data should appear within 5 minutes of pixel activity

## What Gets Synced

### Visitors Tab
- Unique visitor profiles
- Updated every 5 minutes
- Shows: Name, Company, Contact Info, First/Last Seen, Event Count

### Events Log Tab  
- Recent 500 events
- New events appended every 5 minutes
- Shows: Timestamp, Event Type, Visitor Info, Page URL

## Limitations
- Maximum 1000 visitors per sheet (for performance)
- Maximum 500 recent events shown
- Updates every 5 minutes (can be adjusted in cron)

## Troubleshooting

1. **Sheet not updating?**
   ```bash
   # Check if sync is running
   ps aux | grep sheets_sync
   
   # Check logs
   tail -50 /var/log/sheets_sync.log
   
   # Test manually
   php /opt/auto-pixel/sheets_sync.php
   ```

2. **Permission errors?**
   - Ensure service account has Editor access to sheet
   - Check credentials file permissions

3. **Data missing?**
   - Verify client database name matches exactly
   - Check if pixel_sheets table has correct sheet_id 