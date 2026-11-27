import ast, pathlib as pl, re, tempfile, subprocess as sp, types
p=pl.Path(r'D:\Download\quest-g09-release.py')
tree=ast.parse(p.read_text(encoding='utf-8'))
nodes=[n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name in ('need','validate_html','production_http')]
digest='a'*64
routes=['index.php?window=all','quest.php?window=all&region=1%3A2']
ns=dict(pl=pl,re=re,tempfile=tempfile,sp=sp,time=types.SimpleNamespace(time_ns=lambda:123456789,sleep=lambda _:None),PAGES=routes)
exec(compile(ast.Module(body=nodes,type_ignores=[]),str(p),'exec'),ns)
body='<html><span class="g2-estimate">'+('x'*600)+'</span></html>'
def run_case(name,events,expected=None):
 calls=[]
 def mock(*a):
  calls.append(a)
  assert '--resolve' not in a and '-k' not in a and '--insecure' not in a
  assert a[-1].startswith('https://ulgg.online/') and 'qa_nonce=' in a[-1]
  e=events[min(len(calls)-1,len(events)-1)]
  if isinstance(e,int):raise sp.CalledProcessError(e,a,output='mock curl error')
  h,b=e
  pl.Path(a[a.index('--dump-header')+1]).write_bytes(h.encode())
  pl.Path(a[a.index('--output')+1]).write_bytes(b.encode())
  return '200'
 ns['cmd']=mock
 try:
  r=ns['production_http'](digest)
  assert expected is None,(name,'unexpected success')
  assert len(r)==len(routes)
 except RuntimeError as exc:
  assert expected and expected in str(exc),(name,str(exc))
 print('PASS',name,'calls',len(calls))
 return len(calls)
good=('HTTP/2 200\r\nX-ULGG-Quest-Release: '+digest+'\r\n\r\n',body)
stale=('HTTP/2 200\r\nX-ULGG-Quest-Release: '+'b'*64+'\r\n\r\n',body)
bad=(good[0],'<html>Fatal error</html>')
run_case('verified public response',[good])
assert run_case('stale then fresh',[stale,good])==3
assert run_case('TLS fails closed',[60],'curl exit 60')==1
assert run_case('stale digest rejected',[stale],'missing or stale')==5
assert run_case('PHP error rejected',[bad],'incomplete HTML')==5
run_case('proxy header cannot prove final release',[('HTTP/1.1 200 Connection established\r\nX-ULGG-Quest-Release: '+digest+'\r\n\r\nHTTP/2 200\r\n\r\n',body)],'missing or stale')
print('TOTAL 6 PASS')
