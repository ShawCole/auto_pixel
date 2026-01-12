import mysql.connector
import os
from dotenv import load_dotenv

load_dotenv()

def migrate():
    mysql_host = os.getenv("MYSQL_HOST") or "34.26.61.148"
    mysql_port = int(os.getenv("MYSQL_PORT", 3306))
    mysql_user = os.getenv("MYSQL_USER") or "root"
    mysql_password = os.getenv("MYSQL_PASSWORD") or "AccuPoint01!"
    
    try:
        conn = mysql.connector.connect(
            host=mysql_host,
            user=mysql_user,
            password=mysql_password,
            database="pixel",
            port=mysql_port
        )
        cursor = conn.cursor()
        
        print("Adding 'workspace' and 'workspace_url' columns to 'pixel_sheets'...")
        
        # Add workspace column
        try:
            cursor.execute("ALTER TABLE pixel_sheets ADD COLUMN workspace VARCHAR(255) DEFAULT 'accupoint-solutions'")
            print("Added 'workspace' column.")
        except mysql.connector.Error as err:
            if err.errno == 1060: # Duplicate column name
                print("'workspace' column already exists.")
            else:
                raise err
                
        # Add workspace_url column
        try:
            cursor.execute("ALTER TABLE pixel_sheets ADD COLUMN workspace_url VARCHAR(255) DEFAULT 'https://app.simpleaudience.io/home/accupoint-solutions'")
            print("Added 'workspace_url' column.")
        except mysql.connector.Error as err:
            if err.errno == 1060: # Duplicate column name
                print("'workspace_url' column already exists.")
            else:
                raise err
        
        # Update existing VettaFi records to use simpleaudience workspace
        print("Updating VettaFi records to use 'simpleaudience' workspace...")
        cursor.execute("""
            UPDATE pixel_sheets 
            SET workspace = 'simpleaudience', 
                workspace_url = 'https://app.simpleaudience.io/home/simpleaudience'
            WHERE client_name LIKE '%vettafi%'
        """)
        print(f"Updated {cursor.rowcount} VettaFi records.")
        
        conn.commit()
        print("Migration complete.")
        
    except mysql.connector.Error as err:
        print(f"Error during migration: {err}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

if __name__ == "__main__":
    migrate()
