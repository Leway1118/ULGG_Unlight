import copy,sys,tempfile,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts'/'ops'/'card-assets'))
import sync as s
class NewsTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.old=s.PRIVATE;s.PRIVATE=Path(self.tmp.name)
  self.g='a'*64
  self.state={'generation':self.g,'cards_sha256':'b'*64,'baseline_created':True,'last_change_at':'2026-11-28T04:17:08+00:00','cards':{'added':710,'changed':0,'removed':0},'feats':{'added':1156,'changed':0,'removed':0}}
  self.receipt={'status':'PASS','generation':self.g,'cards_sha256':'b'*64,'verification':{'status':'PASS'},'summary':{'released_cards':633,'updated':{'unlight':26,'cost_unlight':2,'unlight_skill':87},'new_skills':8}}
  self.calls=[]
 def tearDown(self):s.PRIVATE=self.old;self.tmp.cleanup()
 def send(self,p):self.calls.append(p);return {'status':'DRAFT_CREATED','id':len(self.calls)}
 def test_initial_is_not_weekly_additions(self):
  p=s.news_payload(self.state,self.receipt);b=p['public_news']['body']
  self.assertIn('26',b);self.assertIn('633',b);self.assertIn('首次建立同步基準',b);self.assertNotIn('710',b);self.assertNotIn('1156',b)
 def test_verified_only(self):
  for key,value in [('status','FAIL'),('generation','x'*64),('verification',{'status':'FAIL'}),('cards_sha256','c'*64)]:
   r=copy.deepcopy(self.receipt);r[key]=value
   with self.assertRaises(RuntimeError):s.news_payload(self.state,r)
 def test_zero_change(self):
  st=copy.deepcopy(self.state);st.update(cards={},feats={},baseline_created=False)
  r=copy.deepcopy(self.receipt);r['summary']['updated']={};r['summary']['new_skills']=0
  self.assertIsNone(s.news_payload(st,r))
 def test_repeat_same_generation(self):
  s.news_after_verified(self.state,self.receipt,self.send)
  s.news_after_verified(self.state,self.receipt,self.send)
  self.assertEqual(len(self.calls),1)
  self.assertEqual(s.read(s.PRIVATE/'news-drafts'/(self.g+'.json'))['status'],'DONE')
 def test_next_generation(self):
  s.news_after_verified(self.state,self.receipt,self.send)
  self.state['generation']=self.receipt['generation']='d'*64;self.state['baseline_created']=False
  s.news_after_verified(self.state,self.receipt,self.send)
  self.assertEqual(len(self.calls),2)
 def test_pending_retry(self):
  def fail(p):raise RuntimeError('simulated DB error')
  s.news_after_verified(self.state,self.receipt,fail)
  marker=s.PRIVATE/'news-drafts'/(self.g+'.json')
  self.assertEqual(s.read(marker)['status'],'PENDING')
  s.news_after_verified(self.state,self.receipt,self.send)
  self.assertEqual(s.read(marker)['status'],'DONE');self.assertEqual(len(self.calls),1)
 def test_no_draft_from_failed_receipt(self):
  self.receipt['status']='FAIL'
  s.news_after_verified(self.state,self.receipt,self.send)
  self.assertFalse(self.calls)
 def test_older_pending_on_new_generation(self):
  s.news_after_verified(self.state,self.receipt,lambda p:(_ for _ in ()).throw(RuntimeError('test')))
  self.state['generation']=self.receipt['generation']='e'*64
  s.news_after_verified(self.state,self.receipt,self.send)
  self.assertEqual(len(self.calls),2)
if __name__=='__main__':unittest.main(verbosity=2)
