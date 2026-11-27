import unittest,copy
from pair_v3 import merge
from test_daily_prepare import BASE,A,B
class Native(unittest.TestCase):
 def setUp(self):
  self.a,self.b=copy.deepcopy(A),copy.deepcopy(B)
  i=dict(map_id=28,region_id=4,quest_id=1,duration_index=0,runtime_map_id=28,runtime_region_id=4,runtime_quest_id=1,runtime_duration_index=0,bucket_key="28:4:0")
  for r in (self.a,self.b):
   r["identity"]=copy.deepcopy(i);r["start_ts"]=self.a["ts"];r["quest"]["duration_index"]=0
  self.b["quest"].update(quest_pid="next",quest_id=2,quest_finished=True,quest_end_result="win")
 def accepted(self):
  return merge(BASE,[self.a,self.b])[1].get("accepted_pairs",0)
 def test_pid_drift(self): self.assertEqual(self.accepted(),1)
 def test_native_conflict(self):
  self.b["identity"]["runtime_quest_id"]=3;self.assertEqual(self.accepted(),0)
 def test_time_conflict(self):
  self.b["start_ts"]=self.b["ts"];self.assertEqual(self.accepted(),0)
 def test_not_finished(self):
  self.b["quest"]["quest_finished"]=False;self.assertEqual(self.accepted(),0)
 def test_wrong_region(self):
  self.b["quest"]["region_id"]=5;self.assertEqual(self.accepted(),0)
 def test_fail(self):
  self.b["event"]="FAIL";self.assertEqual(merge(BASE,[self.a,self.b])[1]["failed_terminal"],1)
 def test_incomplete(self):
  self.assertEqual(merge(BASE,[self.a])[1]["incomplete"],1)
 def test_no_mutation(self):
  before=copy.deepcopy([self.a,self.b]);self.accepted();self.assertEqual(before,[self.a,self.b])
