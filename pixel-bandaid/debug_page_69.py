
import requests
import json
import logging

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

API_URL = "https://api.audiencelab.io/segments/fc797e37-e549-4986-911e-921ac7378493"
API_KEY = "sk_aaaEJaKJZEzw39WFBTLPrvdnPZa5CmjMybZWzED4lY"
PAGE_SIZE = 10  # Reduced to debug timeout
TARGET_PAGE = 37

def debug_fetch():
    headers = {
        "x-api-key": API_KEY,
        "Content-Type": "application/json"
    }
    
    params = {
        "page": TARGET_PAGE,
        "limit": PAGE_SIZE
    }

    logger.info(f"Fetching Page {TARGET_PAGE} with limit {PAGE_SIZE}...")
    
    try:
        response = requests.get(API_URL, headers=headers, params=params, timeout=60)
        
        if response.status_code == 200:
            data = response.json()
            events = data.get('data', [])
            logger.info(f"Success! Retrieved {len(events)} events.")
            # Print first event to verify structure
            if events:
                logger.info("Sample Event 0:")
                logger.info(json.dumps(events[0], indent=2))
        else:
            logger.error(f"Failed: {response.status_code} - {response.text}")
            
    except Exception as e:
        logger.error(f"Exception: {str(e)}")

if __name__ == "__main__":
    debug_fetch()
