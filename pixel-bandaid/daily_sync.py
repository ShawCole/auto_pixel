import os
import argparse
import subprocess
import json
import logging
from automation import SimpleAudienceAutomation
from processor import SimpleAudienceProcessor
from database import SimpleAudienceDatabase
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
    parser.add_argument("--days", type=int, default=1, help="Number of days to export (default: 1)")
    parser.add_argument("--download-dir", type=str, default="./downloads", help="Directory for downloads")
    parser.add_argument("--headless", action="store_true", default=True, help="Run browser in headless mode")
    parser.add_argument("--no-headless", action="store_false", dest="headless", help="Run browser in headed mode")
    parser.add_argument("--local-db", action="store_true", default=False, help="Connect to DB locally without SSH")
    parser.add_argument("--dry-run", action="store_true", help="Do not actually upload data, just simulate")
    parser.add_argument("--manual-pixel", type=str, help="Skip DB fetch and manually process this pixel name")
    parser.add_argument("--manual-client", type=str, help="Client DB name for manual pixel (defaults to pixel name)")
    
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
        use_ssh = not args.local_db
        
        db_manager = SimpleAudienceDatabase(use_ssh=use_ssh)
        try:
            active_pixels = db_manager.get_active_pixels() 
        except Exception as e:
            logger.error(f"Failed to fetch active pixels: {e}")
            return

    if not active_pixels:
        logger.info("No active pixels found to process.")
        return

    # 2. Automation Setup
    logger.info(f"\n--- Stage 2: Selenium Automation (Headless: {args.headless}) ---")
    bot = SimpleAudienceAutomation(download_dir=args.download_dir, headless=args.headless)
    processor = SimpleAudienceProcessor()
    
    try:
        bot.login()
        
        processed_usa_financial = False
        
        for pixel_info in active_pixels:
            client_db = pixel_info['client_name']
            pixel_search_name = pixel_info['pixel_name']
            sheet_id = pixel_info.get('sheet_id')
            website_url = pixel_info.get('client_website') or f"https://{pixel_search_name}"
            
            # Handle USA_Financial redundancy
            if "USA_Financial" in client_db:
                if processed_usa_financial:
                    logger.info(f"Skipping redundant financial pixel: {client_db}")
                    continue
                processed_usa_financial = True

            # CHECK: Does it have a Google Sheet?
            if not sheet_id or sheet_id == 'PENDING':
                logger.info(f"Sheet missing for {client_db}. Creating now...")
                try:
                    # Capture pixel_id if possible, or use a dummy
                    # In a real scenario, we'd need the actual pixel_id (UUID) from the UI or DB
                    dummy_pixel_id = "AUTO_CREATED" 
                    subprocess.run(["php", "create_client_sheet.php", client_db, dummy_pixel_id, website_url], check=True)
                    logger.info(f"Sheet creation triggered for {client_db}")
                except Exception as e:
                    logger.error(f"Failed to create sheet for {client_db}: {e}")
            
            logger.info(f"\n>> Processing Pixel: {pixel_search_name} (Database: {client_db})")
            
            try:
                # Download
                raw_file_path = bot.download_pixel_data(client_db, days_to_pull)
                
                if not raw_file_path:
                    logger.warning(f"No file downloaded for {client_db}")
                    continue
                
                # Process to Payload
                logger.info(f"Preparing payload for {client_db}...")
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
                run_prepare_db(client_db)

                # Upload via PHP Script
                logger.info(f"Uploading to database: {client_db} via pixel_import.php...")
                run_pixel_import(payload, client_db)
                
                # Backfill Visitors
                logger.info(f"Backfilling visitors for {client_db}...")
                run_backfill(client_db)

                # NEW: Trigger Sheets Sync immediately
                logger.info(f"Syncing {client_db} to Google Sheets...")
                run_smart_sync(client_db)

                # NEW: Trigger Oplet Poller immediately
                logger.info(f"Updating Oplet status for {client_db}...")
                run_oplet_sync(client_db)
                
                logger.info(f"Successfully processed {pixel_search_name}!")
                
            except Exception as e:
                logger.error(f"Error processing {pixel_search_name}: {e}", exc_info=True)
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


def run_smart_sync(client_name):
    """
    Executes smart_sync.php to push data to Google Sheets.
    """
    # smart_sync.php is located in the parent directory
    script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "smart_sync.php"))
    
    if not os.path.exists(script_path):
        logger.error(f"smart_sync.php not found at: {script_path}")
        return

    try:
        # We use the --client flag to sync only the current client
        process = subprocess.Popen(
            ["php", script_path, f"--client={client_name}"],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        stdout, stderr = process.communicate()
        
        # Log success/failure from smart_sync.php stdout
        if "sync completed successfully" in stdout.lower():
            logger.info(f"Sheets sync successful for {client_name}")
        else:
            logger.warning(f"Sheets sync output for {client_name}: {stdout.strip()}")
        
        if process.returncode != 0:
            logger.error(f"Smart Sync Script failed (code {process.returncode}). Stderr: {stderr}")

    except Exception as e:
        logger.error(f"Failed to run smart_sync for {client_name}: {e}")


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

def run_oplet_sync(client_name):
    """
    Executes run_oplet_poller.py to update Admin Panel status.
    """
    script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "run_oplet_poller.py"))
    
    if not os.path.exists(script_path):
        logger.error(f"run_oplet_poller.py not found at: {script_path}")
        return

    try:
        # We use the --client flag to poll only the current client
        process = subprocess.Popen(
            ["python3", script_path, f"--client={client_name}"],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        stdout, stderr = process.communicate()
        
        if "oplet poller run complete" in stdout.lower():
            logger.info(f"Oplet status updated for {client_name}")
        else:
            logger.warning(f"Oplet poller output for {client_name}: {stdout.strip()}")
        
        if process.returncode != 0:
            logger.error(f"Oplet Poller Script failed (code {process.returncode}). Stderr: {stderr}")

    except Exception as e:
        logger.error(f"Failed to run oplet_poller for {client_name}: {e}")

if __name__ == "__main__":
    main()
