#!/usr/bin/env python3
"""Quick script: login → impersonate → navigate to API Keys page → capture key."""
import os, time, logging
from selenium import webdriver
from selenium.webdriver.chrome.service import Service as ChromeService
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.action_chains import ActionChains
import tempfile

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

CHROMEDRIVER = "/Users/ShawCole/.wdm/drivers/chromedriver/mac64/145.0.7632.117/chromedriver-mac-arm64/chromedriver"
OUTDIR = os.path.dirname(__file__)

def main():
    opts = Options()
    opts.add_argument("--window-size=2560,1440")
    opts.add_argument("--no-sandbox")
    opts.add_argument("--disable-dev-shm-usage")
    opts.add_argument("--disable-gpu")
    opts.add_argument(f"--user-data-dir={tempfile.mkdtemp()}")

    driver = webdriver.Chrome(service=ChromeService(CHROMEDRIVER), options=opts)
    wait = WebDriverWait(driver, 30)

    try:
        # Login
        driver.get("https://build.audiencelab.io")
        wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='email']"))).send_keys("shaw@strategysimple.com" + Keys.ENTER)
        wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='password']"))).send_keys("Escesc100$$!" + Keys.ENTER)
        time.sleep(8)

        # Select account
        try:
            el = wait.until(EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'simple|AUDIENCE')]")))
            driver.execute_script("arguments[0].click();", el)
            time.sleep(5)
        except: pass

        # Impersonate
        driver.get("https://build.audiencelab.io/home/simple-audience/white-label/teams")
        time.sleep(8)
        wait.until(EC.presence_of_element_located((By.XPATH, "//input[@placeholder='Search by name...']"))).send_keys("AccuPoint")
        time.sleep(3)
        wait.until(EC.element_to_be_clickable((By.XPATH, "//div[h3[contains(text(), 'AccuPoint')]]//a[contains(text(), 'Manage')]"))).click()
        time.sleep(3)

        row = driver.find_element(By.XPATH, "//tr[contains(., 'mas@accupointsolutions.com')]")
        ActionChains(driver).move_to_element(row.find_element(By.TAG_NAME, "button")).click().perform()
        time.sleep(2)

        try:
            vl = wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[5]/div/div/div[3]//a")))
            driver.get(vl.get_attribute("href"))
        except:
            vl = wait.until(EC.presence_of_element_located((By.XPATH, "//a[normalize-space()='View']")))
            driver.get(vl.get_attribute("href"))
        time.sleep(2)

        driver.execute_script("arguments[0].click();", wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Impersonate')]"))))
        time.sleep(1)
        ci = wait.until(EC.presence_of_element_located((By.XPATH, "//input[@placeholder='Type CONFIRM'] | //input")))
        ci.send_keys("CONFIRM" + Keys.ENTER)
        time.sleep(10)
        logger.info("Impersonated. Going to API Keys...")

        # Navigate to API Keys page
        driver.get("https://build.audiencelab.io/home/accupoint-solutions/settings/api-keys")
        time.sleep(5)
        driver.save_screenshot(os.path.join(OUTDIR, "api_keys_page_1.png"))

        # Also try the sidebar link path
        if "api" not in driver.current_url.lower():
            logger.info("Direct URL didn't work. Trying sidebar...")
            try:
                ak = driver.find_element(By.XPATH, "//a[contains(., 'API Keys') or contains(., 'API keys')]")
                ak.click()
                time.sleep(5)
            except:
                pass
            driver.save_screenshot(os.path.join(OUTDIR, "api_keys_page_2.png"))

        # Scroll and capture
        body = driver.find_element(By.TAG_NAME, "body").text
        with open(os.path.join(OUTDIR, "api_keys_text.txt"), "w") as f:
            f.write(body)
        logger.info("Page text saved to api_keys_text.txt")

        # Look for any key-like strings on the page
        import re
        keys = re.findall(r'sk_[A-Za-z0-9_-]{20,}', body)
        if keys:
            logger.info(f"Found API key(s): {keys}")
        else:
            logger.info("No sk_ keys found in page text. Check screenshots.")

        # Also grab the page source for hidden inputs
        source = driver.page_source
        hidden_keys = re.findall(r'sk_[A-Za-z0-9_-]{20,}', source)
        if hidden_keys:
            logger.info(f"Found key(s) in page source: {hidden_keys}")

        time.sleep(5)
    except Exception as e:
        logger.error(f"Failed: {e}")
        driver.save_screenshot(os.path.join(OUTDIR, "api_keys_error.png"))
        time.sleep(15)
    finally:
        driver.quit()

if __name__ == "__main__":
    main()
