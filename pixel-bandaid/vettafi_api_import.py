#!/usr/bin/env python3
"""
VettaFi Segment API Import Script
Fetches data from the VettaFi segment API and imports into the database with deduplication.
"""

import os
import sys
import json
import logging
import requests
import subprocess
import tempfile
import time
from dotenv import load_dotenv

load_dotenv()

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("vettafi_import.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# VettaFi Segment API Configuration
SEGMENT_API_URL = "https://api.audiencelab.io/segments/fc797e37-e549-4986-911e-921ac7378493"
API_KEY = "sk_aaaEJaKJZEzw39WFBTLPrvdnPZa5CmjMybZWzED4lY"
PAGE_SIZE = 500
CLIENT_NAME = "VettaFi"

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
                # Rate Limit hit - wait longer
                wait_time = 30 * (attempt + 1)
                logger.warning(f"Rate limited (429) on page {page_number}. Waiting {wait_time}s...")
                time.sleep(wait_time)
            else:
                # Other errors (500, 502, etc) - exponential backoff
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
    """
    Prepare event for pixel_import.php.
    pixel_import.php expects:
    1. Top-level metadata in lowercase (pixel_id, event_type, etc.)
    2. Data object under 'resolution' key with UPPERCASE field names.
    """
    # Create the resolution object with UPPERCASE keys (as PHP expects)
    resolution_data = {}
    for k, v in api_event.items():
        resolution_data[k.upper()] = v
    
    # helper to get value regardless of key case
    def get_val(key):
        return api_event.get(key.upper()) or api_event.get(key.lower(), "")

    # Extract metadata fields to top level (lowercase)
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
    
    # Calculate import_hash if missing
    uuid = event_wrapper["uuid"]
    etype = event_wrapper["event_type"]
    ets = event_wrapper["event_timestamp"]
    
    import hashlib
    hash_str = f"{uuid}|{etype}|{ets}"
    import_hash = hashlib.sha256(hash_str.encode()).hexdigest()
    
    event_wrapper["import_hash"] = import_hash
    
    return event_wrapper

def import_batch_to_database(events, client_name, retries=3):
    """Import a batch of events using pixel_import.php with retries."""
    if not events:
        return 0
    
    wrapped_events = [transform_event(event) for event in events]
    payload = {"events": wrapped_events}
    
    # Write to temp file
    with tempfile.NamedTemporaryFile(mode='w', delete=False, suffix='.json') as temp_file:
        json.dump(payload, temp_file, allow_nan=False)
        temp_file_path = temp_file.name
    
    try:
        for attempt in range(1, retries + 1):
            logger.info(f"Importing batch of {len(events)} events (Attempt {attempt}/{retries})...")
            
            env = os.environ.copy()
            env["CLIENT_NAME"] = client_name
            env["REQUEST_METHOD"] = "POST"
            
            db_host = os.environ.get("MYSQL_HOST") or os.environ.get("DB_HOST")
            if db_host:
                env["DB_HOST"] = db_host
            
            script_path = os.path.join(os.path.dirname(__file__), "pixel_import.php")
            process = subprocess.Popen(
                ["php", script_path, temp_file_path],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=env,
                text=True
            )
            
            stdout, stderr = process.communicate()
            
            if process.returncode == 0 and '"status":"success"' in stdout:
                logger.info(f"Import complete. Output: {stdout[:200]}")
                return len(events)
            else:
                logger.error(f"Attempt {attempt} failed: {stderr or stdout}")
                if attempt < retries:
                    wait_time = attempt * 5
                    logger.info(f"Waiting {wait_time} seconds before retry...")
                    time.sleep(wait_time)
        
        return 0
        
    finally:
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)

def main():
    logger.info("=" * 60)
    logger.info("VettaFi Segment API Import Starting (REVERSE CRAWL)")
    logger.info("=" * 60)
    
    total_imported = 0
    total_events = 0
    
    # Starting from the oldest available page to move towards the most recent
    # Based on latest check: 285,799 records / 500 = 572 pages
    START_PAGE = 74
    END_PAGE = 38
    page = START_PAGE
    
    while page >= END_PAGE:
        if page == 69:
            logger.info("Skipping known corrupt Page 69.")
            page -= 1
            continue
        data = fetch_segment_page(page)
        
        if not data:
            logger.error(f"Failed to fetch page {page}. Retrying in 10s...")
            time.sleep(10)
            continue
        
        events = data.get("data", []) or data.get("events", []) or data.get("results", [])
        
        if not events:
            logger.info(f"No events on page {page}. Skipping.")
        else:
            total_events += len(events)
            logger.info(f"[PROGRESS] Page {page}: Found {len(events)} events (Batch Total: {total_events})")
            
            imported = import_batch_to_database(events, CLIENT_NAME)
            if imported == 0:
                logger.error(f"Critical failure importing page {page}. Skipping to next page.")
            
            total_imported += imported
        
        page -= 1
        # Small delay to reduce load
        time.sleep(0.5)
    
    logger.info("=" * 60)
    logger.info(f"Import Summary:")
    logger.info(f"  Total events fetched: {total_events}")
    logger.info(f"  Total events imported: {total_imported}")
    logger.info(f"  Final Page processed: {page}")
    logger.info("=" * 60)
    
    # Run visitor backfill
    logger.info("[PROGRESS] Running visitor backfill...")
    try:
        subprocess.run(
            ["php", "run_backfill.php", CLIENT_NAME, "250000"],
            check=True,
            cwd=os.path.dirname(__file__)
        )
        logger.info("[PROGRESS] Visitor backfill complete")
    except Exception as e:
        logger.error(f"Backfill failed: {e}")
    
    logger.info("VettaFi import complete!")

if __name__ == "__main__":
    main()
