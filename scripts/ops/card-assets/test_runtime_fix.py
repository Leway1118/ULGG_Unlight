import ast,json,os,re,stat,shutil,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace
HERE=Path(__file__).parent
src=ast.parse((HERE/'release.py').read_text(encoding='utf8'))
ns={'os':os,'Path':Path,'stat':stat,'re':re,'json':json,'tempfile':tempfile,'shutil':shutil}
def need(x,m):
    if not x:raise RuntimeError(m)
ns['need']=need
ns['read']=lambda p:json.loads(p.read_text(encoding='utf8'))
import hashlib
ns['sha']=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
ns['is_active']=lambda n:False
ns['UNIT']='ulgg-card-assets-refresh'
for n in src.body:
    if isinstance(n,ast.FunctionDef) and n.name in ('rollback_residue','archive_rollback','service_audit'):
        exec(compile(ast.Module(body=[n],type_ignores=[]),'<release>', 'exec'),ns)
class RuntimeTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)
        ns.update({k:self.root/k for k in ('OPT','STATE','PRIVATE')})
        ns['EXPECTED']={self.root/'dashboard.php':'oldhash'}
        ns['NEW']={self.root/'unit.service':'unit.service'}
        ns['is_active']=lambda n:False
    def tearDown(self):self.tmp.cleanup()
    def test_absent_residue(self):
        self.assertFalse(ns['rollback_residue']())
    def test_partial_residue_refused(self):
        ns['OPT'].mkdir()
        with self.assertRaisesRegex(RuntimeError,'partial old installation'):ns['rollback_residue']()
    def test_archive_preserves_all(self):
        for k in ('OPT','STATE','PRIVATE'):
            ns[k].mkdir();(ns[k]/'evidence').write_text(k)
        with patch.dict(ns,{'rollback_residue':lambda:True}):
            moved=ns['archive_rollback']()
        for original,dest in moved:
            self.assertFalse(original.exists())
            self.assertEqual((dest/'evidence').read_text(),original.name)
    def test_archive_failure_restores_moved(self):
        for k in ('OPT','STATE','PRIVATE'):
            ns[k].mkdir();(ns[k]/'evidence').write_text(k)
        real=Path.rename
        def fail(self,dest):
            if self==ns['STATE']:raise OSError('injected')
            return real(self,dest)
        with patch.dict(ns,{'rollback_residue':lambda:True}),patch.object(Path,'rename',fail):
            with self.assertRaises(OSError):ns['archive_rollback']()
        self.assertTrue(all(ns[k].exists() for k in ('OPT','STATE','PRIVATE')))
    def test_service_security(self):
        text=(HERE/'ulgg-card-assets-refresh.service').read_text()
        for s in ('ProtectSystem=strict','ProtectHome=yes','NoNewPrivileges=yes','PrivateTmp=yes','ExecStart=/usr/bin/python3 -B'):
            self.assertIn(s,text)
        self.assertIn("'opcache.enable_cli=0'",(HERE/'sync.py').read_text())
    def test_service_gate_precedes_approval(self):
        code=(HERE/'release.py').read_text()
        self.assertIn('result=service_audit()',code)
        self.assertIn("'systemd-run','--quiet','--wait','--pipe','--collect'",code)
        self.assertIn("result.get('rehearsal',{}).get('status')=='ROLLED_BACK_REHEARSAL'",code)
        self.assertIn("if residue:archive_rollback()",code)
if __name__=='__main__':unittest.main(verbosity=2)
