"""Install into a random QA table namespace, then remove only that namespace. No live tables changed."""
from pathlib import Path
import json,secrets,subprocess,tempfile,zipfile,os
root=Path(__file__).resolve().parents[1];kit=sorted((root/'release').glob('uii-install-*'))[-1]
php=['bash',str(root/'scripts/php-local.sh')]
# Credentials stay inside this process and its private temporary directory.
code="require 'runtime/board/config.php'; echo json_encode(compact('dbhost','dbport','dbuser','dbpasswd','dbname'));"
db=json.loads(subprocess.check_output(php+['-r',code],cwd=root,text=True));prefix='uiiqa_'+secrets.token_hex(4)+'_'
logpath=root/'runtime/test-results/fresh-install.log'
try:
 with tempfile.TemporaryDirectory(prefix='uii-fresh-test-') as directory:
  private=Path(directory);site=private/'site';site.mkdir()
  with zipfile.ZipFile(kit/'website.zip') as z:z.extractall(site)
  conf={'installer':{'admin':{'name':'Install_Admin','password':secrets.token_hex(12),'email':'install@example.test'},'board':{'lang':'ru','name':'QA: isolated UII','description':'Installation test'},'database':dict(db,dbms='mysqli',table_prefix=prefix),'server':{'server_name':'127.0.0.1','server_port':8092,'server_protocol':'http://','script_path':'/','cookie_secure':False,'force_server_vars':True},'email':{'enabled':False},'extensions':[]}}
  cfg=private/'install.yml';cfg.write_text(json.dumps(conf));cfg.chmod(0o600)
  with logpath.open('w') as log:
   logpath.chmod(0o600)
   command=php+[str(site/'install/phpbbcli.php'),'install',str(cfg),'--no-interaction']
   result=subprocess.run(command,cwd=site,stdout=log,stderr=subprocess.STDOUT,timeout=120)
   if result.returncode or not (site/'config.php').stat().st_size:raise RuntimeError('Native clean install failed; inspect private fresh-install.log')
   result=subprocess.run(php+[str(root/'scripts/php/finish-fresh-install.php'),str(site),str(private)],cwd=site,stdout=log,stderr=subprocess.STDOUT,timeout=120)
   if result.returncode:raise RuntimeError('UII finalization failed; inspect private fresh-install.log')
  assert not (site/'install').exists() and not (site/'.uii-fresh-install').exists()
  check=r"""require $argv[1];
$d=new mysqli($dbhost,$dbuser,$dbpasswd,$dbname);
foreach(['uii_navigation_ready'=>'1','default_lang'=>'ru','board_disable'=>'0','require_activation'=>'3'] as $k=>$v) {
 $r=$d->query("SELECT config_value FROM {$table_prefix}config WHERE config_name='".$d->real_escape_string($k)."'")->fetch_row();
 if (!$r || $r[0]!==$v) throw new RuntimeException('Wrong config: '.$k);
}
foreach(['uii/portal'=>1,'phpbb/viglink'=>0] as $name=>$active) {
 $r=$d->query("SELECT ext_active FROM {$table_prefix}ext WHERE ext_name='".$d->real_escape_string($name)."'")->fetch_row();
 if (!$r || (int)$r[0]!==$active) throw new RuntimeException('Wrong extension state');
}
$r=$d->query("SELECT s.style_path FROM {$table_prefix}styles s JOIN {$table_prefix}config c ON c.config_name='default_style' AND c.config_value=s.style_id")->fetch_row();
if (!$r || $r[0]!=='uii_modern') throw new RuntimeException('Wrong default style');
echo 'PASS clean installation, extension migrations, Russian defaults, private installer retirement, extension states and default style',PHP_EOL;
"""
  subprocess.run(php+['-r',check,str(site/'config.php')],cwd=root,check=True)
finally:
 cleanup="require 'runtime/board/config.php';$qa=$argv[1];if(!preg_match('/^uiiqa_[a-f0-9]{8}_$/D',$qa)||$qa===$table_prefix)exit(1);$d=new mysqli($dbhost,$dbuser,$dbpasswd,$dbname);$r=$d->query('SHOW TABLES');$n=0;while($row=$r->fetch_row()){if(str_starts_with($row[0],$qa)){$d->query('DROP TABLE `'.$d->real_escape_string($row[0]).'`');$n++;}}echo 'Isolated QA tables removed: '.$n,PHP_EOL;"
 subprocess.run(php+['-r',cleanup,prefix],cwd=root,check=True)
