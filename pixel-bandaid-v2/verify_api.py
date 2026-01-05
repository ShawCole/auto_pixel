import json
import requests
import os

def test_supabase_api():
    if not os.path.exists("api_credentials.json"):
        print("ERROR: api_credentials.json not found.")
        return

    with open("api_credentials.json", "r") as f:
        creds = json.load(f)

    # Supabase Base URL from the captured URL
    # https://oueetblvprenanzcxlil.supabase.co/rest/v1/...
    base_url = "https://oueetblvprenanzcxlil.supabase.co/rest/v1"
    
    headers = {
        "apikey": creds["api_key"],
        "Authorization": creds["authorization"],
        "Content-Type": "application/json"
    }

    print(f"Testing Supabase API connection...")
    print(f"Base URL: {base_url}")
    
    # Try to fetch segments
    try:
        # We'll try to list rows from segments table
        # Common Supabase table names: segments, audience_segments, etc.
        # Based on the app name "Segments"
        tables_to_try = ["segments", "audience_segments", "accounts"]
        
        for table in tables_to_try:
            print(f"\nAttempting to FETCH from table: {table}...")
            url = f"{base_url}/{table}?limit=1"
            response = requests.get(url, headers=headers)
            
            print(f"Status Code: {response.status_code}")
            if response.status_code == 200:
                print(f"SUCCESS! Data found in {table}:")
                print(json.dumps(response.json(), indent=2))
                # If we found data, we are good.
            else:
                print(f"Failed to fetch {table}: {response.text}")

    except Exception as e:
        print(f"An error occurred during API test: {e}")

if __name__ == "__main__":
    test_supabase_api()
