#!/usr/bin/env python3
"""Build a reviewed, hash-pinned installer. No credentials in the code archive."""
import pathlib, tarfile, hashlib, sys, os
root=pathlib.Path(__file__).resolve().parents[1]
out=pathlib.Path(sys.argv[1]);out.mkdir(parents=True,exist_ok=True);os.chmod(out,0o700)
with tarfile.open(out/'code.tar','w') as archive:
    for section in ['app','public','bin','deploy']:
        for p in sorted((root/section).rglob('*')):
            if p.is_file() and '__pycache__' not in p.parts:archive.add(p,arcname=str(p.relative_to(root)))
    archive.add(root/'README.md',arcname='README.md')
source=root/'private/source-import.json'
(out/'source-import.json').write_bytes(source.read_bytes());os.chmod(out/'source-import.json',0o600)
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
installer=(root/'deploy/install.sh.in').read_text().replace('@@CODE_SHA@@',sha(out/'code.tar')).replace('@@IMPORT_SHA@@',sha(source))
(out/'install.sh').write_text(installer);os.chmod(out/'install.sh',0o700)
print('Package built. Code SHA256:',sha(out/'code.tar'))
