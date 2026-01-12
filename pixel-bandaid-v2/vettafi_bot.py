
import os
import time
import json
import logging
import re
from selenium import webdriver
from selenium.webdriver.chrome.service import Service as ChromeService
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager
import tempfile
from dotenv import load_dotenv

load_dotenv()

# Configure Logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Credentials for VettaFi
VETTAFI_EMAIL = "mas@strategysimple.com"
VETTAFI_PASSWORD = "AccuPoint01!"
LOGIN_URL = "https://build.audiencelab.io/home/"

class VettafiBot:
    def __init__(self, headless=True, download_dir="./downloads"):
        self.download_dir = os.path.abspath(download_dir)
        if not os.path.exists(self.download_dir):
            os.makedirs(self.download_dir)

        self.options = Options()
        if headless:
            self.options.add_argument("--headless=new")
        self.options.add_argument("--window-size=1920,1080")
        self.options.add_argument("--no-sandbox")
        self.options.add_argument("--disable-dev-shm-usage")
        self.options.add_argument("--disable-gpu")
        
        # Enable Performance Logging for API capture
        self.options.set_capability("goog:loggingPrefs", {"performance": "ALL"})
        
        self.user_data_dir = tempfile.mkdtemp()
        self.options.add_argument(f"--user-data-dir={self.user_data_dir}")

        prefs = {"download.default_directory": self.download_dir}
        self.options.add_experimental_option("prefs", prefs)

        self.driver = webdriver.Chrome(service=ChromeService(ChromeDriverManager().install()), options=self.options)
        self.wait = WebDriverWait(self.driver, 45)

    def login(self):
        logger.info(f"Navigating to {LOGIN_URL}...")
        self.driver.get(LOGIN_URL)
        
        try:
            # Check if we need to enter email
            logger.info("Entering Email...")
            email_inp = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='email']")))
            email_inp.clear()
            email_inp.send_keys(VETTAFI_EMAIL)
            email_inp.send_keys(Keys.ENTER)
            
            # Enter Password
            logger.info("Entering Password...")
            pass_inp = self.wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='password']")))
            pass_inp.clear()
            pass_inp.send_keys(VETTAFI_PASSWORD)
            pass_inp.send_keys(Keys.ENTER)
            
            time.sleep(5)
            logger.info("Login submitted.")
            
            # Check for account selection screen (common in these portals)
            if "home" in self.driver.current_url and not any(tag in self.driver.page_source for tag in ["aside", "nav"]):
                 logger.info("Checking for account/workspace selection...")
                 self._select_workspace()
                 
            logger.info("Login flow complete.")
        except Exception as e:
            self.save_screenshot("vettafi_login_fail.png")
            logger.error(f"Login failed: {e}")
            raise

    def _select_workspace(self):
        # Specific workspace selection if applicable. VettaFi usually has a direct path.
        # But if it shows a grid, click the VettaFi card.
        try:
            time.sleep(3)
            # Try to find a card containing 'VettaFi'
            target = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'VettaFi')]")))
            logger.info("Found VettaFi workspace. Clicking...")
            target.click()
            time.sleep(5)
        except Exception as e:
            logger.info("No specific VettaFi workspace card found or already selected.")

    def create_16_day_segment(self, pixel_name="VettaFi"):
        logger.info(f"Creating 16-day segment for {pixel_name}...")
        
        # Navigate to Studio
        # Pattern from automation.py: finding the 'Studio' or 'segments' link
        try:
            studio_link = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//a[contains(., 'Studio')]")))
            studio_link.click()
        except:
            # Fallback direct URL (Workspace name seems to be 'vettafi' in path usually)
            current_url = self.driver.current_url
            if "/home/" in current_url:
                workspace = current_url.split("/home/")[1].split("/")[0]
                self.driver.get(f"https://build.audiencelab.io/home/{workspace}/studio")
        
        time.sleep(5)
        
        # Dataset Selection
        logger.info("Searching for dataset...")
        dataset_search = self.wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[placeholder*='Search']")))
        dataset_search.click()
        dataset_search.send_keys(pixel_name)
        time.sleep(2)
        
        # Click first result
        first_result = self.wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, ".cursor-pointer, .grid-item")))
        first_result.click()
        time.sleep(3)

        # Apply Filter: [Pixel] Event Timestamp Within Last 16 Days
        logger.info("Applying 16-day filter...")
        add_filter_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Add Filter')]")))
        add_filter_btn.click()
        time.sleep(1)

        # Field Select
        field_dropdown = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//div[contains(@class, 'select')] | //button[contains(@class, 'select')]")))
        field_dropdown.click()
        time.sleep(1)
        
        timestamp_opt = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'Event Timestamp')]")))
        timestamp_opt.click()
        time.sleep(1)

        # Operator: Within Last
        # This part is UI-specific. I'll use text-based search.
        operator_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(@class, 'operator')] | //div[contains(@class, 'operator')]")))
        operator_btn.click()
        time.sleep(1)
        
        last_opt = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'Is Within Last')]")))
        last_opt.click()
        time.sleep(1)

        # Value: 16
        val_input = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='number'] | input[placeholder='Value']")))
        val_input.clear()
        val_input.send_keys("16")
        time.sleep(1)

        # Unit: Days (if not default)
        try:
            unit_btn = self.driver.find_element(By.XPATH, "//button[contains(., 'Hours') or contains(., 'Days')]")
            if "Days" not in unit_btn.text:
                unit_btn.click()
                days_opt = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//*[text()='Days']")))
                days_opt.click()
        except:
            pass

        # Select All
        logger.info("Selecting all records...")
        select_all = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Select All')]")))
        select_all.click()
        time.sleep(3)

        # Save Segment
        logger.info("Saving segment...")
        save_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Save')]")))
        save_btn.click()
        time.sleep(1)

        name_input = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder*='Segment Name']")))
        name_input.clear()
        segment_name = f"Last 16 Days - {pixel_name}"
        name_input.send_keys(segment_name)
        
        confirm_save = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[@type='submit' and contains(., 'Save')]")))
        confirm_save.click()
        time.sleep(5)
        logger.info(f"Segment '{segment_name}' saved.")
        return segment_name

    def discover_api(self, segment_name):
        logger.info(f"Discovering API for {segment_name}...")
        # Navigate to Segments page
        try:
            segments_link = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//a[contains(., 'Segments')]")))
            segments_link.click()
        except:
            current_url = self.driver.current_url
            workspace = current_url.split("/home/")[1].split("/")[0]
            self.driver.get(f"https://build.audiencelab.io/home/{workspace}/segment")

        time.sleep(5)
        
        # Search for segment
        search_inp = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder*='Search']")))
        search_inp.send_keys(segment_name)
        search_inp.send_keys(Keys.ENTER)
        time.sleep(3)

        # Open in Studio (eyeball icon)
        studio_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, f"//tr[contains(., '{segment_name}')]//a[contains(@href, 'studio')]")))
        studio_btn.click()
        time.sleep(5)

        # Click Show API
        show_api_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Show API')]")))
        show_api_btn.click()
        time.sleep(5)

        # Capture from logs
        segment_api_url = None
        logs = self.driver.get_log("performance")
        for entry in logs:
            try:
                message = json.loads(entry["message"])["message"]
                if message["method"] == "Network.requestWillBeSent":
                    url = message["params"]["request"]["url"]
                    if "api.audiencelab.io/segments" in url and ("?" in url or "page=" in url):
                        segment_api_url = url
                        break
            except:
                pass
        
        if segment_api_url:
            logger.info(f"Captured API: {segment_api_url}")
            return segment_api_url
        else:
            logger.warning("Failed to capture API URL from logs.")
            return None

    def save_screenshot(self, filename):
        path = os.path.join(self.download_dir, filename)
        self.driver.save_screenshot(path)
        logger.info(f"Screenshot saved: {path}")

    def close(self):
        self.driver.quit()

if __name__ == "__main__":
    bot = VettafiBot(headless=False)
    try:
        bot.login()
        seg_name = bot.create_16_day_segment("VettaFi")
        api_url = bot.discover_api(seg_name)
        if api_url:
            print(f"RESULT:SUCCESS API={api_url}")
        else:
            print("RESULT:FAILED API_NOT_FOUND")
    except Exception as e:
        print(f"RESULT:ERROR MSG={str(e)}")
    finally:
        bot.close()
