#!/usr/bin/env python3
"""Quick test: login only, take screenshot, verify dashboard loads."""

import sys
import os
sys.path.insert(0, os.path.dirname(__file__))

from automation import SimpleAudienceAutomation

def test_login():
    bot = SimpleAudienceAutomation(download_dir="./downloads", headless=False)
    try:
        bot.login()
        print("\n=== LOGIN SUCCESS ===")
        print(f"Current URL: {bot.driver.current_url}")

        # Take screenshot of dashboard
        bot.save_screenshot("test_login_success.png")

        # Check what's on the page
        title = bot.driver.title
        print(f"Page title: {title}")

        # Look for sidebar links to confirm we're on the dashboard
        links = bot.driver.find_elements("xpath", "//aside//a")
        print(f"\nSidebar links found: {len(links)}")
        for link in links[:10]:
            href = link.get_attribute("href") or ""
            text = link.text.strip()
            if text:
                print(f"  - {text}: {href}")

        input("\nPress Enter to close browser...")
    except Exception as e:
        print(f"\n=== LOGIN FAILED ===\n{e}")
        bot.save_screenshot("test_login_fail.png")
    finally:
        bot.close()

if __name__ == "__main__":
    test_login()
