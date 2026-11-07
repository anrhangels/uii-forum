#!/usr/bin/env python3
"""Code-only 1.1.0 update: never exports credentials, user uploads or a database."""
from pathlib import Path
import hashlib,json,tarfile
ROOT=Path(__file__).resolve().parents[1]
version=json.loads((ROOT/'app/ext/uii/portal/composer.json').read_text())['version']
if version!='1.1.0':raise SystemExit('Для текущей версии используйте scripts/package-update.py')
out=ROOT/'release'/('uii-cases-'+version);out.mkdir(exist_ok=True)
archive=out/('uii-cases-'+version+'.tar.gz')
allowed=['app/ext/uii/portal','app/styles/uii_modern','deployment','scripts','docs','tests','Dockerfile','README.md']
files=[]
for name in allowed:
    path=ROOT/name
    files.extend([path] if path.is_file() else [p for p in path.rglob('*') if p.is_file() and not p.is_symlink() and '__pycache__' not in p.parts and p.suffix!='.pyc'])
for path in files:
    rel=path.relative_to(ROOT).as_posix()
    assert rel not in ['app/config.php','.env','compose.yaml'] and not rel.endswith(('.sql','.sqlite3'))
with tarfile.open(archive,'w:gz') as tar:
    for path in sorted(files):
        info=tar.gettarinfo(str(path),arcname=path.relative_to(ROOT).as_posix());info.uid=info.gid=0;info.uname=info.gname='';info.mode=0o755 if path.suffix=='.sh' else 0o644
        with path.open('rb') as source:tar.addfile(info,source)
checksum=hashlib.sha256(archive.read_bytes()).hexdigest()
(out/'SHA256SUMS').write_text(checksum+'  '+archive.name+'\n')
(out/'README.md').write_text('# УИИ '+version+' — обновление существующего сайта\n\nИнструкция: `docs/CASES_1_1_0.md` в архиве и в исходном проекте.\nДо распаковки остановить web и сохранить БД и исходники.\nПакет не содержит данных пользователей, compose.yaml, config.php или .env.\nНужны пересборка web, миграция v015 и очистка кеша от www-data.\n')
print(out)
