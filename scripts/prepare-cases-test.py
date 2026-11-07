#!/usr/bin/env python3
"""Fresh isolated phpBB SQLite installation; no production credentials or database."""
from pathlib import Path
import zipfile,json,secrets,subprocess,os,shutil
root=Path(__file__).resolve().parents[1];stage=Path(os.environ.get('UII_CASE_TEST_DIR',str(root/'runtime/cases-v2-sandbox'))).resolve();site=stage/'site';private=stage/'private'
assert not stage.exists(), 'Sandbox already exists: use it or choose a new isolated directory; never overwrite real data';site.mkdir(parents=True);private.mkdir(mode=0o700)
kit=Path(os.environ.get('UII_INSTALL_KIT',(root/'release/LATEST_INSTALL.txt').read_text().strip()))
with zipfile.ZipFile(kit/'website.zip') as z:z.extractall(site)
secret=secrets.token_hex(12)
conf={'installer':{'admin':{'name':'Test_Admin','password':secret,'email':'qa@example.test'},'board':{'lang':'ru','name':'QA: cases v2','description':'Isolated integration'},'database':{'dbms':'sqlite3','dbhost':str(private/'database.sqlite3'),'dbport':'','dbuser':'','dbpasswd':'','dbname':'','table_prefix':'qa_'},'server':{'server_name':'127.0.0.1','server_port':8094,'server_protocol':'http://','script_path':'/','cookie_secure':False,'force_server_vars':True},'email':{'enabled':False},'extensions':[]}}
config=private/'install.yml';config.write_text(json.dumps(conf));config.chmod(0o600)
php=['bash',str(root/'scripts/php-local.sh'),'-d','extension='+str(root/'runtime/usr/lib/php/modules/sqlite3.so'),'-d','extension='+str(root/'runtime/usr/lib/php/modules/gd.so')]
with (private/'installation.log').open('w') as log:
 result=subprocess.run(php+[str(site/'install/phpbbcli.php'),'install',str(config),'--no-interaction'],cwd=site,stdout=log,stderr=subprocess.STDOUT)
 assert result.returncode==0 and (site/'config.php').stat().st_size, 'See private installation.log'
 result=subprocess.run(php+[str(root/'scripts/php/finish-fresh-install.php'),str(site),str(private)],cwd=site,stdout=log,stderr=subprocess.STDOUT)
 assert result.returncode==0, 'See private installation.log'
(private/'account.json').write_text(json.dumps({'username':'Test_Admin','password':secret}));(private/'account.json').chmod(0o600)
# The app is isolated; extension and theme point at current source under development.
for path,target in ([] if os.environ.get('UII_TEST_PACKAGED')=='1' else [(site/'ext/uii',root/'app/ext/uii'),(site/'styles/uii_modern',root/'app/styles/uii_modern')]):
 shutil.rmtree(path);path.symlink_to(target,target_is_directory=True)
import sqlite3
with sqlite3.connect(private/'database.sqlite3') as db:
 db.execute("UPDATE qa_config SET config_value='1' WHERE config_name='load_tplcompile'")
print('Isolated SQLite phpBB installed:',stage)
