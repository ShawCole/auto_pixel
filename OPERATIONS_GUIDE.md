# 🚀 Auto-Pixel Operations & Management Guide

This guide covers everything required to facilitate, manage, and troubleshoot the Auto-Pixel tracking system.

---

## 🏗️ System Architecture
The system is divided into three main "pipelines":

1.  **Generation Pipeline (Node.js)**: Handles the creation of new pixels, databases, and Google Sheets.
2.  **Standard Sync Pipeline (Selenium/PHP)**: Handles routine pixels by logging into the AudienceLab UI, exporting CSVs, and importing them.
3.  **VettaFi Pipeline (REST API)**: A specialized high-volume pipeline that polls the AudienceLab API directly via Python scripts.

---

## 📊 Monitoring & Health Checks
Run these commands first if you suspect something is wrong.

### 1. Check all live logs (The "Four Terminals" Rule)
Open four terminal windows to watch the system heartbeats:
```bash
# Terminal 1: Standard Smart Sync (Google Sheets updates)
tail -f /opt/auto-pixel/sync.log

# Terminal 2: VettaFi API Sync (Direct API polling)
tail -f /opt/auto-pixel/pixel-bandaid/vettafi_sync.log

# Terminal 3: New Sheet Monitor (Detecting newly created pixels)
tail -f /opt/auto-pixel/monitor.log

# Terminal 4: API Server Logs (Pixel generation & webhook status)
pm2 logs auto-pixel-api
```

### 2. Check Process Status
```bash
pm2 status                    # Check Node.js API
ps aux | grep vettafi_sync    # Check VettaFi Poller
ps aux | grep monitor_new     # Check Sheet Monitor
crontab -l                    # Check the Smart Sync schedule
```

---

## 🔄 Pipeline Management

### VettaFi API (Special Case)
VettaFi does not use Selenium; it uses a direct Python poller.
*   **To Launch/Restart**:
    ```bash
    pkill -f vettafi_sync.py
    cd /opt/auto-pixel/pixel-bandaid
    nohup python3 vettafi_sync.py > vettafi_sync_nohup.log 2>&1 &
    ```

### Standard Pixels (Selenium Sync)
These are handled by `daily_sync.py` in `pixel-bandaid-v2`.
*   **Force Sync Specific Client**:
    ```bash
    cd /opt/auto-pixel/pixel-bandaid-v2
    python3 daily_sync.py --client="CLIENT_NAME" --days=3
    ```

### Google Sheets Sync
Data moves from MySQL to Sheets every 5 minutes.
*   **Force Immediate Sync (All Clients)**:
    ```bash
    php /opt/auto-pixel/force_sync.php
    ```

---

## 🛠️ Common Troubleshooting

### ❌ Issue: "Call to a member function bind_param() on bool"
**Cause**: The script failed to prepare a SQL statement, usually because a table is missing or a column name is wrong.
**Fix**:
1.  Check the `VettaFi` or `CLIENT_NAME` database tables.
2.  Run the schema fix utility:
    ```bash
    php /opt/auto-pixel/fix_all_schemas.php
    ```

### ❌ Issue: VettaFi Sync shows "Import failed (500 Internal Server Error)"
**Cause**: Usually an error in `pixel_import.php` or a database connection timeout.
**Fix**:
1.  Check `pixel_import_debug.log` in the `pixel-bandaid` folder.
2.  Ensure you can manually connect to the DB:
    ```bash
    mysql -h 34.26.61.148 -u root -p'AccuPoint01!' -e "SELECT 1"
    ```

### ❌ Issue: Google Sheets are not updating
**Cause**: The Smart Sync is stuck or hitting a quota.
**Fix**:
1.  Check `/opt/auto-pixel/sync.log` for "Quota Exceeded" or "Lock file exists".
2.  Clear the lock file if it's orphaned:
    ```bash
    rm /tmp/smart_sync.lock
    ```

---

## 🗄️ Database Operations
The system uses **Google Cloud SQL (34.26.61.148)**.

### Check Client Counts
```bash
mysql -h 34.26.61.148 -u root -p'AccuPoint01!' CLIENT_NAME -e "
  SELECT COUNT(*) as events FROM superpixel_resolution_log;
  SELECT COUNT(*) as visitors FROM superpixel_visitors;
"
```

### Delete a Client (Full Cleanup)
Use this if a client is cancelled or a test went wrong:
```bash
php /opt/auto-pixel/delete_client.php 'CLIENT_NAME'
```

---

## 🔑 Directory Map
*   `/opt/auto-pixel/`: Root directory for all code.
*   `/opt/auto-pixel/server/`: Node.js API source.
*   `/opt/auto-pixel/pixel-bandaid/`: VettaFi API scripts & legacy sync.
*   `/opt/auto-pixel/pixel-bandaid-v2/`: Current Selenium automation scripts.
*   `/var/www/hook.thynkdata.com/`: Production webhooks for incoming pixel data.

---

## 📜 Maintenance Check-list (Daily/Weekly)
1.  **Logs**: Truncate log files if they exceed 500MB (especially `pixel_import_debug.log`).
2.  **Sync Count**: Check that the "events" in MySQL roughly match the row count in the Google Sheet.
3.  **VettaFi**: Ensure the `vettafi_sync.py` process hasn't been killed by a server OOM (Out of Memory) event.
4.  **Problematic Sheets**: Run `php check_problematic_sheets.php` to identify databases that are corrupted or missing columns.

---

## 🚑 Support Contact
If the system is completely unresponsive:
1.  Check GCP Console for VM status.
2.  Restart Nginx: `sudo systemctl restart nginx`.
3.  Restart API: `pm2 restart all`.
