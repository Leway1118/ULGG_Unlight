import sqlite3

db = r"C:\Users\UVE\AppData\Local\ULGG\unlight-card-tracker\events.sqlite3"

conn = sqlite3.connect(db)
conn.row_factory = sqlite3.Row

rows = conn.execute("""
SELECT
    session_id,
    status,
    producer_type,
    producer_instance,
    tracker_active,
    started_at
FROM sessions
ORDER BY started_at DESC
LIMIT 10
""").fetchall()

for row in rows:
    print(dict(row))