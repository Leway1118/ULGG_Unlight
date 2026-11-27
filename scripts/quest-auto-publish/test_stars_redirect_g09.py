import ast,pathlib as pl,re,tempfile,subprocess as sp,types
p=pl.Path(r'D:\Download\quest-g09-release.py')
tree=ast.parse(p.read_text(encoding='utf-8'))
nodes=[n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name in ('need','validate_html','production_http')]
d='a'*64
ns=dict(pl=pl,re=re,tempfile=tempfile,sp=sp,time=types.SimpleNamespace(time_ns=lambda:123,sleep=lambda _:None),PAGES=['quest.php?window=all&region=1%3A2','stars.php?window=all','teams.php'])
exec(compile(ast.Module(body=nodes,type_ignores=[]),str(p),'exec'),ns)
good='<html><span class="g2-estimate">'+('x'*600)+'</span><section id="stars"><table class="qpub-heatmap"></table></section></html>'
def test(name,location,body=good,sha=d,expected=None):
 calls=[]
 def mock(*a):
  url=a[-1];calls.append(url)
  assert url.startswith('https://ulgg.online/pages/quest_public/v1.4.1/')
  assert '-k' not in a and '--location' not in a
  if '/stars.php?' in url:
   status='302';h='HTTP/2 302\r\nLocation: '+location+'\r\n\r\n';b=''
  else:
   status='200';h='HTTP/2 200\r\nX-ULGG-Quest-Release: '+(sha if '/index.php?' in url else d)+'\r\n\r\n';b=body if '/index.php?' in url else good
  pl.Path(a[a.index('--dump-header')+1]).write_bytes(h.encode())
  pl.Path(a[a.index('--output')+1]).write_bytes(b.encode())
  return status
 ns['cmd']=mock
 try:
  r=ns['production_http'](d)
  assert expected is None and r[1]['redirected'] and len(r)==3
 except RuntimeError as exc:
  assert expected and expected in str(exc),(name,str(exc))
 print('PASS',name,len(calls))
 return calls
loc='/pages/quest_public/v1.4.1/index.php#stars'
assert len(test('known redirect and verified destination',loc))==4
calls=test('external redirect rejected','https://example.com/',expected='unexpected Stars redirect')
assert not any('example.com' in url for url in calls)
test('wrong internal redirect rejected','/pages/quest_public/v1.4.1/teams.php',expected='unexpected Stars redirect')
test('stale destination rejected',loc,sha='b'*64,expected='missing or stale')
test('missing stars section rejected',loc,body=good.replace('id="stars"','id="other"'),expected='Stars section missing')
test('missing heatmap rejected',loc,body=good.replace('qpub-heatmap','other'),expected='Stars heatmap missing')
print('TOTAL 6 PASS redirect tests')
