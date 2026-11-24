import pathlib,sys,hashlib,json,subprocess,re,tempfile
root=pathlib.Path(__file__).resolve().parent
repo=root.parents[2]
out=pathlib.Path(sys.argv[1]);out.mkdir(parents=True,exist_ok=True)
commit=sys.argv[2];assert re.fullmatch('[0-9a-f]{40}',commit)
html=(root/'preview.html').read_bytes()
digest=hashlib.sha256(html).hexdigest()
helper=(repo/'lib/checkpoint2026/PreviewAccess.php').read_text()
entry=(repo/'pages/admin/checkpoint2026_preview.php').read_text()
assert helper.count('7fd90bb12814ca6d8e56d545bdd6005fc6b9d30c6dfe902787bcb6fb59b02bd1')==1
assert helper.count('0c3689fce88cc17fc4a803b2e04bc8f0a129d894')==1
assert helper.count("'version'=>'V0.3'")==1
helper=helper.replace('7fd90bb12814ca6d8e56d545bdd6005fc6b9d30c6dfe902787bcb6fb59b02bd1',digest).replace('0c3689fce88cc17fc4a803b2e04bc8f0a129d894',commit).replace("'version'=>'V0.3'","'version'=>'V0.4'")
assert entry.count("img-src data: blob:")==1
entry=entry.replace("img-src data: blob:","img-src 'self' data: blob:")
manifest={'version':'V0.4','commit':commit,'sha256':digest,'mode':'SYNTHETIC_ONLY'}
(out/'preview.html').write_bytes(html)
(out/'PreviewAccess.php').write_text(helper)
(out/'checkpoint2026_preview.php').write_text(entry)
(out/'manifest.json').write_text(json.dumps(manifest,separators=(',',':'))+'\n')
(out/'assets.json').write_bytes((root/'assets.json').read_bytes())
for f in ('PreviewAccess.php','checkpoint2026_preview.php'):
    subprocess.run(['php','-l',str(out/f)],check=True)
# No live flag or DB. Test the candidate helper against only a temporary fixture directory.
with tempfile.TemporaryDirectory() as t:
    t=pathlib.Path(t)
    for n in ('preview.html','manifest.json'):(t/n).write_bytes((out/n).read_bytes())
    code=r'require $argv[1]; $a="ULGG\\Checkpoint2026\\PreviewAccess"; $dir=$argv[2]; if($a::enabled($dir)||hash("sha256",$a::verifiedHtml($dir)??"")!==$argv[3])exit(1); echo "FT04V_RELEASE_PIN_PASS\n";'
    subprocess.run(['php','-r',code,str(out/'PreviewAccess.php'),str(t),digest],check=True)
    (t/'preview.html').write_text('tampered')
    subprocess.run(['php','-r',r'require $argv[1];if(\ULGG\Checkpoint2026\PreviewAccess::verifiedHtml($argv[2])!==null)exit(1);echo "FT04V_RELEASE_TAMPER_REJECT_PASS\n";',str(out/'PreviewAccess.php'),str(t)],check=True)
(out/'release-qa.json').write_text(json.dumps({'status':'PASS','commit':commit,'sha256':digest,'default_enabled':False,'note':'candidate only, no deployment'},indent=2)+'\n')
print('FT04V_RELEASE_READY_NOT_DEPLOYED',digest,flush=True)
