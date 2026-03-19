#!/usr/bin/env python3
"""
Test the full pixel bandaid flow with a real pixel:
  login → check segment → create if needed → download CSV

Runs headed so you can watch. Screenshots at each step.
"""

import sys
import os
import logging

sys.path.insert(0, os.path.dirname(__file__))

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[logging.StreamHandler()]
)

from automation import SimpleAudienceAutomation

PIXEL_NAME = sys.argv[1] if len(sys.argv) > 1 else "accupoint_solutions_new_v4"
DAYS = int(sys.argv[2]) if len(sys.argv) > 2 else 2

def main():
    print(f"\n{'='*60}")
    print(f"  PIXEL BANDAID TEST — {PIXEL_NAME}")
    print(f"  Days filter: last {DAYS} days")
    print(f"{'='*60}\n")

    bot = SimpleAudienceAutomation(download_dir="./downloads", headless=False)

    try:
        # Step 1: Login
        print("\n--- STEP 1: LOGIN ---")
        bot.login()
        bot.save_screenshot("step1_login_done.png")
        print(f"  URL after login: {bot.driver.current_url}")

        # Step 2: Check if segment exists
        print(f"\n--- STEP 2: CHECK SEGMENT EXISTS for '{PIXEL_NAME}' ---")
        exists = bot.check_exists_in_segments(PIXEL_NAME)
        bot.save_screenshot("step2_segment_check.png")
        print(f"  Segment exists: {exists}")

        if not exists:
            # Step 3: Create segment
            print(f"\n--- STEP 3: CREATE SEGMENT ---")
            if DAYS > 4:
                days_list = [DAYS]
            else:
                days_list = [2, 3, 4]
            print(f"  Will try days: {days_list}")
            bot.create_segment(PIXEL_NAME, days_list=days_list)
            bot.save_screenshot("step3_segment_created.png")
            print("  Segment created.")
        else:
            print("  Skipping creation — segment already exists.")

        # Step 4: Download
        print(f"\n--- STEP 4: DOWNLOAD CSV ---")
        csv_path = bot.download_segment(PIXEL_NAME)
        bot.save_screenshot("step4_download_done.png")
        print(f"  Downloaded: {csv_path}")

        # Quick stats on the CSV
        if csv_path and os.path.exists(csv_path):
            with open(csv_path) as f:
                lines = f.readlines()
            print(f"  Rows: {len(lines) - 1} (excluding header)")
            print(f"  Columns: {len(lines[0].split(','))}")
            print(f"  File size: {os.path.getsize(csv_path):,} bytes")

        print(f"\n{'='*60}")
        print(f"  TEST COMPLETE — SUCCESS")
        print(f"{'='*60}\n")

    except Exception as e:
        print(f"\n{'='*60}")
        print(f"  TEST FAILED: {e}")
        print(f"{'='*60}\n")
        bot.save_screenshot("test_failure.png")
        import traceback
        traceback.print_exc()

    finally:
        bot.close()

if __name__ == "__main__":
    main()
