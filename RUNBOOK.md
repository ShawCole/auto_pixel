# � Auto-Pixel System Master Runbook

This runbook is the definitive guide for the administration, troubleshooting, and extension of the Auto-Pixel platform.

---

## 📑 Table of Contents
1. [System Architecture](#-1-system-architecture)
2. [VM Environment & Directory Map](#-2-vm-environment--directory-map)
3. [Service Management (Start/Stop/Kill)](#-3-service-management)
4. [The Log Center (Monitoring)](#-4-the-log-center)
5. [Data Integrity & Deduplication](#-5-data-integrity)
6. [Troubleshooting & Immediate Fixes](#-6-troubleshooting)
7. [The Matching System (NPN/CRD Enrichment)](#-7-the-matching-system)
8. [Developer Guide: Adding the `match_worker`](#-8-adding-the-match_worker)

---

## 🏗️ 1. System Architecture
The application is split into three core "Life-cycles":

### **A. Generation & Control (Node.js/TS)**
The "Brain." It receives requests to create a new pixel. It creates the database, table schema, and Google Sheet, then calls AudienceLab to generate the pixel code.
*   **Main File**: `/opt/auto-pixel/server/src/index.ts`
*   **Management**: Handled via PM2.

### **B. Standard Sync Pipeline (Selenium/PHP)**
The "Workhorse." It extracts data from pixels that don't have API access. It logs into the UI, clicks "Export", processes the CSV, and imports it.
*   **Main File**: `/opt/auto-pixel/pixel-bandaid-v2/daily_sync.py`
*   **Schedule**: Managed via system cron.

### **C. VettaFi Pipeline (REST API)**
The "High-Volume Pipeline." It polls the AudienceLab REST API directly every 60 seconds.
*   **Main File**: `/opt/auto-pixel/pixel-bandaid/vettafi_sync.py`
*   **Management**: Background Python process using `nohup`.

---

## � 2. VM Environment & Directory Map
All operations take place on the **pixel-php** VM.

*   **`/opt/auto-pixel/`**: Main application code.
*   **`/opt/auto-pixel/server/`**: Node.js API and AudienceLab automation.
*   **`/opt/auto-pixel/pixel-bandaid/`**: VettaFi API poller and legacy visitor logic.
*   **`/opt/auto-pixel/pixel-bandaid-v2/`**: Modern Selenium automation components.
*   **`/var/www/hook.thynkdata.com/`**: Inbound webhook processing (PHP).
*   **`/etc/auto-pixel/`**: Highly sensitive Google Service Account keys.

---

## ⚙️ 3. Service Management

| Service | Start Command | Kill Command |
| :--- | :--- | :--- |
| **Node.js API** | `pm2 start auto-pixel-api` | `pm2 stop auto-pixel-api` |
| **VettaFi Sync** | `nohup python3 /opt/auto-pixel/pixel-bandaid/vettafi_sync.py > /dev/null 2>&1 &` | `pkill -f vettafi_sync.py` |
| **Sheet Monitor** | `nohup php /opt/auto-pixel/monitor_new_sheets.php > /opt/auto-pixel/monitor.log 2>&1 &` | `pkill -f monitor_new_sheets.php` |
| **Smart Sync** | Managed by Cron (runs every 5m) | `pkill -f smart_sync.php` |

### **Check Status**
*   `pm2 status` (Check Node.js)
*   `ps aux | grep .py` (Check Python syncers)
*   `ps aux | grep .php` (Check PHP monitors/syncers)

---

## 📡 4. The Log Center
To diagnose the system, use these `tail` commands:

```bash
# 1. Look for Data Ingestion Errors (MySQL Insert Failures)
tail -f /opt/auto-pixel/pixel-bandaid/pixel_import_debug.log

# 2. Watch VettaFi API Traffic
tail -f /opt/auto-pixel/pixel-bandaid/vettafi_sync.log

# 3. Watch Standard Pixel Sync (Selenium Actions)
tail -f /opt/auto-pixel/pixel-bandaid-v2/daily_sync.log

# 4. Watch Google Sheet Sync (Data moving from DB to Sheets)
tail -f /opt/auto-pixel/sync.log

# 5. Watch API Health
pm2 logs auto-pixel-api
```

---

## 🔒 5. Data Integrity
### **The `import_hash` (Non-Negotiable)**
Every event in the system is uniquely identified by an `import_hash`.
*   **Formula**: `SHA256(uuid | event_type | event_timestamp)`
*   **Purpose**: This allows us to re-run imports 100 times without ever creating duplicate data.
*   **Check**: If you suspect duplicates, check if the `IDX_IMPORT_HASH` unique index exists on the `superpixel_resolution_log` table.

---

## 🚑 6. Troubleshooting

### **Scenario A: There is a "Hole" in VettaFi Data**
If the script was off for 10 hours, it won't automatically find the gap.
**Fix**: Perform a manual backfill override:
```bash
cd /opt/auto-pixel/pixel-bandaid
python3 -c "import vettafi_sync; vettafi_sync.get_latest_timestamp = lambda: 'YYYY-MM-DDTHH:MM:SSZ'; vettafi_sync.sync_cycle()"
```

### **Scenario B: "bind_param() on bool" Error**
This means the PHP script crashed because it's looking for a column that doesn't exist.
**Fix**: Update the client schema:
```bash
php /opt/auto-pixel/add_emails_tables.php
```

### **Scenario C: Quota Exceeded (Google Sheets)**
The system is hitting Google API limits.
**Fix**:
1.  Increase the delay in `smart_sync.php` (currently 15s).
2.  Clear the lock file: `rm /tmp/smart_sync.lock`.

---

## 🧬 7. The Matching System
We are transitioning from Email-matching to **UUID-matching**.
*   **Master Table**: `accupoint_solutions.Contacts`
*   **Logic**: 
    1.  A visitor arrives with a `UUID`.
    2.  We look up that `UUID` in the master contacts table.
    3.  If found, we pull the `NPN` and `CRD` and write them to the client's `superpixel_resolution_log`.

---

## 👷 8. Developer Guide: Adding the `match_worker`
To build the next-generation enrichment worker, follow this blueprint.

### **Step 1: Create the SQL Routine**
The worker should execute a cross-database join.
```sql
-- The "Enrichment Engine"
UPDATE CLIENT_DB.superpixel_visitors v
INNER JOIN accupoint_solutions.Contacts c ON v.uuid = c.uuid
SET 
  v.npn = c.NPN,
  v.crd = c.CRD
WHERE (v.npn IS NULL OR v.crd IS NULL);

-- Same for the logs
UPDATE CLIENT_DB.superpixel_resolution_log l
INNER JOIN accupoint_solutions.Contacts c ON l.uuid = c.uuid
SET 
  l.npn = c.NPN,
  l.crd = c.CRD
WHERE (l.npn IS NULL OR l.crd IS NULL);
```

### **Step 2: Create the Python/PHP Script**
Create `match_worker.php` that:
1.  Fetches all active clients from `pixel.pixel_sheets`.
2.  Loops through each client database.
3.  Executes the two `UPDATE` queries above.
4.  Logs the number of "Matches Found."

### **Step 3: Schedule the Worker**
Add it to the crontab to run every hour:
```bash
0 * * * * php /opt/auto-pixel/match_worker.php >> /opt/auto-pixel/match_worker.log 2>&1
```

---

## 🚀 Final Deployment Checklist
Before leaving the project or finishing a shift:
1.  [ ] `pm2 status` shows `auto-pixel-api` is ONLINE.
2.  [ ] `tail -f sync.log` shows sheets are updating.
3.  [ ] `tail -f vettafi_sync.log` shows VettaFi is heartbeat polling.
4.  [ ] No `.lock` files exist in `/tmp/`.
