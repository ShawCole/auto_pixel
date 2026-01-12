from database import SimpleAudienceDatabase
import sqlalchemy
from sqlalchemy import text
import os

db = SimpleAudienceDatabase(use_ssh=False)
db_host = os.environ.get("MYSQL_HOST") or os.environ.get("DB_HOST", "127.0.0.1")
db_user = os.environ.get("MYSQL_USER") or os.environ.get("DB_USER")
db_pass = os.environ.get("MYSQL_PASSWORD") or os.environ.get("DB_PASS")
db_name = os.environ.get("PIXEL_DB") or "pixel"

conn_url = f'mysql+mysqlconnector://{db_user}:{db_pass}@{db_host}:3306/{db_name}'
engine = sqlalchemy.create_engine(conn_url)

clients = ['AcquireUp', 'TruVestments', 'Focus_Distribution', 'accupoint_solutions_new']

with engine.connect() as conn:
    for c in clients:
        conn.execute(text('UPDATE pixel_sheets SET oplet_polling_active = 1 WHERE client_name = :c'), {'c': c})
    conn.commit()
    print('Successfully updated oplet_polling_active for target clients.')
