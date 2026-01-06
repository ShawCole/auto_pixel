import os
import logging
import traceback
import argparse
from oplet_bot import OpletBot
from sqlalchemy import create_engine, text
from dotenv import load_dotenv

# Configure Logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

load_dotenv()

def run_poller(client_name=None):
    """Fetches pixels requiring OPLET polling and updates their timestamps."""
    logger.info(f"Starting OPLET Poller run (Target: {client_name if client_name else 'ALL'})...")
    
    # Database Setup
    db_host = os.getenv("MYSQL_HOST") or os.getenv("DB_HOST") or "34.26.61.148"
    db_user = os.getenv("MYSQL_USER") or os.getenv("DB_USER") or "root"
    db_pass = os.getenv("MYSQL_PASSWORD") or os.getenv("DB_PASS") or "AccuPoint01!"
    db_name = os.getenv("PIXEL_DB") or os.getenv("DB_NAME") or "pixel"
    
    # Use mysqlconnector
    conn_url = f"mysql+mysqlconnector://{db_user}:{db_pass}@{db_host}/{db_name}"
    engine = create_engine(conn_url)
    
    # 1. Fetch Pixels with oplet_polling_active = 1
    pixels = []
    try:
        with engine.connect() as conn:
            if client_name:
                query = text("""
                    SELECT id, pixel_name, on_platform_url 
                    FROM pixel_sheets 
                    WHERE client_name = :cname AND on_platform_url IS NOT NULL
                """)
                result = conn.execute(query, {"cname": client_name})
            else:
                query = text("SELECT id, pixel_name, on_platform_url FROM pixel_sheets WHERE oplet_polling_active = 1 AND on_platform_url IS NOT NULL")
                result = conn.execute(query)
            pixels = result.fetchall()
    except Exception as e:
        logger.error(f"Failed to fetch pixels for polling: {e}")
        return

    if not pixels:
        logger.info(f"No pixels found for polling{' for ' + client_name if client_name else ''}.")
        return

    logger.info(f"Found {len(pixels)} pixels to poll.")

    # 2. Run Polling
    bot = OpletBot(headless=True)
    try:
        bot.login()
        
        for pixel in pixels:
            pid, pname, url = pixel
            logger.info(f"Processing {pname}...")
            try:
                bot.poll_oplet(pid, url)
            except Exception as pe:
                logger.error(f"Error polling {pname}: {pe}")
                traceback.print_exc()
                
    finally:
        bot.close()
        
    logger.info("OPLET Poller run complete.")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="AudienceLab OPLET Poller")
    parser.add_argument("--client", type=str, help="Specific client database name to poll")
    args = parser.parse_args()
    
    run_poller(client_name=args.client)
