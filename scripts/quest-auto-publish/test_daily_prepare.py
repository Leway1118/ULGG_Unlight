import unittest,tempfile,json
from pathlib import Path
from daily_prepare import merge,activate,read_snapshot
def r(id,event,pid,t,quest=1):
 return dict(run_id=id,event=event,ts=t,elapsed_sec=10,quest=dict(quest_pid=pid,map_id=28,region_id=4,quest_id=quest))
BASE=[r("old","CLEAR","old","2026-09-21T00:00:00+00:00")]
A=r("new","START","p","2026-11-27T00:00:00+00:00")
B=r("new","CLEAR","p","2026-11-27T00:01:00+00:00")
class T(unittest.TestCase):
 def test_pair(self):self.assertEqual(merge(BASE,[A,B])[1]["map28_pairs"],1)
 def test_duplicate(self):
  rows,c,_=merge(BASE,[A,B,B]);self.assertEqual(len(rows),3);self.assertEqual(c["duplicate_events_removed"],1)
 def test_mismatch(self):self.assertEqual(len(merge(BASE,[A,dict(B,quest=dict(B["quest"],quest_pid="other"))])[0]),1)
 def test_missing(self):self.assertEqual(len(merge(BASE,[A])[0]),1)
 def test_overlap(self):self.assertEqual(len(merge(BASE,[dict(A,run_id="old"),dict(B,run_id="old")])[0]),1)
 def test_duplicate_pid(self):
  self.assertEqual(len(merge(BASE,[A,B,dict(A,run_id="new2"),dict(B,run_id="new2")])[0]),3)
 def test_partial_json(self):
  with tempfile.TemporaryDirectory() as t:
   p=Path(t)/"x";p.write_bytes(b'{"bad":')
   with self.assertRaises(ValueError):read_snapshot(p)
 def test_pointer(self):
  with tempfile.TemporaryDirectory() as t:
   p=Path(t);ok=lambda _:{"ok":True}
   activate(p,"old",ok,lambda _:True)
   before=(p/"candidate_pointer.json").read_bytes()
   with self.assertRaises(ValueError):activate(p,"bad",lambda _:{"ok":False},lambda _:True)
   with self.assertRaises(ValueError):activate(p,"bad",ok,lambda _:False)
   self.assertEqual(before,(p/"candidate_pointer.json").read_bytes())
   activate(p,"new",ok,lambda _:True)
   self.assertEqual(json.loads((p/"candidate_pointer.json").read_text())["previous"],"old")
if __name__=="__main__":unittest.main(verbosity=2)
