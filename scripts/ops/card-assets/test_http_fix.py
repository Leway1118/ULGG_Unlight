import ast, os, re, tempfile, subprocess, time, unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace
HERE=Path(__file__).resolve().parent
tree=ast.parse((HERE/'release.py').read_text(encoding='utf8'))
node=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='http')
def need(x,m):
    if not x:raise RuntimeError(m)
exec(compile(ast.Module(body=[node],type_ignores=[]),'<release-http>','exec'))
class TestHTTP(unittest.TestCase):
    def simulate(self, cases, expected=(200,)):
        steps=iter(cases);seen=[]
        def fake(args,**kwargs):
            self.assertNotIn('--resolve',args)
            self.assertNotIn('-k',args)
            self.assertTrue(args[-1].startswith('https://ulgg.online/pages/calculator.php?_ulgg_check='))
            seen.append(args);rc,status,body,err=next(steps)
            Path(args[args.index('-o')+1]).write_text(body,encoding='utf8')
            return SimpleNamespace(returncode=rc,stdout=status,stderr=err)
        with patch('subprocess.run',side_effect=fake),patch('time.sleep'):
            result=http('/pages/calculator.php',expected)
        return result,len(seen)
    def test_success(self):
        self.assertEqual(self.simulate([(0,'200','ok','')]),('ok',1))
    def test_retry(self):
        self.assertEqual(self.simulate([(7,'000','','connection refused'),(0,'503','',''),(0,'200','ok','')]),('ok',3))
    def test_permanent_failure(self):
        with self.assertRaisesRegex(RuntimeError,'HTTP verification failed'):self.simulate([(7,'000','','failed')]*3)
    def test_permission(self):
        self.assertEqual(self.simulate([(0,'403','Forbidden','')],(403,)),('Forbidden',1))
        with self.assertRaisesRegex(RuntimeError,'unexpected HTTP'):self.simulate([(0,'403','Forbidden','')])
    def test_invalid_content(self):
        for body in ('Fatal error: test','SQLSTATE[HY000]','<title>Just a moment</title>'):
            with self.assertRaises(RuntimeError):self.simulate([(0,'200',body,'')])
if __name__=='__main__':unittest.main(verbosity=2)
