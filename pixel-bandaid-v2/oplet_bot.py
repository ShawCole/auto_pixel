import os
import time
import logging
import re
from datetime import datetime
from selenium import webdriver
from selenium.webdriver.chrome.service import Service as ChromeService
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager
from dotenv import load_dotenv
from sqlalchemy import create_engine, text

# Configure Logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

load_dotenv()

class OpletBot:
    def __init__(self, headless=True):
        self.options = Options()
        if headless:
            self.options.add_argument("--headless=new")
        self.options.add_argument("--window-size=1920,1080")
        self.options.add_argument("--no-sandbox")
        self.options.add_argument("--disable-dev-shm-usage")
        self.options.add_argument("--disable-gpu")
        
        self.driver = webdriver.Chrome(service=ChromeService(ChromeDriverManager().install()), options=self.options)
        self.wait = WebDriverWait(self.driver, 30)
        
        # Database Engine
        db_host = os.getenv("MYSQL_HOST") or "34.26.61.148"
        db_user = os.getenv("MYSQL_USER") or "root"
        db_pass = os.getenv("MYSQL_PASSWORD") or "AccuPoint01!"
        db_name = os.getenv("PIXEL_DB") or "pixel"
        self.conn_url = f"mysql+mysqlconnector://{db_user}:{db_pass}@{db_host}/{db_name}"
        self.engine = create_engine(self.conn_url)

    def login(self):
        """Logs into app.simpleaudience.io using targeted credentials."""
        logger.info("Navigating to app.simpleaudience.io login...")
        self.driver.get("https://app.simpleaudience.io/auth/sign-in")
        
        # Email
        email = os.getenv("SIMPLE_AUDIENCE_EMAIL")
        password = os.getenv("SIMPLE_AUDIENCE_PASSWORD")
        
        logger.info(f"Logging in as {email}...")
        
        email_field = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder='your@email.com']")))
        email_field.send_keys(email)
        
        password_field = self.driver.find_element(By.CSS_SELECTOR, "input[type='password']")
        password_field.send_keys(password)
        password_field.send_keys(Keys.ENTER)
        
        # Wait for dashboard/workspace selector
        time.sleep(5)
        
        # Workspace selection check (AccuPoint Solutions)
        try:
            workspace_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div/a"
            if "workspace" in self.driver.current_url or self.driver.find_elements(By.XPATH, workspace_xpath):
                workspace_link = self.wait.until(EC.element_to_be_clickable((By.XPATH, workspace_xpath)))
                logger.info("Found workspace card. Clicking...")
                workspace_link.click()
                time.sleep(5)
        except:
             logger.info("Workspace selection skipped or already on dashboard.")

    def discover_pixel_url(self, client_name, pixel_name):
        """Discovers the on-platform URL for a specific pixel and updates the DB."""
        logger.info(f"Discovering OPLET URL for: {pixel_name}")
        
        url = "https://app.simpleaudience.io/home/accupoint-solutions/pixel"
        self.driver.get(url)
        time.sleep(5)
        
        try:
            # Search for pixel
            search_xpath = '//*[@id="query"]'
            search_field = self.wait.until(EC.presence_of_element_located((By.XPATH, search_xpath)))
            search_field.clear()
            search_field.send_keys(pixel_name)
            search_field.send_keys(Keys.ENTER)
            time.sleep(5)
            
            # Extract href from the monitoring button/link
            # XPath: /html/body/div[2]/div/div[2]/div[2]/div[2]/div/div[2]/div[1]/table/tbody/tr[1]/td[5]/div/a
            chart_link_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/div/div[2]/div[1]/table/tbody/tr[1]/td[5]/div/a"
            chart_link = self.wait.until(EC.presence_of_element_located((By.XPATH, chart_link_xpath)))
            on_platform_url = chart_link.get_attribute("href")
            
            if on_platform_url:
                logger.info(f"Captured On-Platform URL: {on_platform_url}")
                
                # Update DB
                with self.engine.connect() as conn:
                    update_sql = text("""
                        UPDATE pixel_sheets 
                        SET on_platform_url = :url, oplet_polling_active = 1 
                        WHERE client_name = :cname AND pixel_name = :pname
                    """)
                    conn.execute(update_sql, {
                        "url": on_platform_url,
                        "cname": client_name,
                        "pname": pixel_name
                    })
                    conn.commit()
                logger.info("Database updated with on_platform_url.")
                return on_platform_url
            else:
                logger.warning(f"Could not find href for {pixel_name}")
                return None
                
        except Exception as e:
            logger.error(f"Discovery failed for {pixel_name}: {e}")
            return None

    def poll_oplet(self, pixel_id, on_platform_url):
        """Navigates to the platform URL and scrapes the last event timestamp or fallback status."""
        logger.info(f"Polling OPLET from {on_platform_url}")
        
        self.driver.get(on_platform_url)
        time.sleep(5)
        
        result_value = None
        
        try:
            # 1. Try scraping timestamp from the first row/cell
            # XPath: /html/body/div[2]/div/div[2]/div[2]/div[2]/table/tbody/tr[1]/td[2]
            ts_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/table/tbody/tr[1]/td[2]"
            try:
                # Use a short wait here to detect empty state faster
                ts_element = WebDriverWait(self.driver, 5).until(EC.presence_of_element_located((By.XPATH, ts_xpath)))
                result_value = ts_element.text.strip()
                logger.info(f"Scraped OPLET Timestamp: {result_value}")
            except:
                logger.info("Timestamp cell not found. Checking for 'No Resolutions Found'...")
                
                # 2. Fallback: Check for "No Resolutions Found" header
                # XPath: /html/body/div[2]/div/div[2]/div[2]/div[2]/h3
                fallback_xpath = "/html/body/div[2]/div/div[2]/div[2]/div[2]/h3"
                try:
                    fallback_element = self.driver.find_element(By.XPATH, fallback_xpath)
                    fallback_text = fallback_element.text.strip()
                    if "No Resolutions Found" in fallback_text:
                        result_value = fallback_text
                        logger.info(f"Fallback detected: {result_value}")
                except:
                    logger.warning(f"Neither timestamp nor fallback found at {on_platform_url}")

            if result_value:
                # Update DB with either timestamp string or "No Resolutions Found"
                with self.engine.connect() as conn:
                    update_sql = text("""
                        UPDATE pixel_sheets 
                        SET oplet = :val 
                        WHERE id = :pid
                    """)
                    conn.execute(update_sql, {
                        "val": result_value,
                        "pid": pixel_id
                    })
                    conn.commit()
                logger.info(f"Database updated oplet for pixel ID {pixel_id} to '{result_value}'")
                return result_value
                
        except Exception as e:
            logger.error(f"Polling failed for {on_platform_url}: {e}")
            return None

    def _parse_timestamp(self, ts_str):
        """Attempts to parse various timestamp strings to a Python datetime object."""
        # Common formats:
        # "2024-05-20 10:00:00"
        # "May 20, 2024 10:00 AM"
        # etc.
        
        formats = [
            "%Y-%m-%d %H:%M:%S",
            "%m/%d/%Y %H:%M:%S",
            "%B %d, %Y %I:%M %p",
            "%Y-%m-%dT%H:%M:%S",
            "%Y-%m-%d %I:%M %p"
        ]
        
        for fmt in formats:
            try:
                return datetime.strptime(ts_str, fmt)
            except:
                continue
                
        # Fallback: regex for "YYYY-MM-DD HH:MM:SS"
        match = re.search(r'(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})', ts_str)
        if match:
             return datetime.strptime(match.group(1), "%Y-%m-%d %H:%M:%S")
             
        return None

    def close(self):
        self.driver.quit()

if __name__ == "__main__":
    # Test block
    bot = OpletBot(headless=False)
    try:
        bot.login()
        # bot.discover_pixel_url("ClientName", "PixelName")
    finally:
        bot.close()
