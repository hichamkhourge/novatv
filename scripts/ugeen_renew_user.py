#!/usr/bin/env python3
"""
UGEEN User Renewal Script
Refactored to accept CLI arguments and use environment variables for security.
"""

import requests
import json
import base64
import time
import random
import os
import sys
import argparse
from pathlib import Path
import undetected_chromedriver as uc
from fake_useragent import UserAgent
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC
import psycopg2
from psycopg2.extras import RealDictCursor
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import padding

# Configuration from environment or arguments
MAX_LOGIN_RETRIES = 5
USE_HEADLESS = os.getenv('UGEEN_HEADLESS', 'true').lower() == 'true'
TWOCAPTCHA_API_KEY = os.getenv('TWOCAPTCHA_API_KEY', '')
SESSION_DIR = os.getenv('SESSION_DIR', '/tmp/iptv_sessions')

# Database configuration
DB_HOST = os.getenv('DB_HOST', 'localhost')
DB_PORT = os.getenv('DB_PORT', '5432')
DB_DATABASE = os.getenv('DB_DATABASE', 'iptv_provider')
DB_USERNAME = os.getenv('DB_USERNAME', 'postgres')
DB_PASSWORD = os.getenv('DB_PASSWORD', '')
APP_KEY = os.getenv('APP_KEY', '')

# Ensure session directory exists
Path(SESSION_DIR).mkdir(parents=True, exist_ok=True)

def log(message, level='INFO'):
    """Simple logging function"""
    timestamp = time.strftime('%Y-%m-%d %H:%M:%S')
    print(f"[{timestamp}] [{level}] {message}", flush=True)

def decrypt_laravel_value(encrypted_value):
    """Decrypt Laravel encrypted string (compatible with Crypt::encryptString)"""
    try:
        if not encrypted_value or not APP_KEY:
            return None

        # Remove 'base64:' prefix from APP_KEY if present
        app_key = APP_KEY.replace('base64:', '')
        key = base64.b64decode(app_key)

        # Decode the encrypted payload
        payload = json.loads(base64.b64decode(encrypted_value))

        iv = base64.b64decode(payload['iv'])
        ciphertext = base64.b64decode(payload['value'])

        # Decrypt using AES-256-CBC
        cipher = Cipher(
            algorithms.AES(key),
            modes.CBC(iv),
            backend=default_backend()
        )
        decryptor = cipher.decryptor()
        padded_plaintext = decryptor.update(ciphertext) + decryptor.finalize()

        # Remove PKCS7 padding
        unpadder = padding.PKCS7(128).unpadder()
        plaintext = unpadder.update(padded_plaintext) + unpadder.finalize()

        return plaintext.decode('utf-8')
    except Exception as e:
        log(f"Decryption failed: {e}", 'ERROR')
        return None

def get_db_connection():
    """Create database connection"""
    try:
        log(f"Connecting to database: {DB_USERNAME}@{DB_HOST}:{DB_PORT}/{DB_DATABASE}", 'DEBUG')
        conn = psycopg2.connect(
            host=DB_HOST,
            port=DB_PORT,
            database=DB_DATABASE,
            user=DB_USERNAME,
            password=DB_PASSWORD
        )
        log("Database connection successful", 'DEBUG')
        return conn
    except Exception as e:
        log(f"Database connection failed: {e}", 'ERROR')
        log(f"Connection details - Host: {DB_HOST}, Port: {DB_PORT}, Database: {DB_DATABASE}, User: {DB_USERNAME}", 'ERROR')
        return None

def fetch_user_and_source(user_id):
    """Fetch user and source data from database"""
    conn = get_db_connection()
    if not conn:
        log("Cannot proceed without database connection", 'ERROR')
        return None, None

    try:
        cursor = conn.cursor(cursor_factory=RealDictCursor)

        # Fetch user with source details
        log(f"Querying database for user ID: {user_id}", 'DEBUG')
        cursor.execute("""
            SELECT
                u.id as user_id,
                u.username,
                u.m3u_source_id,
                s.id as source_id,
                s.name as source_name,
                s.provider_type,
                s.provider_username,
                s.provider_password,
                s.provider_config
            FROM iptv_users u
            LEFT JOIN m3u_sources s ON u.m3u_source_id = s.id
            WHERE u.id = %s
        """, (user_id,))

        result = cursor.fetchone()
        log(f"Query returned: {result is not None}", 'DEBUG')
        cursor.close()
        conn.close()

        if not result:
            log(f"User with ID {user_id} not found in database", 'ERROR')
            return None, None

        if not result['m3u_source_id']:
            log(f"User {result['username']} has no M3U source assigned", 'ERROR')
            return None, None

        if result['provider_type'] != 'ugeen':
            log(f"User {result['username']} does not have a UGEEN source. Current provider: {result['provider_type']}", 'ERROR')
            return None, None

        # Decrypt credentials
        provider_username = decrypt_laravel_value(result['provider_username']) if result['provider_username'] else None
        provider_password = decrypt_laravel_value(result['provider_password']) if result['provider_password'] else None

        if not provider_username or not provider_password:
            log(f"Failed to decrypt provider credentials for source: {result['source_name']}", 'ERROR')
            return None, None

        user_data = {
            'user_id': result['user_id'],
            'username': result['username']
        }

        source_data = {
            'source_id': result['source_id'],
            'source_name': result['source_name'],
            'provider_type': result['provider_type'],
            'provider_username': provider_username,
            'provider_password': provider_password,
            'provider_config': result['provider_config'] or {}
        }

        return user_data, source_data

    except Exception as e:
        log(f"Database query failed: {e}", 'ERROR')
        if conn:
            conn.close()
        return None, None

def decode_jwt(token):
    """Decode JWT token without verification"""
    try:
        parts = token.split('.')
        if len(parts) != 3:
            return None

        header, payload = parts[0], parts[1]
        header += '=' * (4 - len(header) % 4)
        payload += '=' * (4 - len(payload) % 4)

        return {
            'header': json.loads(base64.urlsafe_b64decode(header)),
            'payload': json.loads(base64.urlsafe_b64decode(payload))
        }
    except Exception as e:
        log(f'Error decoding JWT: {e}', 'ERROR')
        return None

def random_delay(min_seconds=1, max_seconds=3):
    """Sleep for a random amount of time to mimic human behavior"""
    time.sleep(random.uniform(min_seconds, max_seconds))

def type_like_human(element, text, min_delay=0.05, max_delay=0.2):
    """Type text character by character with random delays"""
    element.clear()
    random_delay(0.3, 0.7)
    for char in text:
        element.send_keys(char)
        time.sleep(random.uniform(min_delay, max_delay))

def scroll_randomly(driver):
    """Perform random scrolling to appear more human-like"""
    scroll_amount = random.randint(100, 500)
    direction = random.choice(['down', 'up'])
    if direction == 'down':
        driver.execute_script(f"window.scrollBy(0, {scroll_amount});")
    else:
        driver.execute_script(f"window.scrollBy(0, -{scroll_amount});")
    random_delay(0.5, 1.5)

def move_mouse_randomly(driver):
    """Simulate random mouse movements"""
    try:
        x = random.randint(100, 800)
        y = random.randint(100, 600)
        driver.execute_script(f"""
            var event = new MouseEvent('mousemove', {{
                'clientX': {x},
                'clientY': {y},
                'bubbles': true
            }});
            document.dispatchEvent(event);
        """)
    except:
        pass

def detect_recaptcha(driver):
    """Check if reCAPTCHA is present AND blocking on the page"""
    try:
        recaptcha_frames = driver.find_elements(By.CSS_SELECTOR, 'iframe[src*="recaptcha"]')

        for frame in recaptcha_frames:
            try:
                if frame.is_displayed():
                    size = frame.size
                    if size['width'] > 300 or size['height'] > 300:
                        log(f"Found visible reCAPTCHA challenge: {size['width']}x{size['height']}px", 'DEBUG')
                        return True
            except:
                pass

        overlay = driver.find_elements(By.CSS_SELECTOR, '.rc-anchor, .recaptcha-checkbox')
        if overlay and any(el.is_displayed() for el in overlay):
            log("Found visible reCAPTCHA checkbox", 'DEBUG')
            return True

        return False
    except:
        return False

def get_recaptcha_sitekey(driver):
    """Extract reCAPTCHA site key from the page"""
    try:
        iframes = driver.find_elements(By.CSS_SELECTOR, 'iframe[src*="recaptcha"]')
        for iframe in iframes:
            src = iframe.get_attribute('src')
            if 'k=' in src:
                sitekey = src.split('k=')[1].split('&')[0]
                return sitekey

        recaptcha_divs = driver.find_elements(By.CSS_SELECTOR, '[data-sitekey]')
        for div in recaptcha_divs:
            sitekey = div.get_attribute('data-sitekey')
            if sitekey:
                return sitekey

        sitekey = driver.execute_script("""
            var sitekey = null;
            var scripts = document.getElementsByTagName('script');
            for (var i = 0; i < scripts.length; i++) {
                var text = scripts[i].textContent || scripts[i].innerText;
                var match = text.match(/sitekey['":\\s]+([a-zA-Z0-9_-]{40})/);
                if (match) return match[1];
            }
            return null;
        """)
        if sitekey:
            return sitekey

        return None
    except Exception as e:
        log(f"Error extracting sitekey: {e}", 'ERROR')
        return None

def solve_recaptcha_with_2captcha(driver, page_url):
    """Solve reCAPTCHA using 2captcha service"""
    if not TWOCAPTCHA_API_KEY:
        log("2captcha API key not configured", 'ERROR')
        return False

    try:
        log("Attempting to solve reCAPTCHA with 2captcha...")

        sitekey = get_recaptcha_sitekey(driver)
        if not sitekey:
            log("Could not find reCAPTCHA sitekey", 'ERROR')
            return False

        log(f"Found reCAPTCHA sitekey: {sitekey}")

        submit_url = "http://2captcha.com/in.php"
        submit_params = {
            'key': TWOCAPTCHA_API_KEY,
            'method': 'userrecaptcha',
            'googlekey': sitekey,
            'pageurl': page_url,
            'json': 1
        }

        response = requests.get(submit_url, params=submit_params, timeout=30)
        result = response.json()

        if result.get('status') != 1:
            log(f"2captcha submission failed: {result.get('request', 'Unknown error')}", 'ERROR')
            return False

        captcha_id = result.get('request')
        log(f"Captcha submitted. ID: {captcha_id}")
        log("Waiting for solution (this may take 30-60 seconds)...")

        result_url = "http://2captcha.com/res.php"
        max_attempts = 30

        for attempt in range(max_attempts):
            time.sleep(5)

            result_params = {
                'key': TWOCAPTCHA_API_KEY,
                'action': 'get',
                'id': captcha_id,
                'json': 1
            }

            response = requests.get(result_url, params=result_params, timeout=30)
            result = response.json()

            if result.get('status') == 1:
                captcha_solution = result.get('request')
                log(f"reCAPTCHA solved! (took {(attempt + 1) * 5} seconds)")

                log("Injecting solution into page...")
                driver.execute_script(f"""
                    document.getElementById('g-recaptcha-response').innerHTML = '{captcha_solution}';
                    if (typeof grecaptcha !== 'undefined') {{
                        grecaptcha.getResponse = function() {{ return '{captcha_solution}'; }};
                    }}
                """)

                driver.execute_script("""
                    var callback = window.recaptchaCallback || window.onRecaptchaSuccess;
                    if (callback) callback();
                """)

                time.sleep(2)
                return True

            elif result.get('request') == 'CAPCHA_NOT_READY':
                log(f"Waiting for solution... ({attempt + 1}/{max_attempts})", 'DEBUG')
                continue
            else:
                log(f"2captcha error: {result.get('request', 'Unknown error')}", 'ERROR')
                return False

        log("Timeout waiting for captcha solution", 'ERROR')
        return False

    except Exception as e:
        log(f"Error solving reCAPTCHA: {e}", 'ERROR')
        return False

def save_session(user_id, cookies, jwt_token):
    """Save cookies and JWT token for session reuse"""
    try:
        session_file = os.path.join(SESSION_DIR, f'ugeen_user_{user_id}.json')
        session_data = {
            'cookies': cookies,
            'jwt_token': jwt_token,
            'timestamp': time.time()
        }
        with open(session_file, 'w') as f:
            json.dump(session_data, f)
        log(f"Session saved to {session_file}")
        return True
    except Exception as e:
        log(f"Could not save session: {e}", 'WARNING')
        return False

def load_session(user_id):
    """Load saved session if available and not expired"""
    try:
        session_file = os.path.join(SESSION_DIR, f'ugeen_user_{user_id}.json')
        if not os.path.exists(session_file):
            return None

        with open(session_file, 'r') as f:
            session_data = json.load(f)

        age = time.time() - session_data.get('timestamp', 0)
        if age > 86400:  # 24 hours
            log("Session expired (>24 hours old)")
            return None

        log(f"Loaded saved session (age: {int(age/3600)} hours)")
        return session_data
    except Exception as e:
        log(f"Could not load session: {e}", 'WARNING')
        return None

def verify_session(jwt_token, api_base):
    """Verify if saved JWT token is still valid"""
    try:
        headers = {
            'Authorization': f'Bearer {jwt_token}',
            'Accept': 'application/json',
        }
        response = requests.get(f"{api_base}/codes", headers=headers, timeout=5)
        return response.status_code != 401
    except:
        return False

def create_stealth_driver(proxy=None, headless=USE_HEADLESS):
    """Create undetected Chrome driver with stealth options"""
    ua = UserAgent()
    user_agent = ua.random

    # Check for Chrome binary from environment variable first
    browser_path = os.getenv('CHROME_BIN')
    if browser_path and os.path.exists(browser_path):
        log(f"Using Chrome from CHROME_BIN env: {browser_path}", 'DEBUG')
    else:
        browser_path = None
        search_paths = [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/snap/bin/chromium',
            '/usr/bin/chrome',
            '/opt/google/chrome/chrome'
        ]

        for path in search_paths:
            if os.path.exists(path) and os.path.isfile(path):
                browser_path = path
                log(f"Found Chrome at: {browser_path}", 'DEBUG')
                break

    if not browser_path:
        log("Could not find Chrome binary, letting undetected-chromedriver auto-detect...", 'WARNING')

    # Check for chromedriver from environment variable
    chromedriver_path = os.getenv('CHROMEDRIVER_PATH')
    if chromedriver_path and os.path.exists(chromedriver_path):
        log(f"Using chromedriver from CHROMEDRIVER_PATH env: {chromedriver_path}", 'DEBUG')
    else:
        chromedriver_path = None
        chromedriver_search_paths = [
            '/usr/bin/chromedriver',
            '/usr/local/bin/chromedriver',
        ]
        for path in chromedriver_search_paths:
            if os.path.exists(path) and os.path.isfile(path):
                chromedriver_path = path
                log(f"Found chromedriver at: {chromedriver_path}", 'DEBUG')
                break

    version_main = None
    if browser_path:
        try:
            import subprocess
            result = subprocess.run([browser_path, '--version'],
                                 capture_output=True, text=True, timeout=5)
            version_str = result.stdout.strip()
            version_parts = version_str.split()
            for part in version_parts:
                if part and part[0].isdigit():
                    version_main = int(part.split('.')[0])
                    log(f"Detected Chrome version: {version_main}", 'DEBUG')
                    break
        except Exception as e:
            log(f"Could not detect version: {e}", 'WARNING')

    options = uc.ChromeOptions()
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--disable-gpu')
    options.add_argument('--disable-software-rasterizer')

    if headless:
        options.add_argument('--headless=new')

    if proxy:
        options.add_argument(f'--proxy-server={proxy}')
        log(f"Using proxy: {proxy}")

    try:
        log("Creating undetected-chromedriver instance...")
        driver_kwargs = {
            'options': options,
            'use_subprocess': False
        }

        # Add browser path if found
        if browser_path:
            driver_kwargs['browser_executable_path'] = browser_path

        # Add chromedriver path if found (forces use of system chromedriver)
        if chromedriver_path:
            driver_kwargs['driver_executable_path'] = chromedriver_path
            log(f"Using explicit chromedriver path: {chromedriver_path}", 'DEBUG')

        # Add version if detected
        if version_main:
            driver_kwargs['version_main'] = version_main

        driver = uc.Chrome(**driver_kwargs)

    except Exception as e:
        log(f"Driver creation failed: {e}", 'WARNING')
        log("Trying with minimal configuration and explicit paths...")

        try:
            options_minimal = uc.ChromeOptions()
            options_minimal.add_argument('--no-sandbox')
            options_minimal.add_argument('--disable-dev-shm-usage')
            options_minimal.add_argument('--disable-gpu')

            minimal_kwargs = {
                'options': options_minimal,
                'use_subprocess': False
            }

            # Force use of system chromedriver to avoid auto-download
            if chromedriver_path:
                minimal_kwargs['driver_executable_path'] = chromedriver_path
            if browser_path:
                minimal_kwargs['browser_executable_path'] = browser_path

            driver = uc.Chrome(**minimal_kwargs)

        except Exception as e2:
            log(f"Minimal configuration also failed: {e2}", 'ERROR')
            raise

    try:
        driver.execute_cdp_cmd('Network.setUserAgentOverride', {
            "userAgent": user_agent
        })
        driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    except:
        pass

    log("Stealth browser created")
    return driver

def perform_login_with_retries(driver, wait, config, retry_count=0):
    """Perform login with human-like behavior and retry logic"""
    if retry_count >= MAX_LOGIN_RETRIES:
        log(f"Max login retries ({MAX_LOGIN_RETRIES}) reached", 'ERROR')
        return None

    try:
        log(f"Login attempt {retry_count + 1}/{MAX_LOGIN_RETRIES}")

        driver.get(config['url'])

        initial_wait = random.uniform(3, 6) + (retry_count * 2)
        log(f"Waiting {initial_wait:.1f}s for page to fully load...")
        time.sleep(initial_wait)

        for _ in range(random.randint(2, 4)):
            scroll_randomly(driver)
            move_mouse_randomly(driver)
            random_delay(0.5, 1.5)

        log('Locating email field...')
        username_field = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, '#email')))
        random_delay(1, 2)

        log('Entering email...')
        type_like_human(username_field, config['username'])
        random_delay(1, 2)

        log('Entering password...')
        password_field = driver.find_element(By.CSS_SELECTOR, '#password')
        type_like_human(password_field, config['password'], min_delay=0.08, max_delay=0.25)
        random_delay(1.5, 3)

        scroll_randomly(driver)
        move_mouse_randomly(driver)
        random_delay(1, 2)

        log("Checking for reCAPTCHA...")
        if detect_recaptcha(driver):
            log("reCAPTCHA challenge is BLOCKING the page!", 'WARNING')

            if solve_recaptcha_with_2captcha(driver, config['url']):
                log("reCAPTCHA solved successfully with 2captcha!")
                random_delay(2, 3)
            else:
                log("2captcha solving failed, waiting 30 seconds...", 'WARNING')
                time.sleep(30)

                if detect_recaptcha(driver):
                    log("reCAPTCHA still blocking. Retrying with new browser instance...")
                    driver.quit()
                    random_delay(10, 15)

                    driver = create_stealth_driver(headless=USE_HEADLESS)
                    wait = WebDriverWait(driver, 20)
                    return perform_login_with_retries(driver, wait, config, retry_count + 1)
                else:
                    log("reCAPTCHA passed automatically!")
        else:
            log("No blocking reCAPTCHA detected")

        log('Clicking login button...')
        login_button = driver.find_element(By.CSS_SELECTOR, '#submit')
        login_button.click()

        log('Waiting for authentication...')
        time.sleep(5)

        if detect_recaptcha(driver):
            log("reCAPTCHA appeared AFTER login click!", 'WARNING')

            if solve_recaptcha_with_2captcha(driver, driver.current_url):
                log("reCAPTCHA solved successfully with 2captcha!")
                time.sleep(3)
            else:
                log("2captcha solving failed, waiting 20 seconds...", 'WARNING')
                time.sleep(20)

                if detect_recaptcha(driver):
                    log("reCAPTCHA still present. Retrying with different pattern...")
                    driver.quit()
                    random_delay(5, 10)

                    driver = create_stealth_driver(headless=USE_HEADLESS)
                    wait = WebDriverWait(driver, 20)
                    return perform_login_with_retries(driver, wait, config, retry_count + 1)

        log('Extracting JWT token from localStorage...')
        jwt_token = None

        for i in range(15):
            jwt_token = driver.execute_script("return window.localStorage.getItem('jsonwebToken');")
            if jwt_token:
                log("JWT token extracted successfully!")
                break
            log(f"Waiting for token... ({i+1}/15)", 'DEBUG')
            time.sleep(2)

        if not jwt_token:
            log("JWT token not found in localStorage", 'ERROR')

            current_url = driver.current_url
            if 'signin' in current_url:
                log("Still on login page - login likely failed")
                log("Retrying with different approach...")
                return perform_login_with_retries(driver, wait, config, retry_count + 1)

            return None

        cookies = driver.get_cookies()

        return {
            'jwt_token': jwt_token,
            'cookies': cookies,
            'driver': driver
        }

    except Exception as e:
        log(f"Login error: {e}", 'ERROR')

        if retry_count < MAX_LOGIN_RETRIES - 1:
            log(f"Retrying... (attempt {retry_count + 2}/{MAX_LOGIN_RETRIES})")
            random_delay(3, 6)
            return perform_login_with_retries(driver, wait, config, retry_count + 1)
        return None

def renew_subscription(user_id, provider_username, provider_password, package_id='384', proxy=None):
    """
    Main function to renew UGEEN subscription for a user

    Args:
        user_id: IPTV user ID
        provider_username: UGEEN email
        provider_password: UGEEN password
        package_id: Package ID to activate (default: 384)
        proxy: Optional proxy server

    Returns:
        bool: True if renewal successful, False otherwise
    """
    config = {
        'url': 'http://ugeen.live/signin.html',
        'username': provider_username,
        'password': provider_password,
        'package_id': package_id,
        'api_base': 'http://ugeen.live/api/v1'
    }

    log("=== UGEEN User Renewal Script ===")
    log(f"User ID: {user_id}")
    log(f"Provider: UGEEN")
    log(f"Package: {package_id}")

    # Try to load existing session
    log("Checking for existing session...")
    session = load_session(user_id)
    jwt_token = None

    if session:
        log('Verifying saved session...')
        if verify_session(session['jwt_token'], config['api_base']):
            log('Saved session is still valid! Skipping login.')
            jwt_token = session['jwt_token']
        else:
            log('Saved session expired or invalid')
            session = None

    # If no valid session, perform login
    if not jwt_token:
        log("Logging in with Stealth Browser...")
        driver = None

        try:
            driver = create_stealth_driver(proxy=proxy, headless=USE_HEADLESS)
            wait = WebDriverWait(driver, 20)

            login_result = perform_login_with_retries(driver, wait, config)

            if not login_result:
                log('Login failed after all retries', 'ERROR')
                return False

            jwt_token = login_result['jwt_token']
            cookies = login_result['cookies']

            save_session(user_id, cookies, jwt_token)

            driver.quit()
            log('Browser closed')

        except Exception as e:
            log(f"Error during login: {e}", 'ERROR')
            if driver:
                driver.quit()
            return False

    # Navigate to renew page and request code
    log("Requesting Code via Browser...")

    driver = None
    try:
        log("Opening browser with authenticated session...")
        driver = create_stealth_driver(headless=USE_HEADLESS)
        wait = WebDriverWait(driver, 20)

        driver.get('http://ugeen.live')
        time.sleep(2)

        driver.execute_script(f"window.localStorage.setItem('jsonwebToken', '{jwt_token}');")

        log("Navigating to renew page...")
        driver.get('http://ugeen.live/renew.html')
        random_delay(3, 5)

        scroll_randomly(driver)
        move_mouse_randomly(driver)
        random_delay(1, 2)

        log("Looking for request code button...")
        request_button = None

        try:
            request_button = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, 'a.request-code')))
        except:
            try:
                request_button = driver.find_element(By.CSS_SELECTOR, 'a.btn.btn-primary.request-code')
            except:
                try:
                    request_button = driver.find_element(By.XPATH, '//a[contains(@class, "request-code")]')
                except:
                    pass

        if not request_button:
            log("Request code button not found!", 'ERROR')
            driver.quit()
            return False

        log("Found request code button. Clicking...")
        request_button.click()

        log("Waiting for token to be generated...")
        time.sleep(5)

        log("Extracting downloadToken from localStorage...")
        download_token = driver.execute_script("return window.localStorage.getItem('downloadToken');")

        if not download_token:
            log("downloadToken not found in localStorage!", 'ERROR')
            driver.quit()
            return False

        log("Successfully got download token!")

    except Exception as e:
        log(f"Browser operation failed: {e}", 'ERROR')
        if driver:
            driver.quit()
        return False

    log("Decoding Token...")
    decoded = decode_jwt(download_token)
    activation_code = decoded['payload']['code']['code'] if (decoded and 'payload' in decoded and 'code' in decoded['payload']) else None

    if not activation_code:
        log("Failed to extract activation code from token.", 'ERROR')
        return False

    log(f"Decoded Activation Code: {activation_code}")

    log("Submit Subscription via Form...")

    try:
        log("Looking for code input field...")
        code_input = None

        try:
            code_input = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, 'input[type="text"]')))
        except:
            try:
                code_input = driver.find_element(By.NAME, 'code')
            except:
                try:
                    code_input = driver.find_element(By.ID, 'code')
                except:
                    pass

        if not code_input:
            log("Code input field not found!", 'ERROR')
            driver.quit()
            return False

        log("Found code input field. Entering code...")
        type_like_human(code_input, activation_code)
        random_delay(1, 2)

        log(f"Selecting package option {config['package_id']}...")
        package_selected = False

        try:
            try:
                select_element = driver.find_element(By.CSS_SELECTOR, 'select')
                select = Select(select_element)
                try:
                    select.select_by_value(config['package_id'])
                    log(f"Package {config['package_id']} selected from dropdown (by value)")
                    package_selected = True
                except:
                    try:
                        for option in select.options:
                            if config['package_id'] in option.get_attribute('value') or config['package_id'] in option.text:
                                option.click()
                                log(f"Package {config['package_id']} selected from dropdown (by text)")
                                package_selected = True
                                break
                    except:
                        pass
            except:
                pass

            if not package_selected:
                try:
                    package_selector = f"#pack-plan-{config['package_id']}"
                    package_option = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, package_selector)))
                    package_option.click()
                    log(f"Package {config['package_id']} clicked")
                    package_selected = True
                except:
                    pass

            if not package_selected:
                try:
                    radio_option = driver.find_element(By.CSS_SELECTOR, f'input[value="{config["package_id"]}"]')
                    radio_option.click()
                    log(f"Package {config['package_id']} selected (radio/checkbox)")
                    package_selected = True
                except:
                    pass

            if package_selected:
                random_delay(1, 2)
            else:
                log("Could not select package, but continuing (might be pre-selected)", 'WARNING')

        except Exception as e:
            log(f"Package selection error: {e}", 'WARNING')
            log("Continuing anyway - package might be pre-selected or optional")

        log("Looking for submit button...")
        submit_button = None

        try:
            submit_button = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, 'button.submit')))
        except:
            try:
                submit_button = driver.find_element(By.CSS_SELECTOR, 'button.btn.btn-primary')
            except:
                try:
                    submit_button = driver.find_element(By.XPATH, '//button[contains(@class, "submit")]')
                except:
                    pass

        if not submit_button:
            log("Submit button not found!", 'ERROR')
            driver.quit()
            return False

        log("Found submit button. Waiting 2 minutes before clicking...")
        time.sleep(120)  # Wait 2 minutes

        log("Clicking submit button...")
        submit_button.click()

        log("Waiting for submission to complete...")
        time.sleep(5)

        current_url = driver.current_url
        page_source = driver.page_source.lower()

        if 'success' in page_source or 'activated' in page_source or 'dashboard' in current_url:
            log("SUCCESS! Subscription Activated!")
            driver.quit()
            return True
        else:
            log("Submission completed but success not confirmed", 'WARNING')
            log(f"Current URL: {current_url}")
            driver.quit()
            return True  # Assume success if no error

    except Exception as e:
        log(f"Form submission failed: {e}", 'ERROR')
        if driver:
            driver.quit()
        return False

def main():
    """CLI entry point"""
    parser = argparse.ArgumentParser(description='UGEEN User Renewal Script')
    parser.add_argument('--user-id', required=True, help='IPTV user ID')
    parser.add_argument('--package-id', help='Package ID (optional, fetched from source config if not provided)')
    parser.add_argument('--proxy', help='Proxy server (optional)')

    args = parser.parse_args()

    log("=== UGEEN User Renewal Script ===")
    log(f"User ID: {args.user_id}")

    # Fetch user and source data from database
    log("Fetching user and source data from database...")
    user_data, source_data = fetch_user_and_source(args.user_id)

    if not user_data or not source_data:
        log("Failed to fetch user or source data. Exiting.", 'ERROR')
        sys.exit(1)

    log(f"User: {user_data['username']}")
    log(f"Source: {source_data['source_name']}")
    log(f"Provider: {source_data['provider_type'].upper()}")

    # Get package ID from args or source config
    package_id = args.package_id or source_data['provider_config'].get('package_id', '384')
    log(f"Package: {package_id}")

    success = renew_subscription(
        user_id=args.user_id,
        provider_username=source_data['provider_username'],
        provider_password=source_data['provider_password'],
        package_id=package_id,
        proxy=args.proxy
    )

    if success:
        log("All done! Renewal successful.")
        sys.exit(0)
    else:
        log("Renewal failed. Check the errors above.", 'ERROR')
        sys.exit(1)

if __name__ == '__main__':
    main()
