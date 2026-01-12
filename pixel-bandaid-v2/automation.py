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

    def check_exists_in_segments(self, pixel_name, expected_name_pattern=None):
        """Checks if a segment for this pixel already exists and returns the Studio URL if found."""
        logging.info(f"Checking for existing segment for {pixel_name}...")
        
        logging.info(f"Checking for existing segment for {pixel_name}...")
        
        # Use targeted search query if pattern is provided
        search_query = expected_name_pattern if expected_name_pattern else pixel_name
        url = f"https://app.simpleaudience.io/home/accupoint-solutions/segment?query={search_query}"
        
        self.driver.get(url)
        self._wait_for_overlays()
        time.sleep(3)
        
        try:
            # Check specific table cell for results
            tbody = self.driver.find_element(By.XPATH, "//table/tbody")
            rows = tbody.find_elements(By.TAG_NAME, "tr")
            
            for row in rows:
                cell_text = row.find_element(By.XPATH, "./td[1]").text.lower()
                
                # Check for the base pixel name and optionally the expected pattern
                if pixel_name.lower() in cell_text:
                    if expected_name_pattern:
                        if expected_name_pattern.lower() not in cell_text:
                            continue 
                    
                    logging.info(f"Found existing segment: '{cell_text}'")
                    # Capture the "View in Studio" link (td[6] -> div -> a)
                    try:
                        view_studio_btn = row.find_element(By.XPATH, ".//td[6]//a")
                        studio_url = view_studio_btn.get_attribute("href")
                        logging.info(f"Found existing segment URL: {studio_url}")
                        return studio_url
                    except:
                        return True # Found but couldn't get URL
        except Exception as e:
            logging.warning(f"Error checking segments table: {e}")
        
        return False

    def create_segment(self, pixel_name, days_list=[3, 4, 5]):
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

        # 2b. Add Contact Filters (UUID and First Name is not empty)
        logging.info("Adding Contact filters (UUID and First Name)...")
        
        contact_filters = [
            {"index": 2, "name": "[Contact] UUID"},
            {"index": 3, "name": "[Contact] First Name"}
        ]

        for filt in contact_filters:
            idx = filt["index"]
            field_name = filt["name"]
            
            # Click "+ Add Rule"
            try:
                add_rule_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[3]/button[1]"
                add_rule_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, add_rule_xpath)))
                self.driver.execute_script("arguments[0].click();", add_rule_btn)
                time.sleep(1)
            except Exception as e:
                logging.warning(f"Failed to click 'Add Rule': {e}")
                continue

            # Select the field (Dropdown)
            try:
                dropdown_xpath = f"/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div[{idx}]/div/div/div/div"
                dropdown = self.wait.until(EC.element_to_be_clickable((By.XPATH, dropdown_xpath)))
                dropdown.click()
                time.sleep(1)
                
                # Find the option by text and scroll it into view
                try:
                    option_xpath = f"//div[contains(@id, 'listbox')]//div[contains(text(), '{field_name}')]"
                    option_el = self.wait.until(EC.presence_of_element_located((By.XPATH, option_xpath)))
                    self.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", option_el)
                    time.sleep(1)
                    option_el.click()
                except Exception as e:
                    logging.warning(f"Could not click {field_name} by text search: {e}. Trying absolute index fallback...")
                    if field_name == "[Contact] UUID":
                        # User's provided index 83 for UUID
                        abs_opt = self.driver.find_element(By.XPATH, f"{dropdown_xpath}[2]/div/div[83]/div")
                        self.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", abs_opt)
                        time.sleep(1)
                        abs_opt.click()
                
                time.sleep(1)
                
                # Operator: Select "is not empty"
                operator_xpath = f"/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div[{idx}]/div/button[1]"
                operator_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, operator_xpath)))
                operator_btn.click()
                time.sleep(1)
                
                # Select "is not empty" option
                try:
                    is_not_empty_xpath = "/html/body/div[4]/div/div[1]/div[8]"
                    is_not_empty_opt = self.wait.until(EC.element_to_be_clickable((By.XPATH, is_not_empty_xpath)))
                    is_not_empty_opt.click()
                except:
                    is_not_empty_opt = self.driver.find_element(By.XPATH, "//div[contains(text(), 'is not empty')]")
                    is_not_empty_opt.click()
                time.sleep(1)
            except Exception as e:
                logging.error(f"Failed to set filter for {field_name}: {e}")

        # 3. Iterate days
        final_days = days_list[0]
        for days in days_list:
            logging.info(f"Trying filter: Last {days} Days...")
            final_days = days
            
            # Value Input matches the one for "Is Within Last" on first rule (idx=1)
            input_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div[1]/div/div[2]/input"
            value_input = self.wait.until(EC.presence_of_element_located((By.XPATH, input_xpath)))
            
            # --- KEYBOARD SEQUENCE FIX ---
            logging.info(f"Setting filter value to {days} using Keyboard Sequence...")
            value_input.click() # Ensure focus
            time.sleep(0.5)
            
            # Type value (if "1" is default, becomes "12" or "1x")
            value_input.send_keys(str(days))
            time.sleep(0.5)
            
            # Move left and backspace to delete the leading default "1"
            # This specifically targets the "12" bug for single digits
            if len(str(days)) == 1:
                logging.info("Applying Backspace to leading character...")
                value_input.send_keys(Keys.ARROW_LEFT)
                time.sleep(0.2)
                value_input.send_keys(Keys.BACKSPACE)
                time.sleep(0.2)
                # Ensure we are back to the right
                value_input.send_keys(Keys.ARROW_RIGHT)
            
            # Click out to a neutral element (specifically NOT a blur event, but clicking something else)
            try:
                # Click the "2. Build Filters" header
                header = self.driver.find_element(By.XPATH, "//h2[contains(text(), '2. Build Filters')]")
                header.click()
            except:
                # Fallback: Click the breadcrumb or body
                try:
                    self.driver.find_element(By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[1]/div[1]").click()
                except:
                    pass
            
            time.sleep(1)
            
            # VERIFICATION (Go line by line... determine exactly how to best ensure)
            verified_val = value_input.get_attribute("value")
            logging.info(f"CHECK: Filter value is now '{verified_val}'")
            if verified_val != str(days):
                logging.warning(f"VALUE ERROR: Expected {days}, got {verified_val}. Retrying click-out...")
                self.driver.execute_script("arguments[0].blur();", value_input)
                self.driver.find_element(By.TAG_NAME, "body").click()
                time.sleep(1)

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

    def capture_segment_metadata(self, pixel_name, existing_url=None, days=3):
        """
        Navigates to the segment's Studio page, captures row count, Oplet, and the segment's platform URL,
        then triggers the download.
        Returns a tuple: (downloaded_file_path, metadata_dict)
        """
        logging.info(f"Capturing metadata and downloading segment for {pixel_name}...")
        
        # 1. Access Studio page (via Search or Direct URL)
        if existing_url:
            logging.info(f"Fast Path: Navigating directly to {existing_url}")
            self.driver.get(existing_url)
            self._wait_for_overlays()
            time.sleep(5)
            platform_url = existing_url
        else:
            search_query = f"Last {days} Days - {pixel_name}"
            url = f"https://app.simpleaudience.io/home/accupoint-solutions/segment?query={search_query}"
            self.driver.get(url)
            self._wait_for_overlays()
            time.sleep(5) # Give time for table to filter and load

        metadata = {
            "row_count": "0",
            "oplet": "No Resolutions Found",
            "on_platform_segment_url": None,
            "on_platform_url": None,
            "segment_name": None
        }
        downloaded_file_path = None

        if not existing_url:
            try:
                # Locate the specific row for our query
                target_row_xpath = f"//table/tbody/tr[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '{search_query.lower()}')]"
                target_row = self.wait.until(EC.presence_of_element_located((By.XPATH, target_row_xpath)))
                
                # Capture Segment Name
                try:
                    segment_name_el = target_row.find_element(By.XPATH, "./td[1]")
                    metadata["segment_name"] = segment_name_el.text.strip()
                    logging.info(f"Captured segment_name: {metadata['segment_name']}")
                except Exception as e:
                    logging.warning(f"Failed to capture segment_name: {e}")

                # Capture the link from "View in Studio" button
                view_studio_link = target_row.find_element(By.XPATH, ".//td[6]//a")
                platform_url = view_studio_link.get_attribute("href")
                metadata["on_platform_segment_url"] = platform_url
                logging.info(f"Captured on_platform_segment_url: {platform_url}")
                
                # Click it to enter Studio
                view_studio_link.click()
                self._wait_for_overlays()
                time.sleep(8) # Wait for Studio to fully load data
            except Exception as e:
                logging.error(f"Failed to find or enter segment Studio for {pixel_name}: {e}")
                raise
        
        # --- Common Logic (Common to both paths once in Studio) ---
        metadata["on_platform_segment_url"] = platform_url
        metadata["on_platform_url"] = platform_url

        try:
            # --- FAST PATH: DERIVE ID & API ---
            try:
                match = re.search(r"segment=([a-f0-9-]{36})", platform_url, re.I)
                if match:
                    segment_id = match.group(1)
                    metadata["segment_id"] = segment_id
                    metadata["segment_api"] = f"https://api.audiencelab.io/segments/{segment_id}?page=1&page_size=50"
                    logging.info(f"Derived Fast Path - segment_id: {segment_id}, segment_api: {metadata['segment_api']}")
            except Exception as e:
                logging.warning(f"Failed to derive Fast Path metadata: {e}")

            # Capture Row Count from Studio
            try:
                row_count_elem = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[2]/div[1]/div[2]/div[3]/p[1]")))
                metadata["row_count"] = row_count_elem.text
                logging.info(f"Segment Row Count: {metadata['row_count']}")
            except Exception as e:
                logging.warning(f"Could not capture row count from Studio page: {e}")

            # Capture Oplet (Last Event Timestamp)
            try:
                oplet_elem = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[5]/div[2]/div[1]/div/table/tbody/tr[1]/td[3]")))
                metadata["oplet"] = oplet_elem.text
                logging.info(f"Segment Oplet (Timestamp): {metadata['oplet']}")
            except Exception as e:
                logging.warning(f"Could not capture Oplet from Studio page table: {e}")

            # Download CSV
            download_btn_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[6]/div[2]/div[2]/div[2]/button"
            download_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, download_btn_xpath)))
            self.driver.execute_script("arguments[0].scrollIntoView(true);", download_btn)
            time.sleep(1)
            self.driver.execute_script("arguments[0].click();", download_btn)
            
            downloaded_file_path = self._wait_for_download(pixel_name)
            
        except Exception as e:
            logging.error(f"Failed to download/capture metadata for {pixel_name}: {e}")
            self.save_screenshot(f"metadata_fail_{pixel_name}.png")
            raise

        return downloaded_file_path, metadata

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
