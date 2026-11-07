#!/usr/bin/env python3
"""Code-only UII update: no database, credentials, user uploads or port configuration."""
from pathlib import Path
import hashlib,json,tarfile
ROOT=Path(__file__).resolve().parents[1]
version=json.loads((ROOT/'app/ext/uii/portal/composer.json').read_text())['version']
out=ROOT/'release'/('uii-update-'+version);out.mkdir(exist_ok=True)
archive=out/('uii-update-'+version+'.tar.gz')
files=[]
for name in ['app/ext/uii/portal','app/styles/uii_modern','deployment','scripts','docs','tests','Dockerfile','README.md']:
    path=ROOT/name
    files.extend([path] if path.is_file() else [p for p in path.rglob('*') if p.is_file() and not p.is_symlink() and '__pycache__' not in p.parts and p.suffix!='.pyc'])
with tarfile.open(archive,'w:gz') as tar:
    for path in sorted(files):
        rel=path.relative_to(ROOT).as_posix()
        assert rel not in ['app/config.php','.env','compose.yaml'] and not rel.endswith(('.sql','.sqlite3'))
        info=tar.gettarinfo(str(path),arcname=rel);info.uid=info.gid=0;info.uname=info.gname='';info.mode=0o755 if path.suffix=='.sh' else 0o644
        with path.open('rb') as source:tar.addfile(info,source)
(out/'SHA256SUMS').write_text(hashlib.sha256(archive.read_bytes()).hexdigest()+'  '+archive.name+'\n')
(out/'README.md').write_text('# УИИ '+version+' — обновление существующего сайта\n\nИнструкции, права баг-репортов и отчёт: docs/GITHUB_UPDATES_RU.md в исходном проекте и архиве.\nСначала резервная копия, затем распаковка, пересборка web, миграция v017 и очистка кеша от www-data.\nВ архиве нет .env, config.php, compose.yaml, БД, кеша и файлов пользователей.\n')
(ROOT/'release/LATEST_UPDATE.txt').write_text(str(archive)+'\n')
print(out)
