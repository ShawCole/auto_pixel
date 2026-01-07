
import os
from database import SimpleAudienceDatabase
from dotenv import load_dotenv

load_dotenv()

def find_vettafi():
    db = SimpleAudienceDatabase(use_ssh=False)
    pixels = db.get_active_pixels()
    print(f"Total pixels: {len(pixels)}")
    for p in pixels:
        print(f"PIXEL: name='{p['pixel_name']}', client='{p['client_name']}', oplet='{p.get('oplet')}'")
        if "vettafi" in p['pixel_name'].lower() or "vettafi" in p['client_name'].lower():
            print(f"*** MATCH: pixel_name='{p['pixel_name']}', client_name='{p['client_name']}'")

if __name__ == "__main__":
    find_vettafi()
