#!/usr/bin/env python3
"""
Dry-run impersonation bot: login → impersonate → scan segments → log to file (no DB writes).
Usage: python3 run_impersonate_dry.py
"""
import os
import sys
import time
import json
import re
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

# Credentials — override via env or use these defaults
EMAIL = os.getenv("SIMPLE_AUDIENCE_EMAIL", "shaw@strategysimple.com")
PASSWORD = os.getenv("SIMPLE_AUDIENCE_PASSWORD", "Escesc100$$!")
IMPERSONATE_TARGET = os.getenv("SIMPLE_AUDIENCE_IMPERSONATE", "mas@accupointsolutions.com")

RESULTS_FILE = os.path.join(os.path.dirname(__file__), "dry_run_results.json")


class ImpersonateDryRun:
    def __init__(self, headless=False):
        self.options = Options()
        if headless:
            self.options.add_argument("--headless=new")
        self.options.add_argument("--window-size=2560,1440")
        self.options.add_argument("--no-sandbox")
        self.options.add_argument("--disable-dev-shm-usage")
        self.options.add_argument("--disable-gpu")
        self.options.set_capability("goog:loggingPrefs", {"performance": "ALL"})

        self.user_data_dir = tempfile.mkdtemp()
        self.options.add_argument(f"--user-data-dir={self.user_data_dir}")

        chromedriver_path = "/Users/ShawCole/.wdm/drivers/chromedriver/mac64/145.0.7632.117/chromedriver-mac-arm64/chromedriver"
        self.driver = webdriver.Chrome(
            service=ChromeService(chromedriver_path),
            options=self.options
        )
        self.wait = WebDriverWait(self.driver, 30)
        self.results = []

    # ── Step 1: Login ──────────────────────────────────────────────────
    def login(self):
        logger.info(f"Logging in as {EMAIL}...")
        self.driver.get("https://build.audiencelab.io")

        email_inp = self.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='email']")))
        email_inp.clear()
        email_inp.send_keys(EMAIL)
        email_inp.send_keys(Keys.ENTER)

        pass_inp = self.wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='password']")))
        pass_inp.clear()
        pass_inp.send_keys(PASSWORD)
        pass_inp.send_keys(Keys.ENTER)

        time.sleep(8)
        logger.info(f"Login submitted. Title: {self.driver.title}")
        self.save_screenshot("01_login_done.png")

    # ── Step 2: Select Account ─────────────────────────────────────────
    def select_account(self):
        logger.info("Selecting account: simple|AUDIENCE...")

        if "AccuPoint Solutions" in self.driver.page_source or "Audience Lists" in self.driver.page_source:
            logger.info("Already in an account. Skipping selection.")
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
                logger.info(f"Selected account via '{label}'")
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

    # ── Step 3: Navigate to Teams + Impersonate ────────────────────────
    def impersonate(self):
        logger.info("Navigating directly to Teams URL...")
        self.driver.get("https://build.audiencelab.io/home/simple-audience/white-label/teams")
        time.sleep(8)

        # Wait for teams page to fully load
        self.wait.until(EC.presence_of_element_located((By.XPATH, "//input[@placeholder='Search by name...']")))
        self.save_screenshot("03_teams_page.png")
        logger.info("Teams page loaded.")

        # Use search box to find AccuPoint Solutions directly
        logger.info("Searching for AccuPoint Solutions...")
        search_box = self.driver.find_element(By.XPATH, "//input[@placeholder='Search by name...']")
        search_box.clear()
        search_box.send_keys("AccuPoint")
        time.sleep(3)
        self.save_screenshot("03_teams_search.png")

        # Find and click Manage on AccuPoint Solutions
        found = False
        try:
            manage_link = self.wait.until(EC.element_to_be_clickable(
                (By.XPATH, "//div[h3[contains(text(), 'AccuPoint')]]//a[contains(text(), 'Manage')]")
            ))
            manage_link.click()
            found = True
            logger.info("Found AccuPoint Solutions via search. Clicked Manage.")
        except Exception:
            # Fallback: search didn't filter, paginate to page 3
            logger.info("Search didn't filter. Paginating to page 3...")
            search_box.clear()
            time.sleep(2)
            for page_num in range(2):  # click Next twice to reach page 3
                try:
                    self.driver.execute_script("window.scrollTo(0, document.body.scrollHeight);")
                    time.sleep(1)
                    next_btn = self.driver.find_element(By.XPATH, "//button[@aria-label='Go to next page'] | //button[contains(@class, 'next')] | //a[text()='>']")
                    self.driver.execute_script("arguments[0].click();", next_btn)
                    time.sleep(4)
                except Exception as e:
                    logger.warning(f"Pagination click {page_num + 1} failed: {e}")

            self.save_screenshot("03_teams_page3.png")
            try:
                manage_link = self.wait.until(EC.element_to_be_clickable(
                    (By.XPATH, "//div[h3[contains(text(), 'AccuPoint')]]//a[contains(text(), 'Manage')]")
                ))
                manage_link.click()
                found = True
                logger.info("Found AccuPoint Solutions on page 3.")
            except Exception:
                pass

        if not found:
            self.save_screenshot("03_accupoint_not_found.png")
            raise Exception("AccuPoint Solutions team not found")

        time.sleep(3)
        self.save_screenshot("04_team_members.png")

        # Find target user row and click three dots
        logger.info(f"Finding user row for {IMPERSONATE_TARGET}...")
        actions = ActionChains(self.driver)
        user_row = self.driver.find_element(By.XPATH, f"//tr[contains(., '{IMPERSONATE_TARGET}')]")
        dots = user_row.find_element(By.TAG_NAME, "button")
        actions.move_to_element(dots).click().perform()
        time.sleep(2)

        # Click View
        logger.info("Clicking 'View'...")
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

        # Click Impersonate
        logger.info("Clicking 'Impersonate'...")
        imp_btn = self.wait.until(EC.element_to_be_clickable(
            (By.XPATH, "//button[contains(., 'Impersonate')]")
        ))
        self.driver.execute_script("arguments[0].click();", imp_btn)
        time.sleep(1)

        # Type CONFIRM
        logger.info("Typing CONFIRM...")
        confirm_inp = self.wait.until(EC.presence_of_element_located(
            (By.XPATH, "//input[@placeholder='Type CONFIRM'] | //input")
        ))
        confirm_inp.send_keys("CONFIRM")
        confirm_inp.send_keys(Keys.ENTER)

        logger.info("Impersonation initiated. Waiting for redirect...")
        time.sleep(10)

        # Navigate to AccuPoint home
        self.driver.get("https://build.audiencelab.io/home/accupoint-solutions")
        time.sleep(5)
        self.save_screenshot("05_impersonated.png")
        logger.info(f"Impersonation complete. Current URL: {self.driver.current_url}")

    # ── Step 4: Scan Segments (no DB writes) ───────────────────────────
    def scan_segments(self):
        logger.info("Navigating to Segments page...")
        self.driver.get("https://build.audiencelab.io/home/accupoint-solutions/segment")
        time.sleep(5)
        self.save_screenshot("06_segments_page.png")

        # Grab the page text to see what segments exist
        body_text = self.driver.find_element(By.TAG_NAME, "body").text
        logger.info(f"Segments page loaded. URL: {self.driver.current_url}")

        # Find all segment rows
        rows = self.driver.find_elements(By.XPATH, "//table//tbody//tr")
        logger.info(f"Found {len(rows)} segment rows")

        for i, row in enumerate(rows):
            try:
                cells = row.find_elements(By.TAG_NAME, "td")
                row_text = [c.text.strip() for c in cells]
                segment_name = row_text[0] if row_text else f"row_{i}"
                logger.info(f"Segment [{i}]: {' | '.join(row_text)}")

                # Try to find studio link in this row
                studio_url = None
                try:
                    studio_link = row.find_element(By.XPATH, ".//a[contains(@href, 'studio')]")
                    studio_url = studio_link.get_attribute("href")
                except Exception:
                    pass

                self.results.append({
                    "index": i,
                    "segment_name": segment_name,
                    "cells": row_text,
                    "studio_url": studio_url,
                })
            except Exception as e:
                logger.warning(f"Error reading row {i}: {e}")

        # Try clicking into first segment's studio to capture API URL
        if self.results and self.results[0].get("studio_url"):
            self._capture_segment_api(self.results[0])

        # Save results
        with open(RESULTS_FILE, "w") as f:
            json.dump(self.results, f, indent=2)
        logger.info(f"Results saved to {RESULTS_FILE} ({len(self.results)} segments)")

    def _capture_segment_api(self, segment):
        """Open a segment in studio and try to capture the API URL from network logs."""
        logger.info(f"Opening segment in studio: {segment['segment_name']}...")
        self.driver.get(segment["studio_url"])
        time.sleep(8)
        self.save_screenshot("07_studio.png")

        # Try clicking Show API
        try:
            show_api = self.wait.until(EC.element_to_be_clickable(
                (By.XPATH, "//button[contains(., 'Show API') or contains(., 'API')]")
            ))
            show_api.click()
            time.sleep(5)
        except Exception as e:
            logger.warning(f"Could not click Show API: {e}")

        # Scan performance logs for API URLs
        logs = self.driver.get_log("performance")
        api_urls = []
        for entry in logs:
            try:
                msg = json.loads(entry["message"])["message"]
                if msg["method"] == "Network.requestWillBeSent":
                    url = msg["params"]["request"]["url"]
                    if "api.audiencelab.io" in url and "segments" in url:
                        api_urls.append(url)
            except Exception:
                pass

        if api_urls:
            segment["api_url"] = api_urls[-1]
            logger.info(f"Captured API URL: {api_urls[-1]}")
        else:
            logger.warning("No API URL captured from network logs")

        # Also check page text
        try:
            page_text = self.driver.find_element(By.TAG_NAME, "body").text
            urls = re.findall(r'https?://api\.audiencelab\.io/segments/[^\s<>"]+', page_text)
            if urls:
                segment["api_url_from_page"] = urls[0]
                logger.info(f"API URL from page text: {urls[0]}")
        except Exception:
            pass

    # ── Capture API Token ──────────────────────────────────────────────
    def capture_token(self):
        logger.info("Scanning logs for API tokens...")
        logs = self.driver.get_log("performance")
        credentials = []

        for entry in logs:
            try:
                msg = json.loads(entry["message"])["message"]
                if msg["method"] == "Network.requestWillBeSent":
                    req = msg["params"]["request"]
                    url = req["url"]
                    headers = req.get("headers", {})
                    auth = None
                    apikey = None
                    for k, v in headers.items():
                        if k.lower() == "authorization":
                            auth = v
                        if k.lower() == "apikey":
                            apikey = v
                    if auth or apikey:
                        credentials.append({
                            "url": url,
                            "authorization": auth,
                            "api_key": apikey,
                        })
            except Exception:
                pass

        creds_file = os.path.join(os.path.dirname(__file__), "dry_run_credentials.json")
        with open(creds_file, "w") as f:
            json.dump(credentials, f, indent=2)
        logger.info(f"Saved {len(credentials)} credential entries to {creds_file}")

    def save_screenshot(self, filename):
        path = os.path.join(os.path.dirname(__file__), filename)
        self.driver.save_screenshot(path)
        logger.info(f"Screenshot: {path}")

    def close(self):
        self.driver.quit()


if __name__ == "__main__":
    logger.info("=" * 60)
    logger.info("DRY RUN: Login → Impersonate → Scan Segments (no DB writes)")
    logger.info(f"Login: {EMAIL}")
    logger.info(f"Target: {IMPERSONATE_TARGET}")
    logger.info("=" * 60)

    bot = ImpersonateDryRun(headless=False)

    try:
        bot.login()
        bot.select_account()
        bot.impersonate()
        bot.scan_segments()
        bot.capture_token()

        logger.info("=" * 60)
        logger.info("DRY RUN COMPLETE")
        logger.info(f"Segments found: {len(bot.results)}")
        logger.info(f"Results: {RESULTS_FILE}")
        logger.info("=" * 60)
    except Exception as e:
        logger.error(f"Bot failed: {e}")
        bot.save_screenshot("error_final.png")
        # Keep browser open for 60s to inspect
        logger.info("Browser staying open 60s for inspection...")
        time.sleep(60)
    finally:
        bot.close()
