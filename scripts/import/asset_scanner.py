import requests
import json
import hashlib
import os
from assets_manifest import ASSET_BASE, ASSET_PATHS

SAVE_ROOT = "assets_dump"
TIMEOUT = 20

os.makedirs(SAVE_ROOT, exist_ok=True)

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def fetch_asset(category, filename):
    url = f"{ASSET_BASE}{category}/{filename}"
    print(f"[GET] {url}")

    r = requests.get(url, timeout=TIMEOUT)
    if r.status_code != 200:
        print(f"  ❌ {r.status_code}")
        return

    data = r.content
    digest = sha256(data)

    save_dir = os.path.join(SAVE_ROOT, category)
    os.makedirs(save_dir, exist_ok=True)

    save_path = os.path.join(save_dir, filename)
    hash_path = save_path + ".sha256"

    old_hash = None
    if os.path.exists(hash_path):
        old_hash = open(hash_path).read().strip()

    if old_hash == digest:
        print("  └─ no change")
        return

    with open(save_path, "wb") as f:
        f.write(data)

    with open(hash_path, "w") as f:
        f.write(digest)

    print("  ✅ updated")

def main():
    for category, files in ASSET_PATHS.items():
        for f in files:
            fetch_asset(category, f)

if __name__ == "__main__":
    main()
