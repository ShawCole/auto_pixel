import os
import logging
from database import SimpleAudienceDatabase
from sqlalchemy import text, create_engine

logging.basicConfig(level=logging.INFO)

def check_db():
    # Use direct connection as per user's credentials
    db = SimpleAudienceDatabase(use_ssh=False)
    
    # Try direct query to see exact column names and values
    try:
        conn_url = f"mysql+mysqlconnector://{db.mysql_user}:{db.mysql_password}@{db.mysql_host}:{db.mysql_port}/{db.pixel_db}"
        engine = create_engine(conn_url)
        with engine.connect() as conn:
            res = conn.execute(text("SELECT * FROM pixel_sheets WHERE client_name = 'USA_Financial_NEW' OR pixel_name = 'USA_Financial_NEW' OR client_name LIKE '%usafinancial%'")).fetchall()
            print("\n=== RAW DB ROWS MATCHING 'USA_Financial_NEW' or 'usafinancial' ===")
            for row in res:
                print(dict(row._mapping))
                print("-" * 20)
    except Exception as e:
        print(f"Direct query failed: {e}")

if __name__ == "__main__":
    check_db()
