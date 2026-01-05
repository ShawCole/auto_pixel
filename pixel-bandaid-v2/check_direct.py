from database import SimpleAudienceDatabase
from sqlalchemy import text, create_engine

def check_direct():
    db = SimpleAudienceDatabase(use_ssh=False)
    
    print(f"Connecting directly to {db.mysql_host}...")
    try:
        conn_url = f"mysql+mysqlconnector://{db.mysql_user}:{db.mysql_password}@{db.mysql_host}:{db.mysql_port}/{db.pixel_db}"
        engine = create_engine(conn_url)
        with engine.connect() as conn:
            result = conn.execute(text("DESCRIBE pixel_sheets"))
            for row in result:
                print(row)
    except Exception as e:
        print(f"Direct connection failed: {e}")

if __name__ == "__main__":
    check_direct()
