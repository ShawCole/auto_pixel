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
from selenium.webdriver.common.action_chains import ActionChains
from webdriver_manager.chrome import ChromeDriverManager
from dotenv import load_dotenv

load_dotenv()

import tempfile

# Base URL — all navigation uses this
BASE_URL = "https://build.audiencelab.io"
WORKSPACE_PATH = "/home/accupoint-solutions"

# Impersonation credentials
LOGIN_EMAIL = os.getenv("AUDIENCE_LAB_EMAIL", "shaw@strategysimple.com")
LOGIN_PASSWORD = os.getenv("AUDIENCE_LAB_PASSWORD", "Escesc100$$!")
IMPERSONATE_TARGET = os.getenv("SIMPLE_AUDIENCE_IMPERSONATE", "mas@accupointsolutions.com")


class SimpleAudienceAutomation:
    def __init__(self, download_dir="./downloads", headless=False):
        self.download_dir = os.path.abspath(download_dir)
        if not os.path.exists(self.download_dir):
            os.makedirs(self.download_dir)

        chrome_options = Options()
        if headless:
             chrome_options.add_argument("--headless=new")
        chrome_options.add_argument("--window-size=2560,1440")
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        chrome_options.add_argument("--disable-gpu")

        # Force a unique temporary directory for EVERY run.
        self.user_data_dir = tempfile.mkdtemp()
        chrome_options.add_argument(f"--user-data-dir={self.user_data_dir}")

        prefs = {"download.default_directory": self.download_dir}
        chrome_options.add_experimental_option("prefs", prefs)

        # Fix: ChromeDriverManager().install() can return wrong file (THIRD_PARTY_NOTICES).
        # Resolve the actual chromedriver binary from the same directory.
        driver_path = ChromeDriverManager().install()
        driver_dir = os.path.dirname(driver_path)
        actual_binary = os.path.join(driver_dir, "chromedriver")
        if os.path.isfile(actual_binary) and os.access(actual_binary, os.X_OK):
            driver_path = actual_binary

        self.driver = webdriver.Chrome(service=Service(driver_path), options=chrome_options)
        self.wait = WebDriverWait(self.driver, 45)

    # ── Login via impersonation (shaw@ → impersonate mas@) ──────────────
    def login(self):
        """Login as shaw@strategysimple.com, select account, impersonate mas@accupointsolutions.com."""
        print(f"[LOGIN] Logging in as {LOGIN_EMAIL}...")
        self.driver.get(BASE_URL)

        # Email field
        email_inp = self.wait.until(EC.presence_of_element_located(
            (By.CSS_SELECTOR, "input[type='email'], input[placeholder='your@email.com']")
        ))
        email_inp.clear()
        email_inp.send_keys(LOGIN_EMAIL)
        email_inp.send_keys(Keys.ENTER)

        # Password field (may appear on same or next page)
        pass_inp = self.wait.until(EC.element_to_be_clickable(
            (By.CSS_SELECTOR, "input[type='password']")
        ))
        pass_inp.clear()
        pass_inp.send_keys(LOGIN_PASSWORD)
        pass_inp.send_keys(Keys.ENTER)

        time.sleep(8)
        print(f"[LOGIN] Login submitted. URL: {self.driver.current_url}")
        self.save_screenshot("01_login_done.png")

        # ── Select account: simple|AUDIENCE ──
        self._select_account()

        # ── Impersonate mas@ ──
        self._impersonate()

        print("[LOGIN] Impersonation complete. Ready to operate as AccuPoint Solutions.")

    def _select_account(self):
        """Select the simple|AUDIENCE account after login."""
        print("[LOGIN] Selecting account: simple|AUDIENCE...")

        # Check if we're already in an account
        if "AccuPoint Solutions" in self.driver.page_source or "Audience Lists" in self.driver.page_source:
            print("[LOGIN] Already in an account. Skipping selection.")
            return

        for label in ["simple|AUDIENCE", "SimpleAudience"]:
            try:
                target = self.wait.until(EC.element_to_be_clickable(
                    (By.XPATH, f"//*[contains(text(), '{label}')]")
                ))
                try:
                    target.click()
                except Exception:
                    self.driver.execute_script("arguments[0].click();", target)
                time.sleep(5)
                print(f"[LOGIN] Selected account via '{label}'")
                self.save_screenshot("02_account_selected.png")
                return
            except Exception:
                continue

        # Fallback: click 2nd card
        cards = self.driver.find_elements(By.XPATH, "//div[contains(@class, 'grid')]//div[contains(@class, 'cursor-pointer')]")
        if len(cards) >= 2:
            cards[1].click()
            time.sleep(5)
            self.save_screenshot("02_account_fallback.png")
        else:
            self.save_screenshot("02_account_fail.png")
            raise Exception("Account selection failed")

    def _impersonate(self):
        """Navigate to Teams, find AccuPoint Solutions, impersonate mas@."""
        print("[LOGIN] Navigating to Teams page...")
        self.driver.get(f"{BASE_URL}/home/simple-audience/white-label/teams")
        time.sleep(8)

        # Wait for teams page
        self.wait.until(EC.presence_of_element_located(
            (By.XPATH, "//input[@placeholder='Search by name...']")
        ))
        self.save_screenshot("03_teams_page.png")

        # Search for AccuPoint Solutions
        print("[LOGIN] Searching for AccuPoint Solutions...")
        search_box = self.driver.find_element(By.XPATH, "//input[@placeholder='Search by name...']")
        search_box.clear()
        search_box.send_keys("AccuPoint")
        time.sleep(3)
        self.save_screenshot("03_teams_search.png")

        # Click Manage on AccuPoint Solutions
        found = False
        try:
            manage_link = self.wait.until(EC.element_to_be_clickable(
                (By.XPATH, "//div[h3[contains(text(), 'AccuPoint')]]//a[contains(text(), 'Manage')]")
            ))
            manage_link.click()
            found = True
            print("[LOGIN] Found AccuPoint Solutions. Clicked Manage.")
        except Exception:
            # Fallback: paginate
            print("[LOGIN] Search didn't filter. Paginating...")
            search_box.clear()
            time.sleep(2)
            for page_num in range(2):
                try:
                    self.driver.execute_script("window.scrollTo(0, document.body.scrollHeight);")
                    time.sleep(1)
                    next_btn = self.driver.find_element(By.XPATH,
                        "//button[@aria-label='Go to next page'] | //button[contains(@class, 'next')] | //a[text()='>']")
                    self.driver.execute_script("arguments[0].click();", next_btn)
                    time.sleep(4)
                except Exception as e:
                    print(f"[LOGIN] Pagination click {page_num + 1} failed: {e}")

            try:
                manage_link = self.wait.until(EC.element_to_be_clickable(
                    (By.XPATH, "//div[h3[contains(text(), 'AccuPoint')]]//a[contains(text(), 'Manage')]")
                ))
                manage_link.click()
                found = True
            except Exception:
                pass

        if not found:
            self.save_screenshot("03_accupoint_not_found.png")
            raise Exception("AccuPoint Solutions team not found")

        time.sleep(3)
        self.save_screenshot("04_team_members.png")

        # Find target user row and click three dots menu
        print(f"[LOGIN] Finding user row for {IMPERSONATE_TARGET}...")
        actions = ActionChains(self.driver)
        user_row = self.driver.find_element(By.XPATH, f"//tr[contains(., '{IMPERSONATE_TARGET}')]")
        dots = user_row.find_element(By.TAG_NAME, "button")
        actions.move_to_element(dots).click().perform()
        time.sleep(2)

        # Click View
        print("[LOGIN] Clicking 'View'...")
        target_url = None
        try:
            view_link = self.wait.until(EC.presence_of_element_located(
                (By.XPATH, "/html/body/div[5]/div/div/div[3]//a")
            ))
            target_url = view_link.get_attribute("href")
        except Exception:
            view_link = self.wait.until(EC.presence_of_element_located(
                (By.XPATH, "//a[normalize-space()='View']")
            ))
            target_url = view_link.get_attribute("href")

        if target_url:
            self.driver.get(target_url)
        else:
            self.driver.execute_script("arguments[0].click();", view_link)
        time.sleep(2)

        # Click Impersonate button
        print("[LOGIN] Clicking 'Impersonate'...")
        imp_btn = self.wait.until(EC.element_to_be_clickable(
            (By.XPATH, "//button[contains(., 'Impersonate')]")
        ))
        self.driver.execute_script("arguments[0].click();", imp_btn)
        time.sleep(1)

        # Type CONFIRM
        print("[LOGIN] Typing CONFIRM...")
        confirm_inp = self.wait.until(EC.presence_of_element_located(
            (By.XPATH, "//input[@placeholder='Type CONFIRM'] | //input")
        ))
        confirm_inp.send_keys("CONFIRM")
        confirm_inp.send_keys(Keys.ENTER)

        print("[LOGIN] Impersonation initiated. Waiting for redirect...")
        time.sleep(10)

        # Navigate to AccuPoint workspace home
        self.driver.get(f"{BASE_URL}{WORKSPACE_PATH}")
        time.sleep(5)
        self.save_screenshot("05_impersonated.png")
        print(f"[LOGIN] Impersonated. URL: {self.driver.current_url}")

    # ── Navigation ──────────────────────────────────────────────────────
    def navigate_to_page(self, page_name):
        """Navigates to a specific page via the sidebar."""
        logging.info(f"Navigating to {page_name}...")
        try:
            link = self.wait.until(EC.element_to_be_clickable((By.XPATH, f"//a[contains(., '{page_name}')]")))
            link.click()
        except:
            print(f"Sidebar click failed for {page_name}, trying direct URL...")
            if page_name.lower() == "studio":
                self.driver.get(f"{BASE_URL}{WORKSPACE_PATH}/studio")
            elif page_name.lower() == "segments":
                self.driver.get(f"{BASE_URL}{WORKSPACE_PATH}/segment")

        self._wait_for_overlays()
        time.sleep(3)

    # ── Segment check ───────────────────────────────────────────────────
    def check_exists_in_segments(self, pixel_name, expected_name_pattern=None):
        """Checks if a segment for this pixel already exists using specific table cell verification."""
        logging.info(f"Checking for existing segment for {pixel_name}...")

        url = f"{BASE_URL}{WORKSPACE_PATH}/segment?query={pixel_name}"
        self.driver.get(url)
        self._wait_for_overlays()
        time.sleep(3)

        try:
            cell_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div[2]/div[1]/table/tbody/tr[1]/td[1]"
            try:
                 first_cell = self.driver.find_element(By.XPATH, cell_xpath)
                 cell_text = first_cell.text.lower()

                 if expected_name_pattern:
                     if expected_name_pattern.lower() in cell_text:
                         logging.info(f"Found specific segment '{expected_name_pattern}' for {pixel_name}.")
                         return True
                     else:
                         logging.info(f"Existing segment found but does not match pattern '{expected_name_pattern}'.")
                         return False

                 if pixel_name.lower() in cell_text:
                     logging.info(f"Found existing segment for {pixel_name} in table.")
                     return True
            except:
                 pass

        except Exception as e:
            logging.warning(f"Error checking segments: {e}")

        return False

    # ── Segment creation ────────────────────────────────────────────────
    def create_segment(self, pixel_name, days_list=[2, 3, 4]):
        """Creates a new segment in Studio with filter for last X days."""
        logging.info(f"Creating new segment for {pixel_name}...")
        self.navigate_to_page("Studio")
        self._wait_for_overlays()
        time.sleep(2)

        # 1. Select Dataset
        try:
            dataset_search = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//input[@placeholder='Search datasets...'] | //input[contains(@class, 'border-input')]")))
        except:
            logging.info("Generic search input not found, trying absolute fallback...")
            dataset_search = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[3]/div[1]/div[2]/div/input")))

        dataset_search.click()
        dataset_search.send_keys(pixel_name)
        time.sleep(2)

        # Click first result — user-provided XPath for the dataset card
        try:
            first_result = self.wait.until(EC.element_to_be_clickable(
                (By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[3]/div[1]/div[3]/div/div")
            ))
            first_result.click()
        except Exception as e:
            logging.error(f"Dataset for '{pixel_name}' not found in search results. Skipping.")
            self.save_screenshot(f"dataset_not_found_{pixel_name}.png")
            raise Exception(f"Dataset not found for '{pixel_name}'")

        time.sleep(2)
        self._wait_for_overlays()

        # 2. Setup Filter
        logging.info(f"Setting up filter logic...")

        # Click "Add First Filter" or "Add Filter"
        try:
            add_filter_btn = self.wait.until(EC.element_to_be_clickable(
                (By.XPATH, "//button[contains(., 'Add First Filter') or contains(., 'Add Filter')]")
            ))
        except:
            add_filter_btn = self.wait.until(EC.element_to_be_clickable(
                (By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/button")
            ))
        add_filter_btn.click()
        time.sleep(1)

        # Select Field: [Pixel] Event Timestamp
        field_dropdown = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div/div/div/div/div")))
        field_dropdown.click()
        time.sleep(1)

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
                logging.warning("Operator option XPath failed, searching by text...")
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

            value_input = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[2]/div[2]/div/div[2]/div/div/div[2]/input")))
            value_input.clear()
            value_input.send_keys(str(days))

            # Click "Select All Visible"
            select_all_btn = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[3]/div[2]/div[2]/button[1]")))
            self.driver.execute_script("arguments[0].scrollIntoView(true);", select_all_btn)
            time.sleep(1)
            self.driver.execute_script("arguments[0].click();", select_all_btn)
            time.sleep(2)

            # Check count
            count_elem = self.driver.find_element(By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[2]/div[1]/div[2]/div[3]/p[1]")
            count_text = count_elem.text

            def parse_count(text):
                try:
                    return int(re.sub(r'\D', '', text))
                except:
                    return 0

            count_val = parse_count(count_text)

            if count_val == 0:
                logging.info(f"Count is 0, waiting 1s to double check...")
                time.sleep(1)
                count_elem = self.driver.find_element(By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[2]/div[1]/div[2]/div[3]/p[1]")
                count_text = count_elem.text
                count_val = parse_count(count_text)

            logging.info(f"Row count for {days} days: {count_text}")

            if count_val > 0:
                break

        # Final check
        count_elem = self.driver.find_element(By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[2]/div[1]/div[2]/div[3]/p[1]")
        count_text = count_elem.text
        if "0 records" in count_text or count_text.strip() == "0":
             logging.warning(f"No data found for {pixel_name} even after {final_days} days.")
             raise Exception(f"No data found for {pixel_name} even after {final_days} days.")

        # 4. Save Segment
        logging.info("Saving Segment...")
        save_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div[4]/div[1]/button")))
        save_btn.click()
        time.sleep(1)

        name_input = self.wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[5]/form/div[1]/input")))
        name_input.clear()
        name_input.send_keys(f"Last {final_days} Days - {pixel_name}")

        final_save_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[5]/form/div[3]/button[2]")))
        final_save_btn.click()
        self._wait_for_overlays()
        time.sleep(3)

    # ── Download ────────────────────────────────────────────────────────
    def download_segment(self, pixel_name):
        """Downloads the export from the Segments page."""
        logging.info(f"Downloading segment for {pixel_name}...")

        url = f"{BASE_URL}{WORKSPACE_PATH}/segment?query={pixel_name}"
        self.driver.get(url)
        self._wait_for_overlays()
        time.sleep(3)

        # Click "View in Studio"
        try:
             view_studio_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div[2]/div/table/tbody/tr/td[6]/div/a")))
             view_studio_btn.click()
        except:
             logging.error("Could not find 'View in Studio' button via strict XPath, trying loose fallback...")
             view_studio_btn = self.driver.find_element(By.XPATH, "//a[contains(., 'View in Studio')]")
             view_studio_btn.click()

        self._wait_for_overlays()
        time.sleep(3)

        # Download Export button
        try:
            logging.info("Attempting to find 'Download Export' or 'Download' button...")
            download_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Download Export') or contains(., 'Download')]")))
            self.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", download_btn)
            time.sleep(1)
            logging.info("Clicking Download button...")
            self.driver.execute_script("arguments[0].click();", download_btn)
        except Exception as e:
             logging.error(f"Download button not found via text search: {e}")
             self.save_screenshot(f"download_btn_fail_{pixel_name}.png")
             raise e

        return self._wait_for_download(pixel_name)

    # ── Main entry point ────────────────────────────────────────────────
    def download_pixel_data(self, pixel_name, days=1):
        """Downloads the pixel data for the given pixel name and days."""
        expected_pattern = None
        if days > 4:
            expected_pattern = f"Last {days} Days"

        if self.check_exists_in_segments(pixel_name, expected_name_pattern=expected_pattern):
            return self.download_segment(pixel_name)
        else:
            if days > 4:
                days_list = [days]
            else:
                days_list = [2, 3, 4]
            self.create_segment(pixel_name, days_list=days_list)
            return self.download_segment(pixel_name)

    # ── Helpers ─────────────────────────────────────────────────────────
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
        try:
            import shutil
            shutil.rmtree(self.user_data_dir, ignore_errors=True)
        except:
            pass
