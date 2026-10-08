#!/usr/bin/env python3
"""Build a hash-pinned code update (app, public, bin) without data or credentials. Usage: build_update.py ZIEL"""
import pathlib,tarfile,hashlib,sys,os
root=pathlib.Path(__file__).resolve().parents[1]
out=pathlib.Path(sys.argv[1]);out.mkdir(parents=True,exist_ok=True);os.chmod(out,0o700)
with tarfile.open(out/'update.tar','w',format=tarfile.PAX_FORMAT) as archive:
    for section in ['app','public','bin']:
        for p in sorted((root/section).rglob('*')):
            if p.is_file() and '__pycache__' not in p.parts:
                info=archive.gettarinfo(str(p),arcname=str(p.relative_to(root)));info.uid=info.gid=0;info.uname=info.gname='root';info.mtime=0
                with p.open('rb') as f:archive.addfile(info,f)
sha=hashlib.sha256((out/'update.tar').read_bytes()).hexdigest()
(out/'update.sh').write_text((root/'deploy/update-documents.sh.in').read_text().replace('@@CODE_SHA@@',sha));os.chmod(out/'update.sh',0o700)
print('Update package built. SHA256:',sha)
