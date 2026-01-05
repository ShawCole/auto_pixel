import os
import argparse
from automation import SimpleAudienceAutomation
from processor import SimpleAudienceProcessor
from database import SimpleAudienceDatabase
from dotenv import load_dotenv

load_dotenv()

def main():
    parser = argparse.ArgumentParser(description="Simple Audience Data Automation")
    parser.add_argument("--pixel", type=str, default=os.getenv("TARGET_PIXEL_NAME", "TruVestments_v3"), help="Pixel name to search for")
    parser.add_argument("--days", type=int, default=os.getenv("EXPORT_TIMEFRAME_DAYS", 7), help="Number of days to export (7, 30, 60, etc.)")
    parser.add_argument("--download-dir", type=str, default=os.getenv("DOWNLOAD_DIR", "./downloads"), help="Directory for downloads")
    
    args = parser.parse_args()

    # 0. Fetch Active Pixels
    print("--- Stage 0: Fetching Active Pixels ---")
    db_manager = SimpleAudienceDatabase()
    try:
        active_pixels = db_manager.get_active_pixels()
    except Exception as e:
        print(f"Failed to fetch active pixels: {e}")
        return

    if not active_pixels:
        print("No active pixels found to process.")
        return

    # 1. Automation Setup
    print("\n--- Stage 1: Selenium Automation ---")
    bot = SimpleAudienceAutomation(download_dir=args.download_dir)
    processor = SimpleAudienceProcessor()
    
    try:
        bot.login()
        bot.navigate_to_pixels()
        
        processed_usa_financial = False
        
        for pixel_info in active_pixels:
            client_db = pixel_info['client_name']
            pixel_search_name = pixel_info['pixel_name']
            
            # Handle USA_Financial redundancy
            if "USA_Financial" in client_db:
                if processed_usa_financial:
                    print(f"Skipping redundant financial pixel: {client_db}")
                    continue
                processed_usa_financial = True
            
            print(f"\n>> Processing Pixel: {pixel_search_name} (Database: {client_db})")
            
            try:
                # Download using client_db (the client_name shown on website)
                raw_file_path = bot.download_pixel_data(client_db, args.days)
                
                if not raw_file_path:
                    # Skip if failed or 0 records (handled in download_pixel_data)
                    continue
                
                # Process
                print(f"Processing data for {client_db}...")
                processed_events, visitors_csv = processor.process_csv(raw_file_path)
                
                # Upload and Sync
                print(f"Syncing data to database: {client_db}...")
                db_manager.upload_and_sync(processed_events, visitors_csv, client_db)
                
                print(f"Successfully synced {pixel_search_name}!")
                
            except Exception as e:
                print(f"Error processing {pixel_search_name}: {e}")
                # Continue to next pixel
    
    finally:
        bot.close()

    print("\nAll tasks completed!")

if __name__ == "__main__":
    main()
