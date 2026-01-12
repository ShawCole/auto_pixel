import os
import argparse
import subprocess
import json
import time
import logging
from automation import SimpleAudienceAutomation
from processor import SimpleAudienceProcessor
from database import SimpleAudienceDatabase
from api_discovery_bot import ApiDiscoveryBot
from oplet_bot import OpletBot
from dotenv import load_dotenv

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("daily_sync.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

load_dotenv()

# Resolve pixel_import.php relative to this script
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
# Always use the local patched version packaged with this script
PIXEL_IMPORT_SCRIPT = os.path.abspath(os.path.join(BASE_DIR, "pixel_import.php"))


def main():
    parser = argparse.ArgumentParser(description="Daily Pixel Sync (Band-Aid Solution)")
    parser.add_argument("--days", type=int, default=3, help="Number of days to export (default: 3)")
    parser.add_argument("--download-dir", type=str, default="./downloads", help="Directory for downloads")
    parser.add_argument("--headless", action="store_true", default=True, help="Run browser in headless mode")
    parser.add_argument("--no-headless", action="store_false", dest="headless", help="Run browser in headed mode")
    parser.add_argument("--local-db", action="store_true", default=False, help="Connect to DB locally without SSH")
    parser.add_argument("--dry-run", action="store_true", help="Do not actually upload data, just simulate")
    parser.add_argument("--manual-pixel", type=str, help="Skip DB fetch and manually process this pixel name")
    parser.add_argument("--manual-client", type=str, help="Client DB name for manual pixel (defaults to pixel name)")
    parser.add_argument("--client", type=str, help="Specifically process this client name from database")
    
    parser.add_argument("--historical-pull", action="store_true", help="One-time pull for past 45 days")
    
    args = parser.parse_args()

    # Determine days count
    days_to_pull = args.days
    if args.historical_pull:
        logger.info("HISTORICAL PULL MODE: Pulling past 45 days.")
        days_to_pull = 45

    # 1. Fetch Active Pixels
    logger.info("--- Stage 1: Fetching Active Pixels ---")
    
    active_pixels = []
    if args.manual_pixel:
        logger.info(f"Manual Mode: Skipping DB fetch. Target: {args.manual_pixel}")
        active_pixels = [{'pixel_name': args.manual_pixel, 'client_name': args.manual_client or args.manual_pixel, 'sheet_id': 'MANUAL'}]
    else:
        # Determine if we should use SSH or local connection
        # If running on the VM, we likely want local-db (use_ssh=False)
        # Determine if we should use SSH or local connection
        use_ssh = not args.local_db
        db_manager = SimpleAudienceDatabase(use_ssh=use_ssh)
        try:
            active_pixels = db_manager.get_active_pixels() 
            
            # If --client is specified, filter for that client only
            if args.client:
                active_pixels = [p for p in active_pixels if p['client_name'].lower() == args.client.lower()]
                logger.info(f"Filtered for specific client: {args.client} (Found {len(active_pixels)})")
            
            logger.info(f"Fetched {len(active_pixels)} active pixels from database.")
        except Exception as e:
            logger.error(f"Failed to fetch active pixels: {e}")
            return

    if not active_pixels:
        logger.info("No active pixels found to process.")
        print("[DEBUG] active_pixels list is empty. Returning.")
        return
    
    print(f"[DEBUG] Processing {len(active_pixels)} pixels now...")
    # 2. Automation Setup
    logger.info(f"\n--- Stage 2: Selenium Automation (Headless: {args.headless}) ---")
    bot = SimpleAudienceAutomation(download_dir=args.download_dir, headless=args.headless)
    processor = SimpleAudienceProcessor()
    
    try:
        bot.login()
        
        processed_usa_financial = False
        
        for pixel_info in active_pixels:
            client_display_name = pixel_info['client_name'] # e.g. USA_Financial_NEW
            database_name = pixel_info['pixel_name']      # e.g. usafinancial.com
            sheet_id = pixel_info.get('sheet_id')
            website_url = pixel_info.get('client_website') or f"https://{database_name}"
            
            # Handle USA_Financial redundancy
            if "USA_Financial" in client_display_name:
                if processed_usa_financial:
                    logger.info(f"Skipping redundant financial pixel: {client_display_name}")
                    continue
                processed_usa_financial = True

            # CHECK: Does it have a Google Sheet?
            if not sheet_id or sheet_id == 'MISSING' or sheet_id == '':
                logger.info(f"No sheet ID for {database_name}. Attempting to trigger creation...")
                try:
                    # In a real scenario, we'd need the actual pixel_id (UUID) from the UI or DB
                    dummy_pixel_id = "AUTO_CREATED" 
                    subprocess.run(["php", "create_client_sheet.php", database_name, dummy_pixel_id, website_url], check=True)
                    logger.info(f"Sheet creation triggered for {database_name}")
                except Exception as e:
                    logger.error(f"Failed to create sheet for {database_name}: {e}")
            
            logger.info(f"\n>> Processing Pixel: {database_name} (Client: {client_display_name})")
            
            try:
                # --- STAGE 2: DOWNLOAD & METADATA ---
                logger.info(f"--- Stage 2: Download & Client Metadata for {database_name} ---")
                
                stored_url = pixel_info.get('on_platform_segment_url')
                metadata = {}
                raw_file_path = None
                
                # Fast Path: If we have a stored URL, skip search/creation
                if stored_url and stored_url != 'MISSING' and stored_url != '':
                    logger.info(f"Fast Path enabled for {client_display_name}. Using stored URL: {stored_url}")
                    raw_file_path, metadata = bot.capture_segment_metadata(client_display_name, existing_url=stored_url, days=days_to_pull)
                else:
                    # No stored URL: Search for existing 3, 4, or 5 day segments
                    logger.info(f"No stored URL for {client_display_name}. Searching for existing segments...")
                    
                    found_url = None
                    actual_pattern = None
                    
                    # Search specifically for days_to_pull first, then fallback to others
                    search_days = [days_to_pull]
                    for d in [3, 4, 5]:
                        if d not in search_days:
                            search_days.append(d)
                            
                    for d in search_days:
                        pattern = f"Last {d} Days - {client_display_name}"
                        logger.info(f"Checking for segment: '{pattern}'")
                        url = bot.check_exists_in_segments(client_display_name, expected_name_pattern=pattern)
                        if url:
                            logger.info(f"Found existing segment: '{pattern}' at {url}")
                            found_url = url
                            actual_pattern = pattern
                            break
                    
                    if found_url:
                        raw_file_path, metadata = bot.capture_segment_metadata(client_display_name, existing_url=found_url, days=days_to_pull)
                        if not metadata:
                            metadata = {}
                        metadata['segment_name'] = actual_pattern
                    else:
                        # None of the 3-5 day segments exist: Create new one
                        expected_pattern = f"Last {days_to_pull} Days - {client_display_name}"
                        logger.info(f"No suitable segment found. Creating '{expected_pattern}' now...")
                        bot.create_segment(client_display_name, days_list=[days_to_pull])
                        time.sleep(5)
                        
                        raw_file_path, metadata = bot.capture_segment_metadata(client_display_name, days=days_to_pull)
                        if not metadata:
                            metadata = {}
                        metadata['segment_name'] = expected_pattern
                
                # Update DB with Stage 2 metadata (Oplet, Row Count, Segment URL, ID, API)
                if metadata:
                    db_manager.update_pixel_metadata(client_display_name, database_name, metadata)

                # --- STAGE 3: API DISCOVERY (Admin) ---
                # logger.info(f"--- Stage 3: API Discovery (Admin) for {database_name} ---")
                # discovery_bot = ApiDiscoveryBot(headless=args.headless)
                # discovery_bot.process_single_pixel(pixel_info) 
                # discovery_bot.close()
                # 
                # Bypass Note: We now derive segment_id and segment_api from on_platform_segment_url in Stage 2.
                # Re-fetch the pixel to show the final 5 fields
                updated_pixels = db_manager.get_active_pixels()
                updated_pixel = next((p for p in updated_pixels if p['pixel_name'] == database_name), None)
                
                if updated_pixel:
                    logger.info("\n" + "="*50)
                    logger.info(f"LIFECYCLE VERIFICATION FOR {database_name}")
                    logger.info(f"1. segment_name: {updated_pixel.get('segment_name')}")
                    logger.info(f"2. segment_api: {updated_pixel.get('segment_api')}")
                    logger.info(f"3. segment_id: {updated_pixel.get('segment_id')}")
                    logger.info(f"4. on_platform_segment_url: {updated_pixel.get('on_platform_segment_url')}")
                    logger.info(f"5. on_platform_url: {updated_pixel.get('on_platform_url')}")
                    logger.info(f"EXTRA - oplet: {updated_pixel.get('last_event_at')}")
                    logger.info(f"EXTRA - visitors: {updated_pixel.get('visitors')}")
                    logger.info("="*50 + "\n")
                else:
                    logger.warning(f"Could not re-fetch updated pixel {database_name} for final verification logging.")
                
                # Process to Payload
                logger.info(f"Preparing payload for {database_name}...")
                payload = processor.prepare_import_payload(raw_file_path)
                
                event_count = len(payload.get("events", []))
                logger.info(f"Prepared payload with {event_count} events.")
                
                if event_count == 0:
                    logger.info("Skipping upload (0 events).")
                    continue

                if args.dry_run:
                     logger.info("[DRY RUN] Would upload payload to pixel_import.php")
                     continue

                # Prepare DB (Ensure Indices)
                run_prepare_db(client_display_name)

                # Upload via PHP Script
                logger.info(f"Uploading to database: {client_display_name} via pixel_import.php...")
                run_pixel_import(payload, client_display_name)
                
                # Backfill Visitors
                logger.info(f"Backfilling visitors for {client_display_name}...")
                run_backfill(client_display_name)
                
                # Sync to Google Sheets
                logger.info(f"Syncing {client_display_name} to Google Sheets...")
                run_sheet_sync(client_display_name)
                
                logger.info(f"Successfully processed {database_name}!")
                
            except Exception as e:
                logger.error(f"Error processing {database_name}: {e}", exc_info=True)
                # Continue to next pixel
    
    finally:
        bot.close()

    logger.info("\nAll tasks completed!")

def run_pixel_import(payload, client_name):
    """
    Executes pixel_import.php with the given payload via stdin.
    Passes ?client=CLIENT_NAME query param to select the DB.
    """
    
    # We can simulate the $_GET['client'] by setting an environment variable or modifying how the script reads it.
    # But checking pixel_import.php:
    # $client = isset($_GET['client']) ? ...
    # Standard CLI PHP doesn't populate $_GET automatically from args unless we fake it or the script supports CLI args.
    # The script uses $_GET['client']. 
    # Valid trick: PHP CLI can populate $_GET if we pass it with php-cgi, but standard php CLI doesn't.
    # However, we can set QUERY_STRING environment variable if we were using cgi, but here we are using `php` command.
    
    # ALTERNATIVE: Modify pixel_import.php to accept CLI arguments OR environment variables.
    # Looking at pixel_import.php lines 10-15:
    # $client = isset($_GET['client']) ? ...
    
    # I should change pixel_import.php slightly to check getenv('CLIENT') as well?
    # OR we can assume the user will run this on a web server? 
    # No, the user said "Selenium based csv export... and upload to mysql server (headless, hosted on the VM)".
    
    # Let's try attempting to set $_GET in the subprocess by prepending a tiny php wrapper string or just using php-cgi if installed.
    # Or cleaner: update pixel_import.php to check CLI args or ENV vars.
    # I'll update pixel_import.php to check `getenv('CLIENT_NAME')` as a fallback.
    
    # For now, I will use an environment variable passed to the subprocess and update pixel_import.php.
    
    env = os.environ.copy()
    env["CLIENT_NAME"] = client_name
    env["REQUEST_METHOD"] = "POST" # Simulate POST so the script processes it

    # Pass global DB settings to PHP subprocess
    # PHP script looks for DB_HOST, but .env might use MYSQL_HOST
    db_host = os.environ.get("MYSQL_HOST") or os.environ.get("DB_HOST")
    if db_host:
        env["DB_HOST"] = db_host
        logger.info(f"Passing DB_HOST={db_host} to PHP process")
    
    # We need to make sure pixel_import.php reads from stdin.
    # It does: $rawData = file_get_contents('php://input'); 
    # This works in CLI too.
    
    if not os.path.exists(PIXEL_IMPORT_SCRIPT):
        raise FileNotFoundError(f"pixel_import.php not found at: {PIXEL_IMPORT_SCRIPT}")
    
    # Ensure invalid JSON (NaN, Infinity) is not sent to PHP
    payload_json = json.dumps(payload, allow_nan=False)
    
    logger.info(f"Payload JSON Sample (First 500 chars): {payload_json[:500]}")
    logger.info(f"Payload JSON Length: {len(payload_json)}")

    # Create a temporary file for the payload
    # This avoids stdin pipe issues on some environments
    import tempfile
    
    with tempfile.NamedTemporaryFile(mode='w+', delete=False, suffix='.json') as temp_file:
        json.dump(payload, temp_file, allow_nan=False)
        temp_file_path = temp_file.name
        
    logger.info(f"Wrote payload to temp file: {temp_file_path}")
    
    try:
        # Pass filename as argument to PHP script
        process = subprocess.Popen(
            ["php", PIXEL_IMPORT_SCRIPT, temp_file_path],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            text=True
        )
        
        # internal communicate() will wait for process
        stdout, stderr = process.communicate()
        
        logger.info(f"PHP Output: {stdout[:500]}...") 
        if stderr:
             logger.warning(f"PHP Stderr: {stderr}")

        if process.returncode != 0:
            raise Exception(f"PHP Script failed (code {process.returncode}). Output: {stdout}. Stderr: {stderr}")

    except Exception as e:
        raise e

    finally:
        # Clean up temp file
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)
            logger.info(f"Cleaned up temp file: {temp_file_path}")


def run_backfill(client_name):
    """
    Executes run_backfill.php to populate superpixel_visitors table.
    """
    env = os.environ.copy()
    env["CLIENT_NAME"] = client_name
    db_host = os.environ.get("MYSQL_HOST") or os.environ.get("DB_HOST")
    if db_host:
        env["DB_HOST"] = db_host

    script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "run_backfill.php"))
    
    try:
        process = subprocess.Popen(
            ["php", script_path, client_name, "100000"],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            text=True
        )
        stdout, stderr = process.communicate()
        
        logger.info(f"Backfill Output: {stdout.strip()}")
        
        if process.returncode != 0:
            logger.warning(f"Backfill Script warning/error (code {process.returncode}). Stderr: {stderr}")

    except Exception as e:
        logger.error(f"Failed to run backfill for {client_name}: {e}")


def run_prepare_db(client_name):
    """
    Executes prepare_client.php to ensure DB schema indices.
    """
    env = os.environ.copy()
    env["CLIENT_NAME"] = client_name
    db_host = os.environ.get("MYSQL_HOST") or os.environ.get("DB_HOST")
    if db_host:
        env["DB_HOST"] = db_host

    script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "prepare_client.php"))
    
    try:
        process = subprocess.Popen(
            ["php", script_path, client_name],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            text=True
        )
        stdout, stderr = process.communicate()
        if process.returncode != 0:
            logger.warning(f"Prepare DB Warning for {client_name}: {stdout} {stderr}")

    except Exception as e:
        logger.error(f"Failed to prepare DB for {client_name}: {e}")

def run_sheet_sync(client_name):
    """
    Executes smart_sync.php to update Google Sheets.
    """
    env = os.environ.copy()
    env["CLIENT_NAME"] = client_name
    
    # smart_sync.php is in the parent directory (pixel-bandaid)
    # relative to daily_sync.py in pixel-bandaid-v2
    script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "smart_sync.php"))
    
    if not os.path.exists(script_path):
        # Fallback to current directory if not found in parent
        script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "smart_sync.php"))

    try:
        process = subprocess.Popen(
            ["php", script_path, "--client=" + client_name],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            text=True
        )
        stdout, stderr = process.communicate()
        
        logger.info(f"Sheet Sync Output: {stdout.strip()}")
        if stderr:
            logger.warning(f"Sheet Sync Stderr: {stderr}")

    except Exception as e:
        logger.error(f"Failed to run sheet sync for {client_name}: {e}")

if __name__ == "__main__":
    main()
