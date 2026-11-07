"""Inspect transfer kits using isolated fixtures, including full exports without real user data."""
from pathlib import Path
import gzip
import hashlib
import importlib.util
import io
import json
import shutil
import tempfile
import zipfile

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('spaceweb_builder',root/'scripts/prepare-spaceweb.py')
builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
for value in ['https://example.ru','../public_html','example.ru/path','example.ru:80',"bad.ru' OR 1=1"]:
    try:builder.domain(value)
    except ValueError:pass
    else:raise AssertionError('Invalid domain accepted: '+value)
assert builder.domain('ФОРУМ.РФ').startswith('xn--')
with tempfile.TemporaryDirectory(prefix='uii-spaceweb-test-') as directory:
    fixture=Path(directory);app=fixture/'app'
    files={'config.php':"<?php $table_prefix='phpbb_'; $dbpasswd='NEVER_PACKAGE_THIS';",'index.php':'<?php echo "forum";', '.htaccess':'# core guard', 'vendor/autoload.php':'<?php', 'ext/uii/portal/composer.json':'{"version":"0.9.1"}', 'ext/uii/portal/tests/test.php':'do not package', 'cache/production/secret.php':'SESSION_SECRET', 'files/.htaccess':'Require all denied', 'files/index.htm':'', 'files/legacy_file':'legacy contents', 'files/uii_evidence/'+'a'*32+'.bin':'private photo', 'store/.htaccess':'Require all denied','store/index.htm':'','store/old-backup.sql':'DATABASE_SECRET', 'images/avatars/upload/index.htm':''}
    for name,value in files.items():
        path=app/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(value)
    shutil.copytree(root/'deployment',fixture/'deployment');(fixture/'docs').mkdir();shutil.copyfile(root/'docs/spaceweb.md',fixture/'docs/spaceweb.md')
    builder.ROOT=fixture
    partial=builder.build(fixture/'code',host='forum.example.ru')
    manifest=json.loads((partial/'deployment.json').read_text());assert not manifest['complete_for_migration'] and not (partial/'database.sql.gz').exists()
    with zipfile.ZipFile(partial/'website.zip') as archive:
        names=set(archive.namelist());assert 'index.php' in names and 'app/index.php' not in names
        assert 'config.php' in names and '.uii-deploy.lock' in names and 'files/.htaccess' in names
        for name in names:
            data=archive.read(name)
            assert b'NEVER_PACKAGE_THIS' not in data and b'SESSION_SECRET' not in data and b'DATABASE_SECRET' not in data
        assert 'files/legacy_file' not in names and not any('uii_evidence' in name for name in names)
        config=archive.read('config.php').decode();assert 'SPACEWEB_DB_PASSWORD' in config and "'3308'" in config and 'UII_EVIDENCE_PATH' in config
        assert 'ext/uii/portal/tests/test.php' not in names
    dump=fixture/'fresh.sql';dump.write_bytes(b"/*M!999999\\- enable the sandbox mode */\nCREATE TABLE `x` (\n  `name` text CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;\nINSERT INTO `x` VALUES ('utf8mb3');\n")
    full=builder.build(fixture/'full',full=True,database=dump)
    manifest=json.loads((full/'deployment.json').read_text());assert manifest['complete_for_migration'] and manifest['photo_files']==1
    assert manifest['photo_quota_bytes']==524288000
    with zipfile.ZipFile(full/'website.zip') as archive:assert archive.read('files/legacy_file')==b'legacy contents'
    with zipfile.ZipFile(full/'private-files.zip') as archive:assert archive.read('uii-private/evidence/'+'a'*32+'.bin')==b'private photo'
    sql=gzip.decompress((full/'database.sql.gz').read_bytes());assert b'enable the sandbox' not in sql and b'CHARSET=utf8;' in sql and b"VALUES ('utf8mb3')" in sql
    assert 'REPLACE_WITH_DOMAIN' in (full/'hosting-settings.sql').read_text()
    for name,details in manifest['artifacts'].items():assert hashlib.sha256((full/name).read_bytes()).hexdigest()==details['sha256']
print('PASS SpaceWeb code/full kit, no source credentials/cache, separate private photos/DB, MySQL SQL compatibility, hashes and domain validation')
