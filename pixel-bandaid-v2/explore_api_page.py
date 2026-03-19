#!/usr/bin/env python3
"""
Re-enter impersonated session and explore the segment API page fully.
Captures screenshots at every scroll position.
"""
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
from selenium.webdriver.common.action_chains import ActionChains
import tempfile

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

EMAIL = "shaw@strategysimple.com"
PASSWORD = "Escesc100$$!"
IMPERSONATE_TARGET = "mas@accupointsolutions.com"
SEGMENT_NAME = "API Test"
SEGMENT_API = "https://api.audiencelab.io/segments/d25d4875-f425-411f-b290-aa65352d9d20?page=1&page_size=50"

CHROMEDRIVER = "/Users/ShawCole/.wdm/drivers/chromedriver/mac64/145.0.7632.117/chromedriver-mac-arm64/chromedriver"
SCREENSHOT_DIR = os.path.join(os.path.dirname(__file__), "api_explore")
os.makedirs(SCREENSHOT_DIR, exist_ok=True)


def screenshot(driver, name):
    path = os.path.join(SCREENSHOT_DIR, name)
    driver.save_screenshot(path)
    logger.info(f"Screenshot: {path}")


def main():
    opts = Options()
    opts.add_argument("--window-size=2560,1440")
    opts.add_argument("--no-sandbox")
    opts.add_argument("--disable-dev-shm-usage")
    opts.add_argument("--disable-gpu")
    opts.set_capability("goog:loggingPrefs", {"performance": "ALL"})
    user_data_dir = tempfile.mkdtemp()
    opts.add_argument(f"--user-data-dir={user_data_dir}")

    driver = webdriver.Chrome(service=ChromeService(CHROMEDRIVER), options=opts)
    wait = WebDriverWait(driver, 30)

    try:
        # 1. Login
        logger.info("Logging in...")
        driver.get("https://build.audiencelab.io")
        email_inp = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='email']")))
        email_inp.send_keys(EMAIL)
        email_inp.send_keys(Keys.ENTER)
        pass_inp = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='password']")))
        pass_inp.send_keys(PASSWORD)
        pass_inp.send_keys(Keys.ENTER)
        time.sleep(8)
        logger.info(f"Logged in. Title: {driver.title}")

        # 2. Select account
        try:
            target = wait.until(EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'simple|AUDIENCE')]")))
            driver.execute_script("arguments[0].click();", target)
            time.sleep(5)
        except:
            pass

        # 3. Navigate to Teams and impersonate
        logger.info("Going to Teams...")
        driver.get("https://build.audiencelab.io/home/simple-audience/white-label/teams")
        time.sleep(8)
        wait.until(EC.presence_of_element_located((By.XPATH, "//input[@placeholder='Search by name...']")))

        search = driver.find_element(By.XPATH, "//input[@placeholder='Search by name...']")
        search.send_keys("AccuPoint")
        time.sleep(3)

        manage = wait.until(EC.element_to_be_clickable(
            (By.XPATH, "//div[h3[contains(text(), 'AccuPoint')]]//a[contains(text(), 'Manage')]")
        ))
        manage.click()
        time.sleep(3)

        # Find user and impersonate
        actions = ActionChains(driver)
        user_row = driver.find_element(By.XPATH, f"//tr[contains(., '{IMPERSONATE_TARGET}')]")
        dots = user_row.find_element(By.TAG_NAME, "button")
        actions.move_to_element(dots).click().perform()
        time.sleep(2)

        try:
            view_link = wait.until(EC.presence_of_element_located((By.XPATH, "/html/body/div[5]/div/div/div[3]//a")))
            url = view_link.get_attribute("href")
        except:
            view_link = wait.until(EC.presence_of_element_located((By.XPATH, "//a[normalize-space()='View']")))
            url = view_link.get_attribute("href")
        driver.get(url)
        time.sleep(2)

        imp_btn = wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(., 'Impersonate')]")))
        driver.execute_script("arguments[0].click();", imp_btn)
        time.sleep(1)
        confirm = wait.until(EC.presence_of_element_located((By.XPATH, "//input[@placeholder='Type CONFIRM'] | //input")))
        confirm.send_keys("CONFIRM")
        confirm.send_keys(Keys.ENTER)
        time.sleep(10)
        logger.info("Impersonation complete.")

        # 4. Navigate to Segments
        logger.info("Going to Segments page...")
        driver.get("https://build.audiencelab.io/home/accupoint-solutions/segment")
        time.sleep(8)
        screenshot(driver, "01_segments_list.png")

        # 5. Click on the first segment row to open its detail page
        logger.info(f"Looking for segment: {SEGMENT_NAME}...")

        # Try clicking on the segment name to open detail
        try:
            seg_link = wait.until(EC.element_to_be_clickable(
                (By.XPATH, f"//tr[contains(., '{SEGMENT_NAME}')]//td[1]//a | //tr[contains(., '{SEGMENT_NAME}')]//a")
            ))
            seg_url = seg_link.get_attribute("href")
            logger.info(f"Segment link URL: {seg_url}")
            driver.get(seg_url)
            time.sleep(5)
        except Exception:
            # Try clicking the row itself
            logger.info("No direct link found. Clicking row...")
            row = driver.find_element(By.XPATH, f"//tr[contains(., '{SEGMENT_NAME}')]")
            row.click()
            time.sleep(5)

        screenshot(driver, "02_segment_detail_top.png")

        # 6. Now scroll through the entire page, capturing screenshots
        total_height = driver.execute_script("return document.body.scrollHeight")
        viewport_height = driver.execute_script("return window.innerHeight")
        logger.info(f"Page height: {total_height}, viewport: {viewport_height}")

        scroll_pos = 0
        shot_num = 3
        while scroll_pos < total_height:
            driver.execute_script(f"window.scrollTo(0, {scroll_pos});")
            time.sleep(1)
            screenshot(driver, f"{shot_num:02d}_scroll_{scroll_pos}.png")
            scroll_pos += viewport_height - 100  # overlap by 100px
            shot_num += 1
            # Re-check height (page may lazy-load)
            total_height = driver.execute_script("return document.body.scrollHeight")

        # 7. Look for any "API" buttons or sections
        logger.info("Searching for API-related elements on page...")
        api_elements = driver.find_elements(By.XPATH,
            "//*[contains(text(), 'API') or contains(text(), 'api') or contains(text(), 'curl') or contains(text(), 'endpoint') or contains(text(), 'Show API') or contains(text(), 'Authorization') or contains(text(), 'Bearer')]"
        )
        for el in api_elements:
            text = el.text.strip()[:200]
            tag = el.tag_name
            logger.info(f"API element found: <{tag}> {text}")

        # 8. Also try clicking Show API if it exists
        try:
            show_api_btn = driver.find_element(By.XPATH, "//button[contains(., 'Show API') or contains(., 'API')]")
            driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", show_api_btn)
            time.sleep(1)
            screenshot(driver, "api_button_found.png")
            show_api_btn.click()
            time.sleep(3)
            screenshot(driver, "api_button_clicked.png")

            # Capture the page text after clicking
            body_text = driver.find_element(By.TAG_NAME, "body").text
            # Save full page text for analysis
            with open(os.path.join(SCREENSHOT_DIR, "page_text_after_api_click.txt"), "w") as f:
                f.write(body_text)
            logger.info("Saved page text after API click")
        except Exception as e:
            logger.info(f"No Show API button found: {e}")

        # Save full page text regardless
        body_text = driver.find_element(By.TAG_NAME, "body").text
        with open(os.path.join(SCREENSHOT_DIR, "full_page_text.txt"), "w") as f:
            f.write(body_text)

        # Save page HTML for deeper analysis
        with open(os.path.join(SCREENSHOT_DIR, "page_source.html"), "w") as f:
            f.write(driver.page_source)

        logger.info("Exploration complete. All screenshots and text saved.")

        # Keep browser open briefly for manual inspection
        time.sleep(10)

    except Exception as e:
        logger.error(f"Failed: {e}")
        screenshot(driver, "error.png")
        time.sleep(30)
    finally:
        driver.quit()


if __name__ == "__main__":
    main()
