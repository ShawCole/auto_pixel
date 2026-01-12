import os
import time
import glob
import logging
import re
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager
from dotenv import load_dotenv

load_dotenv()

import tempfile

class SimpleAudienceAutomation:
    def __init__(self, download_dir="./downloads", headless=False):
        self.download_dir = os.path.abspath(download_dir)
        if not os.path.exists(self.download_dir):
            os.makedirs(self.download_dir)

        chrome_options = Options()
        if headless:
             chrome_options.add_argument("--headless")
        chrome_options.add_argument("--window-size=1920,1080")
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        chrome_options.add_argument("--disable-gpu")
        
        # KEY FIX: Force a unique temporary directory for EVERY run.
        # This bypasses any lock files left by previous zombie processes.
        self.user_data_dir = tempfile.mkdtemp()
        chrome_options.add_argument(f"--user-data-dir={self.user_data_dir}")
        
        prefs = {"download.default_directory": self.download_dir}
        chrome_options.add_experimental_option("prefs", prefs)
        
        self.driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=chrome_options)
        self.wait = WebDriverWait(self.driver, 45)

    def login(self):
        print("Logging in...")
        try:
            self.driver.get("https://app.simpleaudience.io/auth/sign-in")
            
            email_field = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder='your@email.com']")))
            email = os.getenv("SIMPLE_AUDIENCE_EMAIL") or os.getenv("AUDLAB_USERNAME")
            email_field.send_keys(email)
            
            password_field = self.driver.find_element(By.CSS_SELECTOR, "input[type='password']")
            password = os.getenv("SIMPLE_AUDIENCE_PASSWORD") or os.getenv("AUDLAB_PASSWORD")
            password_field.send_keys(password)
            password_field.send_keys(Keys.ENTER)
            
            # Wait for session to establish and check for workspace selection
            print("Waiting for workspace selection...")
            self._wait_for_overlays()
            time.sleep(3) # Give it a moment to render the grid
            
            try:
                # User provided specific XPath for the workspace card
                workspace_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div/a"
                
                # Check if we are already on dashboard (sidebar exists)
                if self.driver.find_elements(By.XPATH, "//aside"):
                    print("Already on dashboard.")
                else:
                    # Try finding the specific workspace card
                    try:
                        workspace_link = self.wait.until(EC.element_to_be_clickable((By.XPATH, workspace_xpath)))
                        print("Found AccuPoint Solutions workspace (via explicit XPath). Clicking...")
                        workspace_link.click()
                    except:
                        # Fallback: Search by text key
                        print("Explicit XPath failed, looking for 'AccuPoint Solutions' text...")
                        workspace_link = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//a[contains(., 'AccuPoint Solutions')] | //div[contains(text(), 'AccuPoint Solutions')]")))
                        workspace_link.click()
                        
                    # Wait for dashboard to load after click
                    print("Waiting for dashboard to load...")
                    self.wait.until(EC.presence_of_element_located((By.XPATH, "//aside | //a[contains(@href, '/pixel')]")))
                    
            except Exception as e:
                print(f"Workspace selection warning: {e}. Checking if we are just logged in anyway...")
                if self.driver.find_elements(By.XPATH, "//aside"):
                     print("Recovered: Dashboard is visible.")
                else:
                     raise e
            
            print("Login flow complete.")
        except Exception as e:
            self.save_screenshot("login_failure.png")
            print(f"Login failed: {e}")
            raise

    def navigate_to_page(self, page_name):
        """Navigates to a specific page via the sidebar."""
        logging.info(f"Navigating to {page_name}...")
        try:
            # Try finding link by text first
            link = self.wait.until(EC.element_to_be_clickable((By.XPATH, f"//a[contains(., '{page_name}')]")))
            link.click()
        except:
            # Fallback to direct URL (Workspace Specific)
            print(f"Sidebar click failed for {page_name}, trying callback URL...")
            if page_name.lower() == "studio":
                self.driver.get("https://app.simpleaudience.io/home/accupoint-solutions/studio")
            elif page_name.lower() == "segments":
                self.driver.get("https://app.simpleaudience.io/home/accupoint-solutions/segment")
        
        self._wait_for_overlays()
        time.sleep(3)

    def check_exists_in_segments(self, pixel_name):
        """Checks if a segment for this pixel already exists using specific table cell verification."""
        logging.info(f"Checking for existing segment for {pixel_name}...")
    def check_exists_in_segments(self, pixel_name, expected_name_pattern=None):
        """Checks if a segment for this pixel already exists using specific table cell verification."""
        logging.info(f"Checking for existing segment for {pixel_name}...")
        
        # Optimize: Go directly to search URL
        url = f"https://app.simpleaudience.io/home/accupoint-solutions/segment?query={pixel_name}"
        self.driver.get(url)
        self._wait_for_overlays()
        time.sleep(3)
        
        try:
            # Check specific table cell for results
            cell_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div[2]/div[1]/table/tbody/tr[1]/td[1]"
            try:
                 first_cell = self.driver.find_element(By.XPATH, cell_xpath)
                 cell_text = first_cell.text.lower()
                 
                 # If we have a specific pattern (e.g. "last 45 days"), check for it
                 if expected_name_pattern:
                     if expected_name_pattern.lower() in cell_text:
                         logging.info(f"Found specific segment '{expected_name_pattern}' for {pixel_name}.")
                         return True
                     else:
                         logging.info(f"Existing segment found but does not match pattern '{expected_name_pattern}'.")
                         return False
                 
                 # Default loose check
                 if pixel_name.lower() in cell_text:
                     logging.info(f"Found existing segment for {pixel_name} in table.")
                     return True
            except:
                 pass # Cell not found or empty
            
        except Exception as e:
            logging.warning(f"Error checking segments: {e}")
        
        return False

    def create_segment(self, pixel_name, days_list=[2, 3, 4]):
        """Creates a new segment in Studio with filter for last X days."""
        logging.info(f"Creating new segment for {pixel_name}...")
        self.navigate_to_page("Studio")
        self._wait_for_overlays()
        time.sleep(2)
        
        # 1. Select Dataset
        try:
            # Try generic valid selector first because absolute paths are brittle and slow if they timeout
            dataset_search = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//input[@placeholder='Search datasets...'] | //input[contains(@class, 'border-input')]")))
        except:
            logging.info("Generic search input not found, trying absolute fallback...")
            dataset_search = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[3]/div[1]/div[2]/div/input")))

        dataset_search.click()
        dataset_search.send_keys(pixel_name)
        time.sleep(2)
        
        # Click first result
        try:
            first_result = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[1]/div[3]/div/div")))
            first_result.click()
        except Exception as e:
            logging.error(f"Dataset for '{pixel_name}' not found in search results. Skipping.")
            raise Exception(f"Dataset not found for '{pixel_name}'")
        
        time.sleep(2)
        self._wait_for_overlays()

        # 2. Setup Filter (Once)
        logging.info(f"Setting up filter logic...")
        
        # Click "Add Filter"
        add_filter_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/button")))
        add_filter_btn.click()
        time.sleep(1)

        # Select Field: [Pixel] Event Timestamp
        field_dropdown = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div/div/div/div/div")))
        field_dropdown.click()
        time.sleep(1)
        
        # Find and click "[Pixel] Event Timestamp" option
        try:
            timestamp_opt = self.driver.find_element(By.XPATH, "//div[contains(text(), '[Pixel] Event Timestamp')]")
            timestamp_opt.click()
        except:
            pass 

        # Operator: Select "Is Within Last"
        operator_btn_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div/div/button[1]"
        try:
            operator_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, operator_btn_xpath)))
            operator_btn.click()
            time.sleep(1)
            
            try:
                is_within_last_opt = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[4]/div/div[1]/div[11]")))
                is_within_last_opt.click()
            except:
                logging.warning("User provided operator option XPath failed, searching by text...")
                is_within_last_opt = self.driver.find_element(By.XPATH, "//div[contains(text(), 'Is Within Last')]")
                is_within_last_opt.click()

            time.sleep(1)
        except Exception as e:
            logging.warning(f"Operator selection issue: {e}. Trying to proceed if default is correct...")

        # Unit: "Days"
        unit_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div/div/div[2]/button")))
        self.driver.execute_script("arguments[0].click();", unit_btn)
        time.sleep(1)
        
        try:
            days_opt = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[4]/div/div/div[3]")))
            days_opt.click()
        except:
             try:
                 days_opt = self.driver.find_element(By.XPATH, "//div[contains(text(), 'Days')]")
                 days_opt.click()
             except:
                 pass

        # 3. Iterate days
        final_days = days_list[0]
        for days in days_list:
            logging.info(f"Trying filter: Last {days} Days...")
            final_days = days
            
            # Value Input matches the one for "Is Within Last"
            value_input = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div/div/div[2]/input")))
            value_input.clear()
            value_input.send_keys(str(days))

            # Click "Select All Visible" (Wait for calculation)
            select_all_btn = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[3]/div[2]/div[2]/button[1]")))
            
            # Scroll into view to avoid header/footer overlap
            self.driver.execute_script("arguments[0].scrollIntoView(true);", select_all_btn)
            time.sleep(1)
            
            # JS Click to force it through any overlay
            self.driver.execute_script("arguments[0].click();", select_all_btn)
            time.sleep(2)

            # Check Count with a small retry buffer for "0 records"
            # Sometimes the UI updates from "..." to "0" then to "123" quickly
            count_elem = self.driver.find_element(By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[2]/div[1]/div[2]/div[3]/p[1]")
            count_text = count_elem.text
            
            # Helper to parse count
            def parse_count(text):
                try:
                    return int(re.sub(r'\D', '', text))
                except:
                    return 0

            count_val = parse_count(count_text)
            
            if count_val == 0:
                logging.info(f"Count is 0, waiting 1s to double check...")
                time.sleep(1)
                # Re-fetch element text
                count_elem = self.driver.find_element(By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[2]/div[1]/div[2]/div[3]/p[1]")
                count_text = count_elem.text
                count_val = parse_count(count_text)

            logging.info(f"Row count for {days} days: {count_text}")

            if count_val > 0:
                break
            
            # If 0, loop continues to next day count

        # Check if we actually found data
        # We need to re-verify the last count_text to be sure, or rely on the break.
        # Since we break on success, if we are here and days==4, we might have failed.
        # Let's check the last count_val logic again or use a flag.
        
        # Simpler: If we finished the loop and count is still 0 (checking the element again is safest)
        count_elem = self.driver.find_element(By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[2]/div[1]/div[2]/div[3]/p[1]")
        count_text = count_elem.text
        if "0 records" in count_text or count_text.strip() == "0":
             logging.warning(f"No data found for {pixel_name} even after {final_days} days.")
             raise Exception(f"No data found for {pixel_name} even after {final_days} days.")

        # 3. Save Segment
        logging.info("Saving Segment...")
        save_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[1]/button")))
        save_btn.click()
        time.sleep(1)
        
        # Enter Name: Last {final_days} Days - {pixel_name}
        name_input = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[5]/form/div[1]/input")))
        name_input.clear()
        name_input.send_keys(f"Last {final_days} Days - {pixel_name}")
        
        # Click Save
        final_save_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[5]/form/div[3]/button[2]")))
        final_save_btn.click()
        self._wait_for_overlays()
        time.sleep(3)

    def download_segment(self, pixel_name):
        """Downloads the export from the Segments page."""
        logging.info(f"Downloading segment for {pixel_name}...")
        
        # Use query param to speed up search
        url = f"https://app.simpleaudience.io/home/accupoint-solutions/segment?query={pixel_name}"
        self.driver.get(url)
        self._wait_for_overlays()
        time.sleep(3) # Wait for table to filter
        
        # Click "View in Studio"
        # User Provided XPath: /html/body/div[2]/div/div[2]/div[2]/div[2]/div/div[2]/div/table/tbody/tr/td[6]/div/a
        try:
             view_studio_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div[2]/div/table/tbody/tr/td[6]/div/a")))
             view_studio_btn.click()
        except:
             logging.error("Could not find 'View in Studio' button via strict XPath, trying loose fallback...")
             view_studio_btn = self.driver.find_element(By.XPATH, "//a[contains(., 'View in Studio')]")
             view_studio_btn.click()

        self._wait_for_overlays()
        time.sleep(3)
        
        # Scroll to bottom and click Download Export
        # User Provided XPath: /html/body/div[2]/div/div[2]/div[2]/div[2]/div[6]/div[2]/div[2]/div[2]/button
        
        # Wait for button to be present (it might be off screen)
        download_btn_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[6]/div[2]/div[2]/div[2]/button"
        try:
            download_btn = self.wait.until(EC.presence_of_element_located((By.XPATH, download_btn_xpath)))
            
            # Scroll it into view
            self.driver.execute_script("arguments[0].scrollIntoView(true);", download_btn)
            time.sleep(1)
            
            # Click it
            self.driver.execute_script("arguments[0].click();", download_btn)
        except:
             logging.error("Exact download button XPath failed, searching by text...")
             download_btn = self.driver.find_element(By.XPATH, "//button[contains(., 'Download Export')]")
             self.driver.execute_script("arguments[0].scrollIntoView(true);", download_btn)
             download_btn.click()
        
        # Wait for file
        return self._wait_for_download(pixel_name)

    def download_pixel_data(self, pixel_name, days=1):
        """Downloads the pixel data for the given pixel name and days."""
        # Check specifically for "Last X Days" if days > 4 (Historical/Custom)
        expected_pattern = None
        if days > 4:
            expected_pattern = f"Last {days} Days"
            
        if self.check_exists_in_segments(pixel_name, expected_name_pattern=expected_pattern):
            return self.download_segment(pixel_name)
        else:
            # Handle historical pull or normal daily loop
            if days > 4:
                days_list = [days]
            else:
                days_list = [2, 3, 4]
            self.create_segment(pixel_name, days_list=days_list)
            return self.download_segment(pixel_name)
    
    def _wait_for_overlays(self):
        try:
            self.wait.until(EC.invisibility_of_element_located((By.CSS_SELECTOR, "div[class*='bg-black/80']")))
            self.wait.until(EC.invisibility_of_element_located((By.CSS_SELECTOR, "div[class*='fixed inset-0 z-50']")))
            time.sleep(0.5)
        except:
            pass

    def _wait_for_download(self, pixel_name):
        timeout = 60
        start_time = time.time()
        while time.time() - start_time < timeout:
            files = glob.glob(os.path.join(self.download_dir, "*.csv"))
            if files:
                newest_file = max(files, key=os.path.getctime)
                if not newest_file.endswith(".crdownload"):
                    if time.time() - os.path.getctime(newest_file) < 60:
                        print(f"Downloaded: {newest_file}")
                        return newest_file
            time.sleep(3)
        raise Exception("Download timed out")

    def save_screenshot(self, filename):
        path = os.path.join(self.download_dir, filename)
        self.driver.save_screenshot(path)
        print(f"Screenshot saved to {path}")

    def close(self):
        self.driver.quit()
        # Cleanup temp user data dir to save space
        try:
            import shutil
            shutil.rmtree(self.user_data_dir, ignore_errors=True)
        except:
            pass
