from database import SimpleAudienceDatabase
from sqlalchemy import text, create_engine
from sshtunnel import SSHTunnelForwarder

def check_schema():
    db = SimpleAudienceDatabase()
    pkey = db._load_pkey()
    
    try:
        with SSHTunnelForwarder(
            (db.ssh_host, db.ssh_port),
            ssh_username=db.ssh_user,
            ssh_password=db.ssh_password,
            ssh_pkey=pkey,
            remote_bind_address=(db.mysql_host, db.mysql_port),
            set_keepalive=60
        ) as tunnel:
            conn_url = f"mysql+mysqlconnector://{db.mysql_user}:{db.mysql_password}@127.0.0.1:{tunnel.local_bind_port}/{db.pixel_db}"
            engine = create_engine(conn_url)
            with engine.connect() as conn:
                result = conn.execute(text("DESCRIBE pixel_sheets"))
                for row in result:
                    print(row)
    except Exception as e:
        print(f"Error checking schema: {e}")

if __name__ == "__main__":
    check_schema()
