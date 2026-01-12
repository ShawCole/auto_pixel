#!/usr/bin/env python3
"""
VettaFi Segment API Sync Script (Real-Time Poller)
Fetches data from the VettaFi segment API and imports into the database.
Uses timestamp-based checkpointing to only ingest new events.
"""

import os
import sys
import json
import logging
import requests
import subprocess
import tempfile
import time
from datetime import datetime
from dotenv import load_dotenv

load_dotenv()

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("vettafi_sync.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# VettaFi Segment API Configuration
SEGMENT_API_URL = "https://api.audiencelab.io/segments/fc797e37-e549-4986-911e-921ac7378493"
API_KEY = "sk_aaaEJaKJZEzw39WFBTLPrvdnPZa5CmjMybZWzED4lY"
PAGE_SIZE = 500
CLIENT_NAME = "VettaFi"
# Note: The pixel_id in the database for VettaFi appears to be e66bb188-742b-4b12-afab-c090e4550d66
PIXEL_ID = "e66bb188-742b-4b12-afab-c090e4550d66"

def get_latest_timestamp():
    """Retrieve the most recent event_timestamp from the database for VettaFi."""
    php_path = "/opt/homebrew/bin/php" if os.path.exists("/opt/homebrew/bin/php") else "php"
    php_code = f"""
    $mysqli = new mysqli('34.26.61.148', 'root', 'AccuPoint01!', 'VettaFi');
    $res = $mysqli->query("SELECT MAX(event_timestamp) FROM superpixel_resolution_log WHERE pixel_id = '{PIXEL_ID}'");
    if ($res && $row = $res->fetch_row()) {{
        echo $row[0] ?: '';
    }}
    """
    try:
        process = subprocess.run(
            [php_path, "-r", php_code],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        ts = process.stdout.strip()
        if ts:
            logger.info(f"Found latest timestamp in DB: {ts}")
            return ts
    except Exception as e:
        logger.error(f"Error fetching latest timestamp: {e}")
    
    logger.warning("No latest timestamp found in DB or error occurred. Starting fresh.")
    return "1970-01-01T00:00:00Z"

def update_central_status(latest_platform_ts):
    """Update central pixel_sheets with the latest platform timestamp and server counts."""
    if not latest_platform_ts or latest_platform_ts == "N/A":
        return

    logger.info(f"Updating central management status with platform TS: {latest_platform_ts}...")
    php_path = "/opt/homebrew/bin/php" if os.path.exists("/opt/homebrew/bin/php") else "php"
    php_code = f"""
    $mysqli = new mysqli('34.26.61.148', 'root', 'AccuPoint01!', 'pixel');
    if ($mysqli->connect_error) die("pixel db conn error");

    $vettafi_db = new mysqli('34.26.61.148', 'root', 'AccuPoint01!', 'VettaFi');
    if ($vettafi_db->connect_error) die("vettafi db conn error");

    // 1. Get current stats from VettaFi DB
    $res_v = $vettafi_db->query("SELECT COUNT(*) as v FROM superpixel_visitors");
    $stats_v = $res_v->fetch_assoc();
    $visitors = $stats_v['v'] ?: 0;

    $res_e = $vettafi_db->query("SELECT COUNT(*) as e FROM superpixel_resolution_log");
    $stats_e = $res_e->fetch_assoc();
    $events = $stats_e['e'] ?: 0;

    $res_max = $vettafi_db->query("SELECT MAX(event_timestamp) as last FROM superpixel_resolution_log");
    $stats_max = $res_max->fetch_assoc();
    $last_event_at = $stats_max['last'] ?: null;

    // 2. Update central pixel_sheets
    $ts_var = "{latest_platform_ts}";
    $stmt = $mysqli->prepare("UPDATE pixel_sheets SET oplet = ?, last_event_at = ?, visitors = ?, events = ? WHERE client_name = 'VettaFi'");
    $stmt->bind_param("ssii", $ts_var, $last_event_at, $visitors, $events);
    $stmt->execute();
    
    echo "SUCCESS";
    $stmt->close();
    $mysqli->close();
    $vettafi_db->close();
    """
    try:
        process = subprocess.run(
            [php_path, "-r", php_code],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        if "SUCCESS" in process.stdout:
            logger.info("✅ Central management status updated successfully")
        else:
            logger.error(f"Failed to update central status: {process.stdout} {process.stderr}")
    except Exception as e:
        logger.error(f"Error updating central status: {e}")

def fetch_segment_page(page_number):
    """Fetch a single page from the segment API with rate limiting and backoff."""
    url = f"{SEGMENT_API_URL}?page={page_number}&page_size={PAGE_SIZE}"
    headers = {"X-Api-Key": API_KEY}
    
    # Add Safety Delay (Rate Limit) of 2s
    time.sleep(2)

    for attempt in range(3):
        try:
            logger.info(f"Fetching page {page_number} (Attempt {attempt+1}/3)...")
            response = requests.get(url, headers=headers, timeout=60)
            
            if response.status_code == 200:
                return response.json()
            elif response.status_code == 429:
                wait_time = 30 * (attempt + 1)
                logger.warning(f"Rate limited (429) on page {page_number}. Waiting {wait_time}s...")
                time.sleep(wait_time)
            else:
                wait_time = 10 * (attempt + 1)
                logger.error(f"API failed page {page_number}: {response.status_code}. Retry in {wait_time}s...")
                time.sleep(wait_time)
                
        except Exception as e:
            wait_time = 10 * (attempt + 1)
            logger.error(f"Exception fetching page {page_number}: {e}. Retry in {wait_time}s...")
            time.sleep(wait_time)

    logger.error(f"Permanently failed to fetch page {page_number} after 3 attempts.")
    return None

def transform_event(api_event):
    """Prepare event for pixel_import.php."""
    resolution_data = {}
    for k, v in api_event.items():
        resolution_data[k.upper()] = v
    
    def get_val(key):
        return api_event.get(key.upper()) or api_event.get(key.lower(), "")

    event_wrapper = {
        "resolution": resolution_data,
        "pixel_id": str(get_val("PIXEL_ID")),
        "event_type": str(get_val("EVENT_TYPE")),
        "event_timestamp": str(get_val("EVENT_TIMESTAMP")),
        "uuid": str(get_val("UUID")),
        "hem_sha256": str(get_val("HEM_SHA256")),
        "ip_address": str(get_val("IP_ADDRESS")),
        "event_data": get_val("EVENT_DATA")
    }
    
    import hashlib
    hash_str = f"{event_wrapper['uuid']}|{event_wrapper['event_type']}|{event_wrapper['event_timestamp']}"
    event_wrapper["import_hash"] = hashlib.sha256(hash_str.encode()).hexdigest()
    
    return event_wrapper

def import_batch_to_database(events, client_name, retries=3):
    """Import a batch of events using pixel_import.php with retries."""
    if not events:
        return 0
    
    wrapped_events = [transform_event(event) for event in events]
    payload = {"events": wrapped_events}
    
    with tempfile.NamedTemporaryFile(mode='w', delete=False, suffix='.json') as temp_file:
        json.dump(payload, temp_file, allow_nan=False)
        temp_file_path = temp_file.name
    
    try:
        for attempt in range(1, retries + 1):
            env = os.environ.copy()
            env["CLIENT_NAME"] = client_name
            env["REQUEST_METHOD"] = "POST"
            
            script_path = os.path.join(os.path.dirname(__file__), "pixel_import.php")
            php_path = "/opt/homebrew/bin/php" if os.path.exists("/opt/homebrew/bin/php") else "php"
            process = subprocess.Popen(
                [php_path, script_path, temp_file_path],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=env,
                text=True
            )
            
            stdout, stderr = process.communicate()
            
            if process.returncode == 0 and '"status":"success"' in stdout:
                return len(events)
            else:
                logger.error(f"Import failed (Attempt {attempt}): {stderr or stdout}")
                if attempt < retries:
                    time.sleep(attempt * 5)
        return 0
    finally:
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)

def sync_cycle():
    """Perform one sync cycle, fetching only new events."""
    latest_ts = get_latest_timestamp()
    logger.info(f"------------------------------------------------------------")
    logger.info(f"Starting sync cycle.")
    logger.info(f"CUTOFF TIMESTAMP (from DB): {latest_ts}")
    logger.info(f"------------------------------------------------------------")
    
    total_imported = 0
    page = 1
    stop_sync = False
    
    while not stop_sync:
        data = fetch_segment_page(page)
        if not data:
            break
            
        events = data.get("data", []) or data.get("events", []) or data.get("results", [])
        if not events:
            logger.info("No more events found.")
            break
            
        first_event_ts = events[0].get("EVENT_TIMESTAMP") if events else "N/A"
        last_event_ts = events[-1].get("EVENT_TIMESTAMP") if events else "N/A"
        
        logger.info(f"Page {page}: Fetched {len(events)} events.")
        logger.info(f"    Page Time Range: {first_event_ts} (newest) -> {last_event_ts} (oldest)")

        new_events = []
        for event in events:
            # Handle timestamps (assuming API and DB use ISO8601 strings)
            event_ts = event.get("EVENT_TIMESTAMP") or event.get("event_timestamp", "")
            if event_ts > latest_ts:
                new_events.append(event)
            else:
                # We reached already ingested data
                stop_sync = True
        
        logger.info(f"    Analysis: {len(new_events)} NEW events | {len(events) - len(new_events)} EXIST in DB")
                
        if new_events:
            logger.info(f"    -> Importing {len(new_events)} new events...")
            imported = import_batch_to_database(new_events, CLIENT_NAME)
            total_imported += imported
        
            break

        # Capture the absolute newest TS from page 1 to update oplet
        if page == 1 and events:
            newest_platform_ts = events[0].get("EVENT_TIMESTAMP") or events[0].get("event_timestamp", "N/A")
            logger.info(f"    -> Latest Platform TS: {newest_platform_ts}")
            update_central_status(newest_platform_ts)
            
        page += 1
        if page > 2000: # Increased safety cap to allow for ~1 million event backlogs (500 * 2000)
            logger.warning("Safety cap of 2000 pages reached. Stopping this cycle.")
            break

    logger.info(f"Sync cycle complete. Total new events imported: {total_imported}")
    return total_imported

def main():
    logger.info("=" * 60)
    logger.info("VettaFi Real-Time Poller Starting")
    logger.info("=" * 60)
    
    while True:
        try:
            sync_cycle()
        except Exception as e:
            logger.error(f"Unexpected error in sync cycle: {e}")
            
        # Wait before next poll cycle. 
        # User said "pull page 1 over and over", so we poll frequently but respect API load.
        wait_interval = 60 # 1 minute
        logger.info(f"Waiting {wait_interval}s until next poll...")
        time.sleep(wait_interval)

if __name__ == "__main__":
    main()
