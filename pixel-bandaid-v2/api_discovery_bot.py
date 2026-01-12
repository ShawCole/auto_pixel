import os
import time
import json
import logging
from selenium import webdriver
from selenium.webdriver.chrome.service import Service as ChromeService
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager
import tempfile
from database import SimpleAudienceDatabase
from sqlalchemy import text, create_engine

# Configure Logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Credentials
EMAIL = os.getenv("SIMPLE_AUDIENCE_EMAIL", "mas@strategysimple.com")
PASSWORD = os.getenv("SIMPLE_AUDIENCE_PASSWORD", "AccuPoint01!")
IMPERSONATE_TARGET = os.getenv("SIMPLE_AUDIENCE_IMPERSONATE", "mas@accupointsolutions.com")

class ApiDiscoveryBot:
    def __init__(self, headless=True):
        self.options = Options()
        if headless:
            self.options.add_argument("--headless=new")
        self.options.add_argument("--window-size=2560,1440")
        self.options.add_argument("--no-sandbox")
        self.options.add_argument("--disable-dev-shm-usage")
        self.options.add_argument("--disable-gpu")
        
        # KEY: Enable Performance Logging
        self.options.set_capability("goog:loggingPrefs", {"performance": "ALL"})
        
        # Temp user data to avoid conflicts
        self.user_data_dir = tempfile.mkdtemp()
        self.options.add_argument(f"--user-data-dir={self.user_data_dir}")

        self.driver = webdriver.Chrome(service=ChromeService(ChromeDriverManager().install()), options=self.options)
        self.wait = WebDriverWait(self.driver, 30)

    def login(self):
        logger.info("Navigate to build.audiencelab.io...")
        self.driver.get("https://build.audiencelab.io")
        
        # 1. Email
        logger.info("Entering Email...")
        email_inp = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='email']")))
        email_inp.clear()
        email_inp.send_keys(EMAIL)
        email_inp.send_keys(Keys.ENTER)
        
        # 2. Password
        logger.info("Entering Password...")
        pass_inp = self.wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='password']")))
        pass_inp.clear()
        pass_inp.send_keys(PASSWORD)
        pass_inp.send_keys(Keys.ENTER)
        
        time.sleep(8)
        logger.info("Login submitted. Page Title: " + self.driver.title)

    def select_second_account(self):
        logger.info("Selecting Account: simple|AUDIENCE...")
        
        if "AccuPoint Solutions" in self.driver.page_source or "Audience Lists" in self.driver.page_source:
            logger.info("Already logged into an account. Skipping account selection.")
            return

        # 1. Direct Search for Text
        try:
             # Look for "simple|AUDIENCE" anywhere clickable
             target = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'simple|AUDIENCE')]")))
             logger.info("Found simple|AUDIENCE text element. Clicking...")
             try:
                target.click()
             except:
                self.driver.execute_script("arguments[0].click();", target)
                
             time.sleep(5)
             return
        except Exception as e:
             logger.info(f"Direct simple|AUDIENCE search failed: {e}")

        # 2. Fallback: 'SimpleAudience'
        try:
             target = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'SimpleAudience')]")))
             target.click()
             time.sleep(5)
             return
        except:
             pass

        # 3. Fallback: Grid items
        try:
            # If not found yet, try clicking the 2nd generic card
            cards = self.driver.find_elements(By.XPATH, "//div[contains(@class, 'grid')]//div[contains(@class, 'cursor-pointer')]")
            if len(cards) >= 2:
                 logger.info(f"Clicking 2nd generic card (index 1) out of {len(cards)}...")
                 cards[1].click()
            else:
                 logger.error("Could not identify simple|AUDIENCE or a 2nd card.")
                 logger.info("Body Text Dump (First 2000 chars):\n" + self.driver.find_element(By.TAG_NAME, "body").text[:2000])
                 raise Exception("Account selection failed.")
                  
        except Exception as e:
            logger.error(f"Failed to select account: {e}")
            self.driver.save_screenshot("account_selection_fail.png")
            raise

        time.sleep(5)
    
    def navigate_teams_impersonate(self):
        logger.info("Navigating to Teams...")
        try:
            # 1. Navigation to Teams
            # User provided specific XPath
            user_teams_xpath = "/html/body/div[2]/div/div[1]/div/div[2]/div/div[2]/div[3]/div[2]/ul/li[2]/a"
            
            try:
                logger.info(f"Attempting to click Teams using User XPath: {user_teams_xpath}")
                teams_link = self.wait.until(EC.element_to_be_clickable((By.XPATH, user_teams_xpath)))
                teams_link.click()
                logger.info("Clicked Teams link using User XPath.")
            except Exception as e:
                logger.warning(f"User XPath for Teams failed: {e}")
                
                # Fallback to robust sidebar search
                try:
                    logger.info("Trying generic sidebar search...")
                    teams_link = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//nav//a[contains(., 'Teams')] | //aside//a[contains(., 'Teams')]")))
                    teams_link.click()
                except:
                    # Fallback to direct href
                    logger.info("Sidebar link not found, trying generic text match...")
                    teams_link = self.driver.find_element(By.XPATH, "//a[normalize-space()='Teams']")
                    self.driver.execute_script("arguments[0].click();", teams_link)
            
            time.sleep(3)
            
            # Verify we are on Teams page
            if "Members" not in self.driver.page_source and "Team" not in self.driver.title:
                 logger.warning("Might not be on Teams page. Current URL: " + self.driver.current_url)

            logger.info("Selecting 'AccuPoint Solutions' Team...")
            
            # 2. Find Team Logic (with Pagination)
            found = False
            
            # Selectors
            # Robust: finds card with header 'AccuPoint' and clicks 'Manage'
            accupoint_generic = "//div[h3[contains(text(), 'AccuPoint')]]//a[contains(text(), 'Manage')]"
            # User Specific:
            accupoint_user = "/html/body/div[2]/div/div[2]/div[2]/div[3]/div/div[2]/div[7]//a[contains(text(), 'Manage')]"
            
            try:
                # Page 1 Check
                # Only use specific name search to avoid clicking wrong team
                target = self.driver.find_element(By.XPATH, accupoint_generic)
                target.click()
                found = True
                logger.info("Found AccuPoint on Page 1 (Generic).")

            except:
                logger.info("AccuPoint not found on Page 1. Attempting Next Page...")
                
                # Pagination
                try:
                    # Scroll down
                    self.driver.execute_script("window.scrollTo(0, document.body.scrollHeight);")
                    time.sleep(1)
                    
                    # Robust Next Button (Span 'Go to next page')
                    next_btns = self.driver.find_elements(By.XPATH, "//button[.//span[contains(text(), 'Go to next page')]]")
                    if not next_btns:
                        # Fallback to Arrow/Text
                        next_btns = self.driver.find_elements(By.XPATH, "//button[contains(@aria-label, 'Next')]")
                    
                    if next_btns:
                        next_btn = next_btns[0]
                        self.driver.execute_script("arguments[0].scrollIntoView(true);", next_btn)
                        time.sleep(1)
                        self.driver.execute_script("arguments[0].click();", next_btn)
                        logger.info("Clicked Next Page. Waiting...")
                        time.sleep(5)
                        
                        # Search Page 2
                        try:
                            target = self.driver.find_element(By.XPATH, accupoint_generic)
                            self.driver.execute_script("arguments[0].scrollIntoView(true);", target)
                            time.sleep(1)
                            target.click()
                            found = True
                            logger.info("Found AccuPoint on Page 2 (Generic)! Waiting for Members page...")
                        except:
                            target = self.driver.find_element(By.XPATH, accupoint_user)
                            self.driver.execute_script("arguments[0].scrollIntoView(true);", target)
                            time.sleep(1)
                            target.click()
                            found = True
                            logger.info("Found AccuPoint on Page 2 (User XPath)! Waiting for Members page...")
                        
                        # Verify navigation
                        if found:
                            try:
                                self.wait.until(EC.text_to_be_present_in_element((By.TAG_NAME, "body"), "Members"))
                            except:
                                logger.warning("Did not detect 'Members' text after clicking team.")

                    else:
                        logger.error("Next Page button not found.")
                        
                except Exception as e:
                    logger.error(f"Pagination/Selection failed: {e}")
                    with open("page_dump.html", "w") as f:
                        f.write(self.driver.page_source)
            
            if not found:
                raise Exception("AccuPoint Solutions team not found.")
            
            time.sleep(3)
            
            # 3. Impersonate Logic
            # Use ActionChains for better interaction
            from selenium.webdriver.common.action_chains import ActionChains
            actions = ActionChains(self.driver)

            logger.info("Finding 'Three Dots' for user...")
            try:
                # Find the row specifically for the target user
                user_row = self.driver.find_element(By.XPATH, f"//tr[contains(., '{IMPERSONATE_TARGET}')]")
                # Find the button inside that row (the last cell usually, or look for button tag)
                dots = user_row.find_element(By.TAG_NAME, "button")
                
                logger.info("Clicking 'Three Dots' using ActionChains...")
                actions.move_to_element(dots).click().perform()
                time.sleep(2)
            except Exception as e:
                logger.error(f"Failed to click Three Dots: {e}")
                with open("dots_fail_dump.html", "w") as f:
                    f.write(self.driver.page_source)
                raise
            
            logger.info("Clicking 'View'...")
            try:
                # User provided NEW XPath for View (from screenshot feedback): 
                # /html/body/div[5]/div/div/div[3]
                # It contains an <a> tag. We want that <a> tag to get the href.
                target_url = None
                try:
                    # Append /a to target the link specifically
                    view_xpath = "/html/body/div[5]/div/div/div[3]//a"
                    view_link = self.wait.until(EC.presence_of_element_located((By.XPATH, view_xpath)))
                    target_url = view_link.get_attribute("href")
                    logger.info(f"Found View link using User XPath: {view_xpath}. HREF: {target_url}")
                except:
                    logger.info("User XPath for View link failed. Finding any 'View' link...")
                    # Find any <a> with text View
                    view_link = self.wait.until(EC.presence_of_element_located((By.XPATH, "//a[normalize-space()='View']")))
                    target_url = view_link.get_attribute("href")
                
                if target_url:
                    logger.info(f"Navigating directly to View URL: {target_url}")
                    self.driver.get(target_url)
                else:
                    # Fallback to JS Click if no href (unlikely for an <a>)
                    logger.info("No HREF found. Trying JS Click on element...")
                    self.driver.execute_script("arguments[0].click();", view_link)
                
                time.sleep(2)
            except Exception as e:
                logger.error(f"Failed to find/click 'View' option: {e}")
                with open("view_fail_dump.html", "w") as f:
                    f.write(self.driver.page_source)
                raise
            time.sleep(2)
            
            logger.info("Clicking 'Impersonate'...")
            # Modal 'Impersonate' button
            impersonate_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Impersonate')]")))
            self.driver.execute_script("arguments[0].click();", impersonate_btn)
            time.sleep(1)
            
            logger.info("Typing CONFIRM...")
            # Popup input
            confirm_input = self.wait.until(EC.presence_of_element_located((By.XPATH, "//input[@placeholder='Type CONFIRM'] | //input")))
            confirm_input.send_keys("CONFIRM")
            confirm_input.send_keys(Keys.ENTER)
            
            logger.info("Impersonation initiated. Waiting for dashboard...")
            time.sleep(10) # Wait for reload/redirect
            
            # Direct navigation to AccuPoint Home as suggested by user
            logger.info("Navigating directly to AccuPoint Solutions Home...")
            self.driver.get("https://build.audiencelab.io/home/accupoint-solutions")
            time.sleep(5)
            
        except Exception as e:
            logger.error(f"Navigation/Impersonation failed: {e}")
            try:
                logger.info("FAILURE PAGE DUMP:\n" + self.driver.find_element(By.TAG_NAME, "body").text[:2000])
                self.save_screenshot("impersonate_fail.png")
            except:
                pass
            raise

    def process_all_segments(self):
        db = SimpleAudienceDatabase(use_ssh=False)
        pixels = db.get_active_pixels()
        
        logger.info(f"Processing {len(pixels)} pixels...")
        
        # Connection for updates
        mysql_host = os.getenv("MYSQL_HOST") or "34.26.61.148"
        mysql_port = int(os.getenv("MYSQL_PORT", 3306))
        mysql_user = os.getenv("MYSQL_USER") or "root"
        mysql_password = os.getenv("MYSQL_PASSWORD") or "AccuPoint01!"
        conn_url = f"mysql+mysqlconnector://{mysql_user}:{mysql_password}@{mysql_host}:{mysql_port}/pixel"
        engine = create_engine(conn_url)

        # 1. Handle already-in-account state
        current_url = self.driver.current_url
        if "home/accupoint-solutions" in current_url or "/segments" in current_url:
            logger.info("Already in AccuPoint Solutions account. Skipping post-impersonation selection.")
        else:
            logger.info("Selecting AccuPoint Account (Post-Impersonation)...")
            try:
                accu_card_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div/a"
                accu_card = self.wait.until(EC.element_to_be_clickable((By.XPATH, accu_card_xpath)))
                accu_card.click()
                time.sleep(5)
            except Exception as e:
                logger.warning(f"Account card not found or already skipped: {e}")

        for pixel in pixels:
            pixel_name = pixel['pixel_name']
            client_name = pixel['client_name']
            existing_api = pixel.get('segment_api')
            
            if existing_api and "api.audiencelab.io" in existing_api:
                logger.info(f"Skipping already processed Pixel: {pixel_name}")
                continue

            if "45 day" in pixel_name.lower():
                logger.info(f"Skipping 45 day segment: {pixel_name}")
                continue
                
            logger.info(f"Processing Pixel: {pixel_name} (Client: {client_name})")
            
            try:
                # 1. Navigate to Segments (Correct URL provided by user)
                self.driver.get("https://build.audiencelab.io/home/accupoint-solutions/segment")
                time.sleep(5)
                
                # 2. Search for segment (Try client_name first as suggested by user)
                # 2. Search for segment (Use literal client_name as requested)
                search_query = client_name
                logger.info(f"Searching for Segment using term: {search_query}")
                
                search_inp = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder*='Search']")))
                search_inp.click()
                search_inp.send_keys(Keys.COMMAND + "a")
                search_inp.send_keys(Keys.BACKSPACE)
                search_inp.send_keys(search_query)
                search_inp.send_keys(Keys.ENTER)
                time.sleep(5)
                
                # Check results - if no results, try a broader but literal match
                if "No records found" in self.driver.page_source or "No results" in self.driver.page_source:
                    logger.info(f"No results for '{search_query}'. Trying pixel_name: {pixel_name}")
                    search_inp.click()
                    search_inp.send_keys(Keys.COMMAND + "a")
                    search_inp.send_keys(Keys.BACKSPACE)
                    search_inp.send_keys(pixel_name)
                    search_inp.send_keys(Keys.ENTER)
                    time.sleep(5)
                    search_query = pixel_name
                
                # Check results - if no results, try a more flexible term
                if "No records found" in self.driver.page_source or "No results" in self.driver.page_source:
                    logger.info(f"No results for '{search_query}'. Trying simplified search...")
                    # For Retirement_Results_AWM, try searching for "Retirement"
                    simplified_query = search_term.split('_')[0] if "_" in search_term else search_term[:5]
                    logger.info(f"Trying simplified search with: {simplified_query}")
                    search_inp.click()
                    search_inp.send_keys(Keys.COMMAND + "a")
                    search_inp.send_keys(Keys.BACKSPACE)
                    search_inp.send_keys(simplified_query)
                    search_inp.send_keys(Keys.ENTER)
                    time.sleep(5)
                    search_query = simplified_query # Update for row finding
                
                # 3. Open in Studio (Try to find row more robustly)
                target_row = None
                try:
                    logger.info(f"Looking for row containing '{search_term}'...")
                    # Try finding by name in a specific cell if possible
                    target_row = self.wait.until(EC.presence_of_element_located((By.XPATH, f"//tr[.//td[contains(., '{search_term}')]]]")))
                except:
                    logger.info(f"Row with '{search_term}' in TD not found. Trying looser row match...")
                    try:
                        target_row = self.wait.until(EC.presence_of_element_located((By.XPATH, f"//tr[contains(., '{search_term}')]")))
                    except:
                        logger.info(f"Looser match for '{search_term}' failed. Trying pixel_name: '{pixel_name}'...")
                        target_row = self.wait.until(EC.presence_of_element_located((By.XPATH, f"//tr[contains(., '{pixel_name}')]")))

                if not target_row:
                    raise Exception(f"No row found for {client_name} or {pixel_name}")

                # 4. Click the 'Studio' eyeball icon (usually the first anchor in the actions cell)
                try:
                    # Target an <a> tag that contains 'studio' in its href
                    studio_btn = target_row.find_element(By.XPATH, ".//a[contains(@href, 'studio')]")
                    logger.info("Found 'Studio' eyeball icon by href.")
                except:
                    # Fallback to the first child <a> or button in the last few cells
                    logger.info("'Studio' icon not found by href. Trying generic action icons...")
                    studio_btn = target_row.find_element(By.XPATH, ".//td[last()]//a | .//td[last()]//button")

                self.driver.execute_script("arguments[0].click();", studio_btn)
                time.sleep(10)
                
                # 5. Extract Segment Name from Studio header
                # Specific XPath for segment name container: /html/body/div[2]/div/div[2]/div[2]/div[3]/div[4]/div[2]/div[2]/div/div
                try:
                    name_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[3]/div[4]/div[2]/div[2]/div/div"
                    name_el = self.driver.find_element(By.XPATH, name_xpath)
                    extracted_name = name_el.text.strip()
                    logger.info(f"Extracted Segment Name: {extracted_name}")
                except:
                    logger.warning("Could not extract segment name from studio.")
                    extracted_name = None

                # 5. Click "Show API" to trigger requests
                # Button XPath: /html/body/div[2]/div/div[2]/div[2]/div[3]/div[6]/div[2]/div[2]/div[1]/span/button
                try:
                    show_api_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[3]/div[6]/div[2]/div[2]/div[1]/span/button"
                    show_api_btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, show_api_xpath)))
                    self.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", show_api_btn)
                    time.sleep(1)
                    show_api_btn.click()
                    logger.info("Clicked 'Show API'.")
                    time.sleep(5)
                except Exception as e:
                    logger.warning(f"Could not click Show API: {e}")

                # 6. Capture the Segment API URL from performance logs
                # Broaden search: any URL containing "segments" and query params
                segment_api_url = None
                logs = self.driver.get_log("performance")
                for entry in logs:
                    try:
                        message = json.loads(entry["message"])["message"]
                        if message["method"] == "Network.requestWillBeSent":
                            url = message["params"]["request"]["url"]
                            if ("segments" in url.lower() or "audiencelab.io" in url.lower()) and ("?" in url or "page=" in url):
                                segment_api_url = url
                                # Preference for ones with both segments and audiencelab
                                if "api.audiencelab.io" in url and "segments" in url:
                                    break
                    except:
                        pass
                
                # Fallback: Extract from page text if button revealed it
                if not segment_api_url:
                    logger.info("API not found in network logs. Checking page content...")
                    try:
                        # Look for common URL patterns in the page body
                        # Usually displayed in a read-only input or code block
                        page_text = self.driver.find_element(By.TAG_NAME, "body").text
                        import re
                        urls = re.findall(r'https?://[^\s<>"]+api\.audiencelab\.io/segments/[^\s<>"]+', page_text)
                        if urls:
                            segment_api_url = urls[0]
                            logger.info(f"Found API URL in page text: {segment_api_url}")
                    except:
                        pass
                
                if segment_api_url:
                    # Transform Studio URL to API URL if needed
                    if "segment=" in segment_api_url:
                        import re
                        match = re.search(r'segment=([a-f0-9-]{36})', segment_api_url)
                        if match:
                            uuid = match.group(1)
                            segment_api_url = f"https://api.audiencelab.io/segments/{uuid}?page=1&page_size=50"
                            logger.info(f"Transformed to API URL: {segment_api_url}")
                        
                    logger.info(f"Captured Segment API: {segment_api_url}")
                    
                    # 7. Update Database
                    with engine.connect() as conn:
                        update_sql = text("""
                            UPDATE pixel_sheets 
                            SET segment_name = :sname, segment_api = :sapi 
                            WHERE client_name = :cname AND pixel_name = :pname
                        """)
                        conn.execute(update_sql, {
                            "sname": extracted_name,
                            "sapi": segment_api_url,
                            "cname": client_name,
                            "pname": pixel_name
                        })
                        conn.commit()
                        logger.info(f"Database updated for {pixel_name}")
                        
                        # 8. Discover OPLET URL (Third Step)
                        try:
                            logger.info(f"Starting third step: OPLET discovery for {pixel_name}")
                            from oplet_bot import OpletBot
                            oplet_bot = OpletBot(headless=True)
                            oplet_bot.login()
                            oplet_bot.discover_pixel_url(client_name, pixel_name)
                            oplet_bot.close()
                            logger.info(f"OPLET discovery completed for {pixel_name}")
                        except Exception as oe:
                            logger.error(f"OPLET discovery failed for {pixel_name}: {oe}")
                else:
                    logger.warning(f"No segment API URL found for {pixel_name}")
                    
            except Exception as ev:
                logger.error(f"Error processing {pixel_name}: {ev}")
                self.save_screenshot(f"fail_{pixel_name}.png")
                # Also save HTML for debugging
                with open(f"fail_{pixel_name}.html", "w") as f:
                    f.write(self.driver.page_source)
                continue

    def open_segment_in_studio(self):
        # Deprecated: usage moved to process_all_segments
        pass


    def capture_api_token(self):
        logger.info("Scanning Performance Logs for API Token...")
        logs = self.driver.get_log("performance")
        
        candidates = []
        
        for entry in logs:
            try:
                message = json.loads(entry["message"])["message"]
                if message["method"] == "Network.requestWillBeSent":
                    request = message["params"]["request"]
                    url = request["url"]
                    headers = request.get("headers", {})
                    
                    # Check for Authorization or apikey (Supabase)
                    auth_header = None
                    api_key_header = None
                    for k, v in headers.items():
                        if k.lower() == "authorization":
                            auth_header = v
                        if k.lower() == "apikey":
                            api_key_header = v
                    
                    if auth_header or api_key_header:
                        candidate = {
                            "url": url,
                            "authorization": auth_header,
                            "api_key": api_key_header,
                            "headers": headers
                        }
                        candidates.append(candidate)
                        
                        # Save if it's likely an API call (audiencelab, api, graphql, or supabase with apikey)
                        if any(term in url.lower() for term in ["api", "graphql", "audiencelab", "supabase"]):
                            logger.info(f"POTENTIAL TOKEN/KEY FOUND!")
                            logger.info(f"URL: {url}")
                            if auth_header:
                                logger.info(f"Auth Token: {auth_header[:20]}... (truncated)")
                            if api_key_header:
                                logger.info(f"API Key: {api_key_header[:20]}... (truncated)")
                            
                            # Save the best looking candidate (auth_header is usually better if both exist)
                            with open("api_credentials.json", "w") as f:
                                json.dump(candidate, f, indent=2)
                            
                            # Log success but continue scanning to find ALL candidates
                            # we'll use the last one found as the primary in api_credentials.json 
                            # but network_dump.json has everything.
            except Exception as e:
                # logger.debug(f"Error parsing log entry: {e}")
                pass

        # Save all candidates to debug file
        with open("network_dump.json", "w") as f:
            json.dump(candidates, f, indent=2)
            
        if os.path.exists("api_credentials.json"):
            logger.info("Credentials successfully saved to api_credentials.json")
            return True
            
        logger.warning("No suitable API tokens or keys found in logs. Check network_dump.json")
        return False

    def save_screenshot(self, filename):
        self.driver.save_screenshot(filename)
        logger.info(f"Saved screenshot: {filename}")

    def close(self):
        self.driver.quit()

if __name__ == "__main__":
    # Run the bot
    # HEADED MODE ENABLED for debugging
    bot = ApiDiscoveryBot(headless=False)
    
    try:
        bot.login()
        bot.select_second_account()
        bot.navigate_teams_impersonate()
        
        # Start the automated iteration
        bot.process_all_segments()
        
        # Finally capture a global token for verification/fast_sync.py
        if bot.capture_api_token():
            print("SUCCESS: Full cycle complete.")
        else:
            print("WARNING: Token capture at end failed, but check DB for results.")
    except Exception as e:
        logger.error(f"Bot execution failed: {e}")
        # Keep browser open for inspection if headed
        time.sleep(300) 
    finally:
        # bot.driver.quit() # Commented out to keep browser open
        pass
