"""Zalo Bridge via unofficial zlapi.

Authenticates using IMEI + session_cookies extracted from Zalo Web.
Credentials can be provided via:
  1. WordPress Admin Settings (fetched from REST API)
  2. Environment variables (ZALO_IMEI, ZALO_COOKIES)
  3. Local session.json file (auto-saved after first successful connection)
"""
import os
import sys
import json
import logging
import time
import requests
from zlapi import ZaloAPI
from zlapi.models import Message

logging.basicConfig(
    level=logging.INFO,
    format='[ZaloBridge] %(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

WP_BASE_URL = os.environ.get("WP_BASE_URL", "http://localhost:8080")
WP_BRIDGE_SECRET = os.environ.get("WP_BRIDGE_SECRET", "change-me-in-production")
SESSION_FILE = os.environ.get("SESSION_FILE", "/data/session.json")


def fetch_credentials_from_wp():
    """Fetch IMEI and cookies from WordPress REST API."""
    url = f"{WP_BASE_URL.rstrip('/')}/wp-json/dxtechai-claw-agent/v1/zalo/credentials"
    logger.info("Fetching Zalo credentials from WordPress...")
    try:
        resp = requests.get(url, headers={"X-Bridge-Secret": WP_BRIDGE_SECRET}, timeout=10)
        if resp.status_code == 200:
            data = resp.json()
            return data.get("imei"), data.get("cookies"), data.get("phone", "")
        logger.warning("WP credentials fetch returned %d: %s", resp.status_code, resp.text)
    except requests.exceptions.RequestException as e:
        logger.warning("Could not connect to WordPress: %s", e)
    return None, None, None


def load_session():
    """Load session data from local file."""
    if not os.path.isfile(SESSION_FILE):
        return None
    try:
        with open(SESSION_FILE, "r") as f:
            data = json.load(f)
        if data.get("imei") and data.get("cookies"):
            logger.info("Loaded saved session from %s", SESSION_FILE)
            return data
    except Exception as e:
        logger.warning("Could not load session file: %s", e)
    return None


def save_session(imei, cookies, phone=""):
    """Persist session data to local file."""
    try:
        os.makedirs(os.path.dirname(SESSION_FILE), exist_ok=True)
        with open(SESSION_FILE, "w") as f:
            json.dump({"imei": imei, "cookies": cookies, "phone": phone}, f)
        logger.info("Session saved to %s", SESSION_FILE)
    except Exception as e:
        logger.error("Failed to save session: %s", e)


def resolve_credentials():
    """Try to resolve credentials from multiple sources."""

    # Source 1: Local session file (fastest, no network)
    session = load_session()
    if session:
        return session["imei"], session["cookies"], session.get("phone", "")

    # Source 2: Environment variables
    env_imei = os.environ.get("ZALO_IMEI", "")
    env_cookies = os.environ.get("ZALO_COOKIES", "")
    if env_imei and env_cookies:
        logger.info("Using credentials from environment variables.")
        return env_imei, env_cookies, os.environ.get("ZALO_PHONE", "")

    # Source 3: WordPress REST API
    imei, cookies_data, phone = fetch_credentials_from_wp()
    
    # Auto-parse RAW cookie string if user pasted something like "zpw_sek=xxx; zpw_sekm=yyy"
    if cookies_data:
        parsed_cookies = {}
        if cookies_data.strip().startswith("{"):
            try:
                parsed_cookies = json.loads(cookies_data)
            except json.JSONDecodeError:
                pass
        
        # If not JSON, try parsing raw cookie string format
        if not parsed_cookies:
            for pair in cookies_data.split(";"):
                if "=" in pair:
                    k, v = pair.split("=", 1)
                    parsed_cookies[k.strip()] = v.strip()
        cookies_data = parsed_cookies

    if imei and cookies_data:
        return imei, cookies_data, phone

    return None, None, None


class ZaloBridge(ZaloAPI):
    """Zalo message listener that forwards messages to WordPress."""

    def __init__(self, imei, cookies, phone=""):
        self.wp_incoming_url = (
            f"{WP_BASE_URL.rstrip('/')}/wp-json/dxtechai-claw-agent/v1/zalo/incoming"
        )
        self._phone = phone

        super().__init__(phone or "0000000000", "not-used", imei=imei, cookies=cookies)
        logger.info("Zalo bridge connected. UID: %s", self.uid)

    def onMessage(self, mid, author_id, message, message_object, thread_id, thread_type):
        """Handle incoming text messages."""
        # Fix for infinite loop: Zalo's API sometimes returns uid=0. 
        # In 1-on-1 chats (thread_type=1), if the author is not the peer, it must be the bot itself.
        if str(author_id) == str(self.uid):
            return
        if str(thread_type) == "1" and str(author_id) != str(thread_id):
            return

        # Basic debounce: ignore if we just replied this exact text to not endlessly loop
        if getattr(self, "_last_reply", None) == message:
             return

        logger.info("Message from %s in %s: %s", author_id, thread_id, message[:100])

        payload = {
            "thread_id": str(thread_id),
            "sender_id": str(author_id),
            "message": message,
        }

        try:
            resp = requests.post(
                self.wp_incoming_url,
                json=payload,
                headers={"X-Bridge-Secret": WP_BRIDGE_SECRET},
                timeout=120,
            )

            if resp.status_code == 200:
                data = resp.json()
                reply_text = data.get("reply")
                if reply_text:
                    for i in range(0, len(reply_text), 3000):
                        chunk = reply_text[i:i + 3000]
                        self._last_reply = chunk
                        self.send(
                            Message(text=chunk),
                            thread_id=thread_id,
                            thread_type=thread_type,
                        )
                    logger.info("Reply sent successfully.")
                else:
                    logger.warning("WP returned 200 but no 'reply' field.")
            else:
                logger.error("WP error: %d %s", resp.status_code, resp.text)
        except requests.exceptions.RequestException:
            logger.exception("Failed to reach WordPress REST API")
        except Exception:
            logger.exception("Unexpected error in onMessage")


if __name__ == "__main__":
    logger.info("=== Zalo Bridge starting ===")

    imei, cookies, phone = None, None, ""

    while not imei or not cookies:
        imei, cookies, phone = resolve_credentials()
        if not imei or not cookies:
            logger.info(
                "Credentials not ready. Ensure IMEI + Cookies are configured in "
                "WordPress Admin > Claw Agent > Settings > Zalo. Retrying in 15s..."
            )
            time.sleep(15)

    # Persist for future restarts
    save_session(imei, cookies, phone)

    try:
        bridge = ZaloBridge(imei, cookies, phone)
        logger.info("Listening for messages...")
        bridge.listen()
    except Exception as e:
        logger.exception("Bridge crashed: %s", e)
        sys.exit(1)
