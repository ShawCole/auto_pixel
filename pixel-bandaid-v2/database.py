import os
import logging
import re
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
                      query = text("SELECT client_name, pixel_name, sheet_id, client_website, segment_name, segment_api, on_platform_segment_url, workspace, workspace_url FROM pixel_sheets WHERE paused = 0 AND oplet_polling_active = 1 AND client_name != 'VettaFi'")
                      result = conn.execute(query)
                      pixels = []
                      for row in result:
                          pixels.append({
                              "client_name": row[0], 
                              "pixel_name": row[1],
                              "sheet_id": row[2],
                              "client_website": row[3],
                              "segment_name": row[4],
                              "segment_api": row[5],
                              "on_platform_segment_url": row[6],
                              "workspace": row[7],
                              "workspace_url": row[8]
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
                    query = text("SELECT client_name, pixel_name, sheet_id, client_website, segment_name, segment_api, on_platform_segment_url, workspace, workspace_url FROM pixel_sheets WHERE paused = 0 AND oplet_polling_active = 1 AND client_name != 'VettaFi'")
                    result = conn.execute(query)
                    pixels = []
                    for row in result:
                        pixels.append({
                            "client_name": row[0], 
                            "pixel_name": row[1],
                            "sheet_id": row[2],
                            "client_website": row[3],
                            "segment_name": row[4],
                            "segment_api": row[5],
                            "on_platform_segment_url": row[6],
                            "workspace": row[7],
                            "workspace_url": row[8]
                        })
                    print(f"Found {len(pixels)} active pixels.")
                    return pixels
        except Exception as e:
            logger.error(f"SSH Tunnel Error: {e}", exc_info=True)
            raise

    def get_active_pixels_extended(self):
        # Already updated get_active_pixels to return extended info
        return self.get_active_pixels()

    def update_pixel_metadata(self, client_name, pixel_name, metadata):
        """Updates the metadata for a pixel in the database."""
        try:
            if self.use_ssh:
                pkey = self._load_pkey()
                with SSHTunnelForwarder(
                    (self.ssh_host, self.ssh_port),
                    ssh_username=self.ssh_user,
                    ssh_password=self.ssh_password,
                    ssh_pkey=pkey,
                    remote_bind_address=(self.mysql_host, self.mysql_port),
                    set_keepalive=60
                ) as tunnel:
                    conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@127.0.0.1:{tunnel.local_bind_port}/{self.pixel_db}"
                    self._execute_update(conn_url, client_name, pixel_name, metadata)
            else:
                conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@{self.mysql_host}:{self.mysql_port}/{self.pixel_db}"
                self._execute_update(conn_url, client_name, pixel_name, metadata)
        except Exception as e:
            logger.error(f"Failed to update metadata for {pixel_name}: {e}", exc_info=True)
            raise

    def _execute_update(self, conn_url, client_name, pixel_name, metadata):
        engine = create_engine(conn_url)
        with engine.connect() as conn:
            # Prepare update fields
            updates = []
            params = {"cname": client_name, "pname": pixel_name}
            
            if metadata.get("oplet"):
                updates.append("last_event_at = :oplet")
                oplet_val = metadata["oplet"]
                try:
                    from datetime import datetime
                    if 'T' in oplet_val:
                        clean_date = oplet_val.replace('Z', '')
                        params["oplet"] = datetime.fromisoformat(clean_date)
                    else:
                        params["oplet"] = oplet_val
                except Exception as e:
                    logger.warning(f"Failed to parse oplet date {oplet_val}: {e}")
                    params["oplet"] = oplet_val
            
            if metadata.get("on_platform_segment_url"):
                updates.append("on_platform_segment_url = :surl")
                params["surl"] = metadata["on_platform_segment_url"]
            
            if metadata.get("segment_api"):
                updates.append("segment_api = :sapi")
                params["sapi"] = metadata["segment_api"]

            if metadata.get("segment_id"):
                updates.append("segment_id = :sid")
                params["sid"] = metadata["segment_id"]

            if metadata.get("segment_name"):
                updates.append("segment_name = :sname")
                params["sname"] = metadata["segment_name"]

            # Capture Row Count (Visitors)
            if metadata.get("row_count"):
                match = re.search(r'(\d[\d,]*)', metadata["row_count"])
                if match:
                    count = int(match.group(1).replace(',', ''))
                    updates.append("visitors = :count")
                    params["count"] = count

            if updates:
                sql_str = f"UPDATE pixel_sheets SET {', '.join(updates)} WHERE client_name = :cname AND pixel_name = :pname"
                logger.info(f"Executing Update: {sql_str}")
                sql = text(sql_str)
                conn.execute(sql, params)
                conn.commit()
                logger.info(f"Updated DB metadata for {pixel_name}.")
            else:
                logger.warning(f"No valid metadata updates found for {pixel_name}")

    def update_pixel_metadata(self, client_name, pixel_name, metadata):
        """Updates the metadata for a pixel in the database."""
        try:
            if self.use_ssh:
                pkey = self._load_pkey()
                with SSHTunnelForwarder(
                    (self.ssh_host, self.ssh_port),
                    ssh_username=self.ssh_user,
                    ssh_password=self.ssh_password,
                    ssh_pkey=pkey,
                    remote_bind_address=(self.mysql_host, self.mysql_port),
                    set_keepalive=60
                ) as tunnel:
                    conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@127.0.0.1:{tunnel.local_bind_port}/{self.pixel_db}"
                    self._execute_update(conn_url, client_name, pixel_name, metadata)
            else:
                conn_url = f"mysql+mysqlconnector://{self.mysql_user}:{self.mysql_password}@{self.mysql_host}:{self.mysql_port}/{self.pixel_db}"
                self._execute_update(conn_url, client_name, pixel_name, metadata)
        except Exception as e:
            logger.error(f"Failed to update metadata for {pixel_name}: {e}", exc_info=True)
            raise

    def _execute_update(self, conn_url, client_name, pixel_name, metadata):
        engine = create_engine(conn_url)
        with engine.connect() as conn:
            # Prepare update fields
            updates = []
            params = {"cname": client_name, "pname": pixel_name}
            
            if metadata.get("oplet"):
                updates.append("last_event_at = :oplet")
                oplet_val = metadata["oplet"]
                try:
                    from datetime import datetime
                    if 'T' in oplet_val:
                        clean_date = oplet_val.replace('Z', '')
                        params["oplet"] = datetime.fromisoformat(clean_date)
                    else:
                        params["oplet"] = oplet_val
                except Exception as e:
                    logger.warning(f"Failed to parse oplet date {oplet_val}: {e}")
                    params["oplet"] = oplet_val
            
            if metadata.get("on_platform_segment_url"):
                updates.append("on_platform_segment_url = :surl")
                params["surl"] = metadata["on_platform_segment_url"]
            
            if metadata.get("segment_api"):
                updates.append("segment_api = :sapi")
                params["sapi"] = metadata["segment_api"]

            if metadata.get("segment_id"):
                updates.append("segment_id = :sid")
                params["sid"] = metadata["segment_id"]

            if metadata.get("segment_name"):
                updates.append("segment_name = :sname")
                params["sname"] = metadata["segment_name"]

            # Capture Row Count (Visitors)
            if metadata.get("row_count"):
                match = re.search(r'(\d[\d,]*)', metadata["row_count"])
                if match:
                    count = int(match.group(1).replace(',', ''))
                    updates.append("visitors = :count")
                    params["count"] = count

            if updates:
                sql_str = f"UPDATE pixel_sheets SET {', '.join(updates)} WHERE client_name = :cname AND pixel_name = :pname"
                logger.info(f"Executing Update: {sql_str}")
                sql = text(sql_str)
                conn.execute(sql, params)
                conn.commit()
                logger.info(f"Updated DB metadata for {pixel_name}.")
            else:
                logger.warning(f"No valid metadata updates found for {pixel_name}")

    def upload_and_sync(self, events_csv, visitors_csv, target_db):
        print(f"Starting SSH Tunnel for database: {target_db}...")

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
