from pathlib import Path
import re
import shutil
import py_compile
from datetime import datetime

ROOT = Path(__file__).resolve().parent
ROUTES = ROOT / "raid_bot" / "api" / "routes.py"

if not ROUTES.exists():
    raise SystemExit(f"[PATCH STOP] file not found: {ROUTES}")

text = ROUTES.read_text(encoding="utf-8")

# 網站 API 專用：
# 不改 formatter 預設值，因此 Discord 仍維持原本最多 5 個。
pattern = re.compile(
    r'(["\']state_labels["\']\s*:\s*)format_status_labels\(\s*raid\.statuses\s*\)'
)

matches = list(pattern.finditer(text))
if not matches:
    # 若已 patch 過，直接視為成功
    if re.search(
        r'["\']state_labels["\']\s*:\s*format_status_labels\(\s*raid\.statuses\s*,\s*limit\s*=\s*(?:99|999|9999)\s*\)',
        text
    ):
        print("[OK] routes.py already uses an expanded website-only status limit.")
        py_compile.compile(str(ROUTES), doraise=True)
        print(f"[PY_COMPILE OK] {ROUTES}")
        raise SystemExit(0)

    raise SystemExit(
        "[PATCH STOP] state_labels route call not found.\n"
        "Run:\n"
        "  grep -n \"state_labels\\|format_status_labels\" raid_bot/api/routes.py\n"
        "and send me the output."
    )

stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
backup = ROUTES.with_name(f"{ROUTES.name}.before_web_all_statuses_{stamp}")
shutil.copy2(ROUTES, backup)
print(f"[BACKUP] {backup}")

# 99 已遠高於遊戲實際可能同時存在的狀態數；
# formatter 的 Discord/default limit=5 完全不動。
new_text, count = pattern.subn(
    r'\1format_status_labels(raid.statuses, limit=99)',
    text
)

ROUTES.write_text(new_text, encoding="utf-8")
print(f"[PATCH] routes.py website state_labels limit=99 ({count} occurrence(s))")

py_compile.compile(str(ROUTES), doraise=True)
print(f"[PY_COMPILE OK] {ROUTES}")

print()
print("[DONE] Raid Observer website can display all practical statuses.")
print("[SAFE] status_text.py was NOT changed; Discord/default output remains unchanged.")
