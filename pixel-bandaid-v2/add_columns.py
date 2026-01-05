import os
from sqlalchemy import create_engine, text
from dotenv import load_dotenv

load_dotenv()

def add_columns():
    mysql_host = os.getenv("MYSQL_HOST") or "34.26.61.148"
    mysql_port = int(os.getenv("MYSQL_PORT", 3306))
    mysql_user = os.getenv("MYSQL_USER") or "root"
    mysql_password = os.getenv("MYSQL_PASSWORD") or "AccuPoint01!"
    pixel_db = "pixel"

    print(f"Connecting directly to database at {mysql_host}:{mysql_port}...")
    
    conn_url = f"mysql+mysqlconnector://{mysql_user}:{mysql_password}@{mysql_host}:{mysql_port}/{pixel_db}"
    engine = create_engine(conn_url)
    
    try:
        with engine.connect() as conn:
            # Check if columns already exist
            check_sql = text("SHOW COLUMNS FROM pixel_sheets")
            result = conn.execute(check_sql)
            columns = [row[0] for row in result]
            
            if 'segment_name' not in columns:
                print("Adding segment_name column...")
                conn.execute(text("ALTER TABLE pixel_sheets ADD COLUMN segment_name VARCHAR(255) AFTER sheet_id"))
            else:
                print("segment_name column already exists.")
                
            if 'segment_api' not in columns:
                print("Adding segment_api column...")
                conn.execute(text("ALTER TABLE pixel_sheets ADD COLUMN segment_api TEXT AFTER segment_name"))
            else:
                print("segment_api column already exists.")
            
            conn.commit()
            print("Database update successful!")
            
    except Exception as e:
        print(f"Error adding columns: {e}")

if __name__ == "__main__":
    add_columns()
