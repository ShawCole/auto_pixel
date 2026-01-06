import pandas as pd
import json
import os
from datetime import datetime

class SimpleAudienceProcessor:
    def __init__(self, visitors_suffix="_visitors"):
        self.visitors_suffix = visitors_suffix

    def process_csv(self, file_path):
        print(f"Processing file: {file_path}")
        df = pd.read_csv(file_path)
        # Standardize column names to lowercase early
        df.columns = [c.lower() for c in df.columns]

        # Drop rows with null uuid (required for SQL)
        if 'uuid' in df.columns:
            initial_count = len(df)
            df = df.dropna(subset=['uuid'])
            if len(df) < initial_count:
                print(f"Dropped {initial_count - len(df)} rows with null uuid.")
        
        # 1. Expand event_data JSON
        if 'event_data' in df.columns:
            print("Expanding event_data...")
            event_data_expanded = df['event_data'].apply(self._safe_json_parse)
            event_df = pd.json_normalize(event_data_expanded)
            # Merge expanded columns back
            df = pd.concat([df, event_df], axis=1)
        else:
            print("Warning: 'event_data' column not found.")
        
        # 2. Map Column Names to SQL Schema
        # personal_verified_emails -> deep_verified_emails
        if 'personal_verified_emails' in df.columns:
            df['deep_verified_emails'] = df['personal_verified_emails']
        
        # 3. Handle 'Visitors' file (Deduplicate by UUID)
        print("Creating visitors dataframe (deduplicated by UUID)...")
        # UUID is the unique identifier for a visitor
        visitors_df = df.drop_duplicates(subset=['uuid']).copy()
        
        # 4. Save files
        base_name = os.path.splitext(file_path)[0]
        visitors_path = f"{base_name}{self.visitors_suffix}.csv"
        
        # We save the processed events file (with expanded JSON) and the visitors file
        processed_events_path = f"{base_name}_processed.csv"
        
        df.to_csv(processed_events_path, index=False)
        visitors_df.to_csv(visitors_path, index=False)
        
        print(f"Processed events saved to: {processed_events_path}")
        print(f"Visitors file saved to: {visitors_path}")
        
        return processed_events_path, visitors_path

    def prepare_import_payload(self, file_path, pixel_id_override=None):
        """
        Reads the CSV and transforms it into the JSON structure expected by pixel_import.php.
        Output format: {"events": [ { ... }, { ... } ]}
        """
        print(f"Preparing import payload from: {file_path}")
        df = pd.read_csv(file_path)
        # Lowecase columns
        df.columns = [c.lower() for c in df.columns]
        
        if 'uuid' in df.columns:
            df = df.dropna(subset=['uuid']) # Ensure UUIDs exist
            
        # In-memory deduplication to avoid sending same event twice in one payload
        dedupe_cols = ['uuid', 'timestamp', 'event_type', 'pixel_id']
        existing_cols = [c for c in dedupe_cols if c in df.columns]
        if existing_cols:
            initial_len = len(df)
            df = df.drop_duplicates(subset=existing_cols)
            if len(df) < initial_len:
                print(f"Removed {initial_len - len(df)} duplicate events from CSV.")
                
        # Fill all NaNs with empty string to prevent JSON serialization errors
        df = df.fillna("")

        events = []
        
        for _, row in df.iterrows():
            # Parse event_data if it exists (it's usually a JSON string in the CSV)
            event_data = {}
            if 'event_data' in row and pd.notna(row['event_data']):
                event_data = self._safe_json_parse(row['event_data'])
                
            # Construct the event object
            # Note: pixel_import.php expects "resolution" or "event_data" keys?
            # Reading pixel_import.php: 
            # $pixel_data = isset($event['resolution']) ? $event['resolution'] : [];
            # So we should put the main pixel fields into 'resolution'.
            
            # We map the flat CSV columns back to the 'resolution' structure
            # But the CSV from SimpleAudience might ALREADY be flat.
            # Let's look at what pixel_import.php expects for standard fields vs resolution fields.
            
            # Standard fields in pixel_import.php loop:
            # pixel_id, hem_sha256, event_timestamp, event_type, ip_address, etc.
            
            # Helper to safely get string or empty from row, handling NaNs
            def safe_get(key, default=""):
                val = row.get(key)
                if pd.isna(val) or val is None:
                    return default
                return str(val)

            event_obj = {
                "pixel_id": pixel_id_override if pixel_id_override else safe_get('pixel_id'),
                "event_timestamp": safe_get('timestamp') or safe_get('event_timestamp') or datetime.now().isoformat(),
                "event_type": safe_get('event_type', 'page_view'),
                "hem_sha256": safe_get('hem_sha256'),
                "ip_address": safe_get('ip_address'),
                "activity_start_date": safe_get('activity_start_date'),
                "activity_end_date": safe_get('activity_end_date'),
                "activity_end_date": safe_get('activity_end_date'),
            }

            # Generate Deterministic Import Hash
            # Hash of: UUID + event_type + event_timestamp
            # This ensures that if we re-run the same CSV row, we get the exact same hash.
            import hashlib
            uid = safe_get('uuid')
            etype = event_obj['event_type']
            ets = event_obj['event_timestamp']
            
            hash_string = f"{uid}|{etype}|{ets}"
            import_hash = hashlib.sha256(hash_string.encode('utf-8')).hexdigest()
            event_obj['import_hash'] = import_hash

            # Gather resolution data (the big flat list of attributes)
            # We essentially dump the whole row into 'resolution' because pixel_import.php 
            # looks for keys like 'FIRST_NAME', 'personal_city', etc. inside $pixel_data (which comes from 'resolution')
            # The CSV columns are lowercase now (we did that above). 
            # pixel_import.php checks for UPPERCASE keys (e.g. $pixel_data['FIRST_NAME']) 
            # inside $pixel_data. Wait, lines 146+ of pixel_import.php show:
            # "uuid" => isset($pixel_data['UUID']) ? ...
            # So pixel_import.php expects UPPERCASE keys in 'resolution'.
            
            resolution_data = {}
            for col, val in row.items():
                if pd.notna(val):
                     # Convert to upper case key for PHP compatibility
                    resolution_data[col.upper()] = str(val)
            
            # Pass import_hash in resolution as well
            resolution_data['IMPORT_HASH'] = import_hash
            
            event_obj['resolution'] = resolution_data
            
            # Pass event_data as well if needed
            if event_data:
                event_obj['event_data'] = event_data
                
            events.append(event_obj)
            
        return {"events": events}

    def _safe_json_parse(self, x):
        try:
            if pd.isna(x) or not x:
                return {}
            return json.loads(x)
        except Exception:
            return {}

if __name__ == "__main__":
    # Test block
    sample_file = r"d:\Documents\THYNKData\AI BS\Antigravity\Antigravity Projects\example_data\783d940a-3463-4f2a-9c08-4c895e665f2c_20251222_222151.csv"
    if os.path.exists(sample_file):
        processor = SimpleAudienceProcessor()
        processor.process_csv(sample_file)
