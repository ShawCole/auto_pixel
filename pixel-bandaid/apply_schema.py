import os
import logging
from dotenv import load_dotenv
from sqlalchemy import create_engine, text

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

load_dotenv()

def apply_schema():
    # Load config from .env
    host = os.getenv("MYSQL_HOST") or os.getenv("DB_HOST", "127.0.0.1")
    user = os.getenv("MYSQL_USER") or os.getenv("DB_USER")
    password = os.getenv("MYSQL_PASSWORD") or os.getenv("DB_PASS")
    port = os.getenv("MYSQL_PORT", 3306)
    dbname = os.getenv("PIXEL_DB") or os.getenv("DB_DATABASE") or os.getenv("DB_NAME", "pixel")

    logger.info(f"Connecting to database at {host}...")
    
    # Create connection string
    conn_url = f"mysql+mysqlconnector://{user}:{password}@{host}:{port}/{dbname}"
    engine = create_engine(conn_url)

    # Read SQL file
    try:
        with open("update_schema.sql", "r") as f:
            sql_content = f.read()
    except FileNotFoundError:
        logger.error("update_schema.sql not found!")
        return

    # Split into statements (basic split by ;)
    # Note: This is simple and might break on complex stored procs but works for our simple ALTER/CREATE commands
    statements = [s.strip() for s in sql_content.split(';') if s.strip()]

    with engine.connect() as conn:
        for stmt in statements:
            logger.info(f"Executing: {stmt[:50]}...")
            try:
                conn.execute(text(stmt))
                logger.info("Success.")
            except Exception as e:
                # Ignore "Duplicate column" or similar safe errors if they use IF NOT EXISTS, 
                # but SQLAlchemy might raise if the syntax is slightly off for the driver.
                # However, our SQL uses IF NOT EXISTS, so database shouldn't error on logic,
                # but we should catch to be safe and print.
                logger.warning(f"Warning/Error executing statement: {e}")
        
        conn.commit()
    
    logger.info("Schema update completed.")

if __name__ == "__main__":
    apply_schema()
