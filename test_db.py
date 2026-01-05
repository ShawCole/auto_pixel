#!/usr/bin/env python3
import mysql.connector
import os
from dotenv import load_dotenv

load_dotenv()

def test_database():
    db_config = {
        'host': os.getenv('DB_HOST', '34.26.61.148'),
        'user': os.getenv('DB_USER', 'root'),
        'password': os.getenv('DB_PASS', 'AccuPoint01!'),
        'database': 'pixel',
        'connect_timeout': 60
    }
    
    try:
        print("Connecting to database...")
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor(dictionary=True)
        
        print("✅ Database connected successfully!")
        
        # Check table structure
        cursor.execute("DESCRIBE pixel_sheets")
        columns = cursor.fetchall()
        print(f"\nTable structure ({len(columns)} columns):")
        for col in columns:
            print(f"  {col['Field']} - {col['Type']}")
        
        # Check if client_website column exists
        client_website_exists = any(col['Field'] == 'client_website' for col in columns)
        print(f"\nclient_website column exists: {client_website_exists}")
        
        # Get sample data
        cursor.execute("SELECT id, client_name, client_website FROM pixel_sheets LIMIT 5")
        pixels = cursor.fetchall()
        print(f"\nSample pixels ({len(pixels)} found):")
        for pixel in pixels:
            print(f"  ID: {pixel['id']}, Name: {pixel['client_name']}, Website: {pixel['client_website']}")
        
        cursor.close()
        conn.close()
        
    except Exception as e:
        print(f"❌ Database error: {e}")

if __name__ == "__main__":
    test_database() 