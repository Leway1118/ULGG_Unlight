"""Local preview integrity and isolated pointer transaction tests; no VPS writes."""
import json, hashlib, tempfile, os, shutil, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parent
PREVIEW=ROOT/"compat_preview"
def load(p): return json.loads(p.read_bytes())
def verify(root):
    m=load(root/"preview_manifest.json")
    if m["status"]!="HOLD": raise ValueError("preview must remain HOLD")
    expected={f"windows/{w}/{k}.json" for w in ("today","7d","30d","all") for k in ("quests","regions")}
    files=m["files"]
    if len(files)!=len(expected) or {f["path"] for f in files}!=expected: raise ValueError("inventory")
    for f in files:
        p=root/f["path"]; raw=p.read_bytes()
        if len(raw)!=f["bytes"] or hashlib.sha256(raw).hexdigest()!=f["sha256"]: raise ValueError("integrity")
        d=json.loads(raw)
        if d["as_of_utc"]!=m["as_of_utc"] or d["count"]!=len(d["rows"]): raise ValueError("contract")
        if not isinstance(d["rows"],list) or not all(isinstance(r,dict) for r in d["rows"]): raise ValueError("rows")
        if d["kind"]=="quests":
            keys=[r["quest_key"] for r in d["rows"]]
            if len(keys)!=len(set(keys)): raise ValueError("duplicate quest identity")
    return True
def activate(sandbox, release):
    if release not in ("old","new"): raise ValueError("isolated release only")
    verify(sandbox/release)
    p=sandbox/"current.json"
    previous=load(p)["current"] if p.exists() else None
    tmp=sandbox/"current.tmp"
    tmp.write_text(json.dumps({"current":release,"previous":previous}))
    os.replace(tmp,p)
def rollback(sandbox):
    previous=load(sandbox/"current.json")["previous"]
    if previous is None: raise ValueError("no rollback")
    activate(sandbox,previous)
class Tests(unittest.TestCase):
    def test_integrity(self): self.assertTrue(verify(PREVIEW))
    def test_transaction(self):
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)
            for name in ("old","new"): shutil.copytree(PREVIEW,p/name)
            activate(p,"old"); activate(p,"new")
            self.assertEqual(load(p/"current.json")["current"],"new")
            rollback(p); self.assertEqual(load(p/"current.json")["current"],"old")
            before=(p/"current.json").read_bytes()
            with (p/"new/windows/all/quests.json").open("ab") as f: f.write(b" ")
            with self.assertRaises(ValueError): activate(p,"new")
            self.assertEqual(before,(p/"current.json").read_bytes())
    def test_rollback_corrupt(self):
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)
            for name in ("old","new"): shutil.copytree(PREVIEW,p/name)
            activate(p,"old"); activate(p,"new")
            before=(p/"current.json").read_bytes()
            (p/"old/windows/all/quests.json").unlink()
            with self.assertRaises(OSError): rollback(p)
            self.assertEqual(before,(p/"current.json").read_bytes())
if __name__=="__main__": unittest.main(verbosity=2)
