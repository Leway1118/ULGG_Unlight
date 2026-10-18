from pathlib import Path
import shutil
import py_compile
from datetime import datetime

ROOT = Path(__file__).resolve().parent

STATUS = ROOT / "raid_bot" / "formatter" / "status_text.py"
ROUTES = ROOT / "raid_bot" / "api" / "routes.py"

for p in (STATUS, ROUTES):
    if not p.exists():
        raise SystemExit(f"[PATCH STOP] file not found: {p}")

stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
for p in (STATUS, ROUTES):
    backup = p.with_name(f"{p.name}.before_all_statuses_{stamp}")
    shutil.copy2(p, backup)
    print(f"[BACKUP] {backup}")

# 1) status_text.py
# Preserve default limit=5 for Discord/text callers,
# but allow limit=None to mean "no truncation".
text = STATUS.read_text(encoding="utf-8")

old_sig = '''def format_status_labels(
    statuses: Iterable[RaidStatus],
    *,
    now_ms: int | None = None,
    limit: int = 5,
) -> list[str]:
    if limit <= 0:
        return []
'''
new_sig = '''def format_status_labels(
    statuses: Iterable[RaidStatus],
    *,
    now_ms: int | None = None,
    limit: int | None = 5,
) -> list[str]:
    if limit is not None and limit <= 0:
        return []
'''

if old_sig not in text:
    raise SystemExit("[PATCH STOP] status_text signature anchor missing")
text = text.replace(old_sig, new_sig, 1)

old_tail = '''    tokens = [item[2] for item in prepared]
    truncated = len(tokens) > limit
    labels = tokens[:limit]
    if truncated and labels:
        labels[-1] = f"{labels[-1]}..."
    return labels
'''
new_tail = '''    tokens = [item[2] for item in prepared]

    if limit is None:
        return tokens

    truncated = len(tokens) > limit
    labels = tokens[:limit]
    if truncated and labels:
        labels[-1] = f"{labels[-1]}..."
    return labels
'''

if old_tail not in text:
    raise SystemExit("[PATCH STOP] status_text truncation anchor missing")
text = text.replace(old_tail, new_tail, 1)

STATUS.write_text(text, encoding="utf-8")
print("[PATCH] status_text.py: limit=None now means unlimited")

# 2) routes.py
# Website API explicitly requests all labels.
# Other formatter users retain default limit=5.
text = ROUTES.read_text(encoding="utf-8")

old_route = '"state_labels": format_status_labels(raid.statuses),'
new_route = '"state_labels": format_status_labels(raid.statuses, limit=None),'

count = text.count(old_route)
if count < 1:
    raise SystemExit("[PATCH STOP] routes state_labels anchor missing")

text = text.replace(old_route, new_route)
ROUTES.write_text(text, encoding="utf-8")
print(f"[PATCH] routes.py: unlimited state_labels ({count} occurrence(s))")

# Syntax checks
for p in (STATUS, ROUTES):
    py_compile.compile(str(p), doraise=True)
    print(f"[PY_COMPILE OK] {p}")

print()
print("[DONE] Website Raid API now returns all active status labels.")
print("[SAFE] Existing Discord/text formatter default remains limit=5.")
