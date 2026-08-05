import sqlite3
import json

db = r"C:\Users\UVE\AppData\Local\ULGG\unlight-card-tracker\events.sqlite3"

session_id = "1ab53160-906a-443f-9428-7cf641cf91a9"

conn = sqlite3.connect(db)
conn.row_factory = sqlite3.Row

rows = conn.execute("""
SELECT
    sequence,
    event_type,
    payload_json,
    confidence
FROM events
WHERE session_id = ?
ORDER BY sequence
LIMIT 10
""", (session_id,)).fetchall()


for r in rows:
    print("=" * 50)
    print("SEQ:", r["sequence"])
    print("TYPE:", r["event_type"])
    print("CONF:", r["confidence"])
    print("PAYLOAD:")

    try:
        print(json.dumps(
            json.loads(r["payload_json"]),
            indent=2,
            ensure_ascii=False
        ))
    except Exception:
        print(r["payload_json"])