import sqlite3

db = r"C:\Users\UVE\AppData\Local\ULGG\unlight-card-tracker\events.sqlite3"

conn = sqlite3.connect(db)

for table in ["events", "sessions"]:
    print("\n====", table, "====")

    rows = conn.execute(
        f"PRAGMA table_info({table})"
    ).fetchall()

    for r in rows:
        print(r)