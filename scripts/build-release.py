"""Build one deterministic, credential-free WordPress release ZIP."""
from pathlib import Path
import hashlib, json, re, zipfile
ROOT=Path(__file__).resolve().parent.parent
version=re.search(r"define\('WKEL_VERSION',\s*'([^']+)'",(ROOT/'wk-event-leads.php').read_text(encoding='utf-8')).group(1)
allowed={
 'includes':{'.php'}, 'templates':{'.php','.html'}, 'languages':{'.pot','.po','.mo'},
 'assets':{'.css','.js','.png','.jpg','.jpeg','.svg','.gif','.webp','.woff','.woff2','.ttf','.ico'},
 'public':{'.html','.css','.js','.png','.jpg','.jpeg','.svg','.webp','.ico'},
}
files=[ROOT/'wk-event-leads.php',ROOT/'uninstall.php']
for folder,extensions in allowed.items():
 files.extend(p for p in (ROOT/folder).rglob('*') if p.is_file() and p.suffix.lower() in extensions)
files=sorted(files,key=lambda p:p.relative_to(ROOT).as_posix())
entries=[]
for p in files:
 if not p.resolve().is_relative_to(ROOT): raise RuntimeError('External symlink in package')
 data=p.read_bytes()
 if b'-----BEGIN PRIVATE KEY-----' in data or re.search(rb'\bre_[A-Za-z0-9]{24,}\b',data): raise RuntimeError('Credential-shaped content in package')
 entries.append((p.relative_to(ROOT).as_posix(),data))
dist=ROOT/'dist'; dist.mkdir(exist_ok=True)
archive=dist/f'wk-event-leads-{version}.zip'
with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as output:
 for name,data in entries:
  info=zipfile.ZipInfo('wk-event-leads/'+name,(2020,1,1,0,0,0)); info.compress_type=zipfile.ZIP_DEFLATED
  info.external_attr=0o100644<<16; output.writestr(info,data)
with zipfile.ZipFile(archive) as built:
 if built.testzip() is not None: raise RuntimeError('ZIP validation failed')
 for name,data in entries:
  if built.read('wk-event-leads/'+name)!=data: raise RuntimeError('Source/package mismatch')
digest=hashlib.sha256(archive.read_bytes()).hexdigest()
manifest={'version':version,'sha256':digest,'files':{name:hashlib.sha256(data).hexdigest() for name,data in entries}}
(dist/f'wk-event-leads-{version}.manifest.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8',newline='\n')
(dist/f'wk-event-leads-{version}.zip.sha256').write_text(digest+'  '+archive.name+'\n',encoding='utf-8',newline='\n')
print(json.dumps({'archive':str(archive),'sha256':digest,'files':len(entries)}))
