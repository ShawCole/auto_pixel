
import os
import logging
import pandas as pd
import paramiko
# Monkey patch paramiko to support sshtunnel with newer paramiko versions
if not hasattr(paramiko, "DSSKey"):
    paramiko.DSSKey = None


from sqlalchemy import create_engine, text
from sshtunnel import SSHTunnelForwarder
from dotenv import load_dotenv

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

load_dotenv()

class SimpleAudienceDatabase:
    def __init__(self, use_ssh=True):
        self.use_ssh = use_ssh
        self.ssh_host = os.getenv("SSH_HOST")
        self.ssh_user = os.getenv("SSH_USER")
        self.ssh_password = os.getenv("SSH_PASSWORD")
        self.ssh_key_path = os.getenv("SSH_KEY_PATH")
        self.ssh_port = int(os.getenv("SSH_PORT", 22))
        
        self.mysql_host = os.getenv("MYSQL_HOST") or os.getenv("DB_HOST", "127.0.0.1")
        self.mysql_port = int(os.getenv("MYSQL_PORT", 3306))
        self.mysql_user = os.getenv("MYSQL_USER") or os.getenv("DB_USER")
        self.mysql_password = os.getenv("MYSQL_PASSWORD") or os.getenv("DB_PASS")
        self.pixel_db = os.getenv("PIXEL_DB") or os.getenv("DB_DATABASE") or os.getenv("DB_NAME", "pixel")

    def _load_pkey(self):
        if not self.ssh_key_path or not os.path.exists(self.ssh_key_path):
            return None
        
        try:
            pkey = paramiko.RSAKey.from_private_key_file(self.ssh_key_path)
            print(f"Loaded RSA key from {self.ssh_key_path}")
            return pkey
        except Exception as e:
            print(f"Warning: Failed to load key: {e}")
        return None

    def get_active_pixels(self):
        print("Fetching active pixels from management database...")
        
        if not self.use_ssh:
             print(f"Connecting directly to MySQL at {self.mysql_host}...")
             conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@{self.mysql_host}:{self.mysql_port}/{self.pixel_db}"
             engine = create_engine(conn_url)
             try:
                 with engine.connect() as conn:
                      query = text("SELECT client_name, pixel_name, sheet_id, client_website, segment_id, on_platform_segment_url, segment_api FROM pixel_sheets WHERE paused = 0 AND visitors > 0 AND poll_segments = 1 AND client_name != 'VettaFi'")
                      result = conn.execute(query)
                      pixels = []
                      for row in result:
                          pixels.append({
                              "client_name": row[0], 
                              "pixel_name": row[1],
                              "sheet_id": row[2],
                              "client_website": row[3],
                              "segment_id": row[4],
                              "on_platform_segment_url": row[5],
                              "segment_api": row[6]
                          })
                      print(f"Found {len(pixels)} active pixels.")
                      return pixels
             except Exception as e:
                 logger.error(f"Direct DB Connection Error: {e}", exc_info=True)
                 raise

        pkey = self._load_pkey()
        
        try:
            with SSHTunnelForwarder(
                (self.ssh_host, self.ssh_port),
                ssh_username=self.ssh_user,
                ssh_password=self.ssh_password,
                ssh_pkey=pkey,
                remote_bind_address=(self.mysql_host, self.mysql_port),
                set_keepalive=60
            ) as tunnel:
                conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@127.0.0.1:{tunnel.local_bind_port}/{self.pixel_db}"
                engine = create_engine(conn_url)
                with engine.connect() as conn:
                    query = text("SELECT client_name, pixel_name, sheet_id, client_website, segment_id, on_platform_segment_url, segment_api FROM pixel_sheets WHERE paused = 0 AND visitors > 0 AND poll_segments = 1 AND client_name != 'VettaFi'")
                    result = conn.execute(query)
                    pixels = []
                    for row in result:
                        pixels.append({
                            "client_name": row[0], 
                            "pixel_name": row[1],
                            "sheet_id": row[2],
                            "client_website": row[3],
                            "segment_id": row[4],
                            "on_platform_segment_url": row[5],
                            "segment_api": row[6]
                        })
                    print(f"Found {len(pixels)} active pixels.")
                    return pixels
        except Exception as e:
            logger.error(f"SSH Tunnel Error: {e}", exc_info=True)
            raise

    def get_active_pixels_extended(self):
        # Already updated get_active_pixels to return extended info
        return self.get_active_pixels()

    def update_segment_metadata(self, client_name, segment_id, segment_api, segment_name):
        """Updates segment metadata in the management database."""
        logger.info(f"Updating segment metadata for {client_name}...")
        
        on_platform_segment_url = f"https://app.simpleaudience.io/home/accupoint-solutions/studio?segment={segment_id}"
        
        if not self.use_ssh:
            conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@{self.mysql_host}:{self.mysql_port}/{self.pixel_db}"
            engine = create_engine(conn_url)
            try:
                with engine.begin() as conn:
                    query = text("""
                        UPDATE pixel_sheets 
                        SET segment_id = :sid, 
                            on_platform_segment_url = :url, 
                            segment_api = :api,
                            segment_name = :name
                        WHERE client_name = :cname
                    """)
                    conn.execute(query, {
                        "sid": segment_id,
                        "url": on_platform_segment_url,
                        "api": segment_api,
                        "name": segment_name,
                        "cname": client_name
                    })
                logger.info("Metadata updated locally.")
            except Exception as e:
                logger.error(f"Failed to update metadata locally: {e}")
            return

        pkey = self._load_pkey()
        try:
            with SSHTunnelForwarder(
                (self.ssh_host, self.ssh_port),
                ssh_username=self.ssh_user,
                ssh_password=self.ssh_password,
                ssh_pkey=pkey,
                remote_bind_address=(self.mysql_host, self.mysql_port),
                set_keepalive=60
            ) as tunnel:
                conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@127.0.0.1:{tunnel.local_bind_port}/{self.pixel_db}"
                engine = create_engine(conn_url)
                with engine.begin() as conn:
                    query = text("""
                        UPDATE pixel_sheets 
                        SET segment_id = :sid, 
                            on_platform_segment_url = :url, 
                            segment_api = :api,
                            segment_name = :name
                        WHERE client_name = :cname
                    """)
                    conn.execute(query, {
                        "sid": segment_id,
                        "url": on_platform_segment_url,
                        "api": segment_api,
                        "name": segment_name,
                        "cname": client_name
                    })
                logger.info("Metadata updated via SSH.")
        except Exception as e:
            logger.error(f"Failed to update metadata via SSH: {e}")

    def upload_and_sync(self, events_csv, visitors_csv, target_db):
        print(f"Starting SSH Tunnel for database: {target_db}...")
        pkey = self._load_pkey()

        try:
            with SSHTunnelForwarder(
                (self.ssh_host, self.ssh_port),
                ssh_username=self.ssh_user,
                ssh_password=self.ssh_password,
                ssh_pkey=pkey,
                remote_bind_address=(self.mysql_host, self.mysql_port),
                set_keepalive=60
            ) as tunnel:
                print(f"SSH Tunnel active on local port: {tunnel.local_bind_port}")
                
                # Create SQLAlchemy engine for the specific client database
                conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@127.0.0.1:{tunnel.local_bind_port}/{target_db}"
                engine = create_engine(conn_url)
                
                # Define schema columns
                columns = [
                    'uuid', 'first_name', 'last_name', 'event_timestamp', 'event_type', 'hem_sha256', 
                    'ip_address', 'pixel_id', 'activity_start_date', 'activity_end_date', 
                    'referrer_url', 'personal_address', 'personal_city', 'personal_state', 
                    'personal_zip', 'personal_zip4', 'age_range', 'children', 'gender', 
                    'homeowner', 'married', 'net_worth', 'income_range', 'direct_number', 
                    'direct_number_dnc', 'mobile_phone', 'mobile_phone_dnc', 'personal_phone', 
                    'personal_phone_dnc', 'business_email', 'personal_emails', 
                    'deep_verified_emails', 'sha256_personal_email', 'sha256_business_email', 
                    'job_title', 'headline', 'department', 'seniority_level', 
                    'inferred_years_experience', 'company_name_history', 'job_title_history', 
                    'education_history', 'company_address', 'company_description', 
                    'company_domain', 'company_employee_count', 'company_linkedin_url', 
                    'company_name', 'company_phone', 'company_revenue', 'company_sic', 
                    'company_naics', 'company_city', 'company_state', 'company_zip', 
                    'company_industry', 'linkedin_url', 'twitter_url', 'facebook_url', 
                    'social_connections', 'skills', 'interests', 'skiptrace_match_score', 
                    'skiptrace_name', 'skiptrace_address', 'skiptrace_city', 'skiptrace_state', 
                    'skiptrace_zip', 'skiptrace_landline_numbers', 'skiptrace_wireless_numbers', 
                    'skiptrace_credit_rating', 'skiptrace_dnc', 'skiptrace_exact_age', 
                    'skiptrace_ethnic_code', 'skiptrace_language_code', 'skiptrace_ip', 
                    'skiptrace_b2b_address', 'skiptrace_b2b_phone', 'skiptrace_b2b_source', 
                    'skiptrace_b2b_website', 'element', 'percentage', 'referrer', 
                    'timestamp', 'title', 'url', 'valid_phones'
                ]

                # 1. Upload Events to Temp Table
                self._upload_temp(engine, events_csv, "temp_events", columns)
                
                # 2. Upload Visitors to Temp Table
                self._upload_temp(engine, visitors_csv, "temp_visitors", columns)
                
                # 3. Perform Sync Queries
                print("Syncing data to main tables...")
                with engine.begin() as conn:
                    # List of columns to insert
                    columns = [
                        'uuid', 'first_name', 'last_name', 'event_timestamp', 'event_type', 'hem_sha256', 
                        'ip_address', 'pixel_id', 'activity_start_date', 'activity_end_date', 
                        'referrer_url', 'personal_address', 'personal_city', 'personal_state', 
                        'personal_zip', 'personal_zip4', 'age_range', 'children', 'gender', 
                        'homeowner', 'married', 'net_worth', 'income_range', 'direct_number', 
                        'direct_number_dnc', 'mobile_phone', 'mobile_phone_dnc', 'personal_phone', 
                        'personal_phone_dnc', 'business_email', 'personal_emails', 
                        'deep_verified_emails', 'sha256_personal_email', 'sha256_business_email', 
                        'job_title', 'headline', 'department', 'seniority_level', 
                        'inferred_years_experience', 'company_name_history', 'job_title_history', 
                        'education_history', 'company_address', 'company_description', 
                        'company_domain', 'company_employee_count', 'company_linkedin_url', 
                        'company_name', 'company_phone', 'company_revenue', 'company_sic', 
                        'company_naics', 'company_city', 'company_state', 'company_zip', 
                        'company_industry', 'linkedin_url', 'twitter_url', 'facebook_url', 
                        'social_connections', 'skills', 'interests', 'skiptrace_match_score', 
                        'skiptrace_name', 'skiptrace_address', 'skiptrace_city', 'skiptrace_state', 
                        'skiptrace_zip', 'skiptrace_landline_numbers', 'skiptrace_wireless_numbers', 
                        'skiptrace_credit_rating', 'skiptrace_dnc', 'skiptrace_exact_age', 
                        'skiptrace_ethnic_code', 'skiptrace_language_code', 'skiptrace_ip', 
                        'skiptrace_b2b_address', 'skiptrace_b2b_phone', 'skiptrace_b2b_source', 
                        'skiptrace_b2b_website', 'element', 'percentage', 'referrer', 
                        'timestamp', 'title', 'url', 'valid_phones'
                    ]
                    
                    col_str = ", ".join([f"`{c}`" for c in columns])
                    
                    # Sync Events
                    print("Syncing events...")
                    event_sync_query = text(f"""
                    INSERT INTO superpixel_resolution_log ({col_str})
                    SELECT {col_str}
                    FROM temp_events te
                    WHERE NOT EXISTS (
                        SELECT 1 FROM superpixel_resolution_log srl
                        WHERE srl.pixel_id = te.pixel_id 
                        AND srl.event_timestamp = te.event_timestamp 
                        AND srl.uuid = te.uuid
                    );
                    """)
                    conn.execute(event_sync_query)
                    
                    # Sync Visitors
                    print("Syncing visitors...")
                    visitor_sync_query = text(f"""
                    INSERT IGNORE INTO superpixel_visitors ({col_str})
                    SELECT {col_str}
                    FROM temp_visitors tv
                    WHERE NOT EXISTS (
                        SELECT 1 FROM superpixel_visitors sv
                        WHERE sv.uuid = tv.uuid
                    );
                    """)
                    try:
                        conn.execute(visitor_sync_query)
                    except Exception as e:
                        print(f"Warning: Visitor sync issue: {e}")
                    
                    # 4. Cleanup Temp Tables
                    print("Cleaning up temporary tables...")
                    conn.execute(text("DROP TABLE IF EXISTS temp_events, temp_visitors"))
                
                    print("Sync completed successfully.")
        except Exception as e:
            logger.error(f"SSH Tunnel Error: {e}", exc_info=True)
            raise

    def _upload_temp(self, engine, csv_path, table_name, schema_columns):
        print(f"Uploading {csv_path} to {table_name}...")
        df = pd.read_csv(csv_path)
        
        # Ensure only columns in schema_columns are included, and missing ones are added as None
        # We need to intersect with existing columns to keep extra data if needed? 
        # Actually, for the Sync queries to work, we only need to ensure schema_columns are there.
        for col in schema_columns:
            if col not in df.columns:
                df[col] = None
        
        # We only keep the schema columns to keep the temp table clean and match the INSERT
        df_to_upload = df[schema_columns]
        df_to_upload.to_sql(table_name, engine, if_exists='replace', index=False)

if __name__ == "__main__":
    pass
