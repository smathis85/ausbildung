#!/usr/bin/env python3
"""Build a hash-pinned code update (app, public, bin) without data or credentials.
Usage: build_update.py ZIEL [--schedule TERMINPLAN.json]
The optional plan (personal names, never in git) is copied next to the package and imported once into an empty year."""
import pathlib,tarfile,hashlib,sys,os,shutil
root=pathlib.Path(__file__).resolve().parents[1]
args=sys.argv[1:]
schedule=None
if '--schedule' in args:
    i=args.index('--schedule');schedule=pathlib.Path(args[i+1]);del args[i:i+2]
out=pathlib.Path(args[0]);out.mkdir(parents=True,exist_ok=True);os.chmod(out,0o700)
with tarfile.open(out/'update.tar','w',format=tarfile.PAX_FORMAT) as archive:
    for section in ['app','public','bin']:
        for p in sorted((root/section).rglob('*')):
            if p.is_file() and '__pycache__' not in p.parts:
                info=archive.gettarinfo(str(p),arcname=str(p.relative_to(root)));info.uid=info.gid=0;info.uname=info.gname='root';info.mtime=0
                with p.open('rb') as f:archive.addfile(info,f)
sha=hashlib.sha256((out/'update.tar').read_bytes()).hexdigest()
schedule_sha=''
if schedule:
    shutil.copyfile(schedule,out/'terminplan.json');schedule_sha=hashlib.sha256((out/'terminplan.json').read_bytes()).hexdigest()
script=(root/'deploy/update-app.sh.in').read_text().replace('@@CODE_SHA@@',sha).replace('@@SCHEDULE_SHA@@',schedule_sha)
(out/'update.sh').write_text(script);os.chmod(out/'update.sh',0o700)
print('Update package built. SHA256:',sha,('· terminplan.json SHA256: '+schedule_sha) if schedule_sha else '')
