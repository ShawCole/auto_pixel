# Google Sheets Integration Setup Guide

## 1. Create Google Service Account

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable Google Sheets API:
   - Go to "APIs & Services" > "Library"
   - Search for "Google Sheets API"
   - Click Enable

4. Create Service Account:
   - Go to "APIs & Services" > "Credentials"
   - Click "Create Credentials" > "Service Account"
   - Name: "pixel-tracking-sync"
   - Click "Create and Continue"
   - Grant role: "Editor"
   - Click "Done"

5. Create JSON Key:
   - Click on the service account you created
   - Go to "Keys" tab
   - Add Key > Create new key > JSON
   - Save the file as `google-credentials.json`

## 2. Install Required Dependencies

```bash
# On pixel-php server
cd /opt/auto-pixel
composer require google/apiclient:^2.12
```

## 3. Store Credentials Securely

```bash
# Create secure directory for credentials
sudo mkdir -p /etc/auto-pixel
sudo mv google-credentials.json /etc/auto-pixel/
sudo chown www-data:www-data /etc/auto-pixel/google-credentials.json
sudo chmod 600 /etc/auto-pixel/google-credentials.json
```

## 4. Database Schema Update

Add a table to track sheet mappings:

```sql
CREATE TABLE `pixel_sheets` (
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
``` 