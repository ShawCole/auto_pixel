#!/usr/bin/env python3
"""
One-time script to update client_website URLs for all existing pixels
by searching on app.simpleaudience.io and extracting the website URL.
"""

import mysql.connector
import time
import logging
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import TimeoutException, NoSuchElementException
import os
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('website_update.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

class WebsiteURLUpdater:
    def __init__(self):
        self.db_config = {
            'host': os.getenv('DB_HOST', '34.31.66.104'),
            'user': os.getenv('DB_USER', 'root'),
            'password': os.getenv('DB_PASS', 'AccuPoint01!'),
            'database': 'pixel'
        }
        
        # Initialize webdriver
        options = webdriver.ChromeOptions()
        options.add_argument('--start-maximized')
        options.add_argument('--disable-blink-features=AutomationControlled')
        options.add_experimental_option("excludeSwitches", ["enable-automation"])
        options.add_experimental_option('useAutomationExtension', False)
        
        # Set Chrome binary path for macOS
        options.binary_location = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
        
        self.driver = webdriver.Chrome(options=options)
        self.driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
        
        # Initialize wait
        self.wait = WebDriverWait(self.driver, 10)
        
    def get_all_pixels(self):
        """Get all pixels from the database that need website URL updates"""
        try:
            conn = mysql.connector.connect(**self.db_config)
            cursor = conn.cursor(dictionary=True)
            
            query = """
                SELECT id, client_name, client_website 
                FROM pixel_sheets 
                ORDER BY created_at DESC
            """
            
            cursor.execute(query)
            pixels = cursor.fetchall()
            
            cursor.close()
            conn.close()
            
            logger.info(f"Found {len(pixels)} total pixels in database")
            for pixel in pixels[:5]:  # Show first 5 pixels
                logger.info(f"Pixel: {pixel['client_name']} - Website: {pixel['client_website']}")
            
            # Filter pixels that need website URL updates
            pixels_needing_updates = [p for p in pixels if not p['client_website'] or p['client_website'] == '']
            logger.info(f"Found {len(pixels_needing_updates)} pixels that need website URL updates")
            return pixels_needing_updates
            
        except Exception as e:
            logger.error(f"Database error: {e}")
            return []
    
    def login_to_simpleaudience(self):
        """Login to app.simpleaudience.io using the same approach as audienceLab.ts"""
        try:
            logger.info("Navigating to SimpleAudience login page...")
            self.driver.get("https://app.simpleaudience.io/auth/sign-in")
            logger.info("✅ Reached login page")
            time.sleep(0.2)  # Wait for page load
            
            # Wait for username field
            logger.info("⏳ Waiting for username field...")
            username_field = self.wait.until(
                EC.presence_of_element_located((By.XPATH, "/html/body/div/div/form/div[1]/input"))
            )
            logger.info("✅ Username field found")
            time.sleep(0.1)
            
            # Enter credentials
            logger.info("🔐 Entering login credentials...")
            username_field.clear()
            time.sleep(0.1)
            username_field.send_keys(os.getenv('SIMPLEAUDIENCE_EMAIL', 'shaw@accupointsolutions.com'))
            logger.info("✅ Username entered")
            time.sleep(0.1)
            
            password_field = self.driver.find_element(By.XPATH, "/html/body/div/div/form/div[2]/input")
            password_field.clear()
            time.sleep(0.1)
            password_field.send_keys(os.getenv('SIMPLEAUDIENCE_PASSWORD', 'AccuPoint01!'))
            logger.info("✅ Password entered")
            time.sleep(0.2)
            
            # Click sign in button
            logger.info("🖱️ Clicking sign in button...")
            sign_in_button = self.driver.find_element(By.XPATH, "/html/body/div/div/form/button")
            sign_in_button.click()
            logger.info("✅ Sign in button clicked")
            time.sleep(0.2)
            
            # Wait for audience selection page
            logger.info("🎯 Waiting for audience selection page...")
            audience_option = self.wait.until(
                EC.presence_of_element_located((By.XPATH, "/html/body/div/div/div[2]/div[2]/div[2]/div/div/a/div"))
            )
            logger.info("✅ Audience selection page loaded")
            time.sleep(0.2)
            
            # Click simple|Audience option
            logger.info("🖱️ Clicking simple|Audience option...")
            audience_option.click()
            logger.info("✅ Audience option selected")
            time.sleep(0.4)
            
            logger.info("Successfully logged in to SimpleAudience")
            time.sleep(2)
            
        except Exception as e:
            logger.error(f"Login failed: {e}")
            raise
    
    def navigate_to_pixels(self):
        """Navigate to the pixels management page using the same approach as audienceLab.ts"""
        try:
            # Wait for dashboard to load
            logger.info("📊 Waiting for dashboard to load...")
            pixel_menu_item = self.wait.until(
                EC.element_to_be_clickable((By.XPATH, "/html/body/div[1]/div/div[1]/div/div[2]/div/div[2]/div[2]/div[2]/ul/li[1]/a"))
            )
            logger.info("✅ Dashboard loaded")
            time.sleep(0.2)
            
            # Click pixels menu item
            logger.info("🖱️ Clicking pixels menu item...")
            pixel_menu_item.click()
            logger.info("✅ Pixels section opened")
            time.sleep(0.6)  # Wait for pixels page to load
            
        except Exception as e:
            logger.error(f"Navigation to pixels failed: {e}")
            raise
    
    def search_and_extract_website(self, client_name):
        """Search for client name and extract website URL"""
        try:
            # Find and clear search input
            search_input = self.wait.until(
                EC.element_to_be_clickable((By.XPATH, "/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[1]/div/input"))
            )
            
            # Clear existing search
            search_input.clear()
            time.sleep(1)
            
            # Enter client name
            search_input.send_keys(client_name)
            search_input.send_keys(Keys.RETURN)
            
            # Wait for search results
            time.sleep(3)
            
            # Try to find the website URL in the first row
            try:
                website_cell = self.wait.until(
                    EC.presence_of_element_located((By.XPATH, "/html/body/div/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/table/tbody/tr[1]/td[2]"))
                )
                
                website_url = website_cell.text.strip()
                
                if website_url and website_url != "N/A" and website_url != "":
                    logger.info(f"Found website URL for {client_name}: {website_url}")
                    return website_url
                else:
                    logger.warning(f"No valid website URL found for {client_name}")
                    return None
                    
            except TimeoutException:
                logger.warning(f"No search results found for {client_name}")
                return None
                
        except Exception as e:
            logger.error(f"Error searching for {client_name}: {e}")
            return None
    
    def update_database(self, pixel_id, website_url):
        """Update the database with the found website URL"""
        try:
            conn = mysql.connector.connect(**self.db_config)
            cursor = conn.cursor()
            
            query = "UPDATE pixel_sheets SET client_website = %s WHERE id = %s"
            cursor.execute(query, (website_url, pixel_id))
            
            conn.commit()
            cursor.close()
            conn.close()
            
            logger.info(f"Updated pixel ID {pixel_id} with website URL: {website_url}")
            return True
            
        except Exception as e:
            logger.error(f"Database update failed for pixel ID {pixel_id}: {e}")
            return False
    
    def run_update(self):
        """Main method to run the website URL update process"""
        try:
            # Get all pixels that need updates
            pixels = self.get_all_pixels()
            
            if not pixels:
                logger.info("No pixels found that need website URL updates")
                return
            
            # Login to SimpleAudience
            self.login_to_simpleaudience()
            
            # Navigate to pixels page
            self.navigate_to_pixels()
            
            # Process each pixel
            updated_count = 0
            failed_count = 0
            
            for pixel in pixels:
                try:
                    logger.info(f"Processing pixel: {pixel['client_name']} (ID: {pixel['id']})")
                    
                    # Search and extract website URL
                    website_url = self.search_and_extract_website(pixel['client_name'])
                    
                    if website_url:
                        # Update database
                        if self.update_database(pixel['id'], website_url):
                            updated_count += 1
                        else:
                            failed_count += 1
                    else:
                        failed_count += 1
                        logger.warning(f"Could not find website URL for {pixel['client_name']}")
                    
                    # Small delay between searches
                    time.sleep(2)
                    
                except Exception as e:
                    logger.error(f"Error processing pixel {pixel['client_name']}: {e}")
                    failed_count += 1
                    continue
            
            logger.info(f"Update process completed. Updated: {updated_count}, Failed: {failed_count}")
            
        except Exception as e:
            logger.error(f"Update process failed: {e}")
        finally:
            # Keep browser open for manual inspection
            logger.info("Update process finished. Browser will remain open for 30 seconds...")
            time.sleep(30)
            self.driver.quit()

if __name__ == "__main__":
    updater = WebsiteURLUpdater()
    updater.run_update() 