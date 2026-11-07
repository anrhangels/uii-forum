<?php
if (PHP_SAPI!=='cli') {exit(1);}
$project=dirname(__DIR__);$sandbox=getenv('UII_UPDATER_TEST_DIR') ?: 'updater-sandbox';if (!preg_match('/^updater-sandbox(?:-[a-z0-9]+)?$/D',$sandbox)) {throw new RuntimeException('Invalid isolated sandbox name.');}$site=realpath($project.'/runtime/'.$sandbox.'/site');$private=realpath($project.'/runtime/'.$sandbox.'/private');
if (!$site || !$private || is_link($site.'/ext/uii')) {throw new RuntimeException('A separate, non-symlinked updater sandbox is required.');}
define('IN_PHPBB',true);$phpbb_root_path=$site.'/';$phpEx='php';require $site.'/common.php';
use uii\portal\service\web_updater as U;
use uii\portal\service\github_releases as G;
function check($value,$message) {if (!$value) {throw new RuntimeException($message);}}
function rows($table) {global $db;$r=$db->sql_query('SELECT * FROM '.$table);$out=[];while ($row=$db->sql_fetchrow($r)) {$out[]=$row;}$db->sql_freeresult($r);return $out;}
function sameData($before) {global $table_prefix;foreach ($before as $table=>$records) {check(rows($table_prefix.$table)===$records,'Protected data changed: '.$table);}}
function future(string $path,string $version,string $migration,bool $destructive=false): void
{
 global $project;
 copy($project.'/release/web-update/uii-web-update.zip',$path);$zip=new ZipArchive();$zip->open($path);$manifest=json_decode($zip->getFromName('manifest.json'),true);$manifest['version']=$version;
 $composer=json_decode($zip->getFromName('payload/ext/uii/portal/composer.json'),true);$composer['version']=$version;
 $parent=$migration==='v018' ? 'v017' : 'v018';
 $php="<?php\nnamespace uii\\portal\\migrations;\nclass $migration extends \\phpbb\\db\\migration\\migration {public static function depends_on(){return [".var_export('\\uii\\portal\\migrations\\'.$parent,true)."]; }";
 $php.=$destructive ? "public function update_schema(){return ['drop_tables'=>[\$this->table_prefix.'users']];}}" : "public function update_data(){return [['config.add',['uii_test_update',1]]];}}";
 foreach (['ext/uii/portal/composer.json'=>json_encode($composer),'styles/uii_modern/theme/update-probe.css'=>'/* updater preservation test */','ext/uii/portal/migrations/'.$migration.'.php'=>$php] as $relative=>$content) {$zip->addFromString('payload/'.$relative,$content);$manifest['files'][$relative]=['size'=>strlen($content),'sha256'=>hash('sha256',$content)];}
 $zip->addFromString('manifest.json',json_encode($manifest));$zip->close();
}
$token=str_repeat('a',32);$image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aB1sAAAAASUVORK5CYII=');
if (!is_dir(UII_EVIDENCE_PATH)) {mkdir(UII_EVIDENCE_PATH,0700,true);}file_put_contents(UII_EVIDENCE_PATH.'/'.$token.'.bin',$image);
$db->sql_query('INSERT INTO '.$table_prefix.'uii_cases '.$db->sql_build_array('INSERT',['kind'=>'complaint','title'=>'QA: updater data preservation','author_id'=>2,'assigned_id'=>2,'created_at'=>time(),'updated_at'=>time(),'payload'=>json_encode(['photos'=>[['token'=>$token,'slot'=>'document_url','mime'=>'image/png','size'=>strlen($image)]]]) ]));
$caseId=(int)$db->sql_nextid();
$db->sql_query('INSERT INTO '.$table_prefix.'uii_history '.$db->sql_build_array('INSERT',['case_id'=>$caseId,'actor_id'=>2,'created_at'=>time(),'action'=>'save','details'=>'{"qa":true}']));
$db->sql_query('INSERT INTO '.$table_prefix.'uii_bug_reports '.$db->sql_build_array('INSERT',['user_id'=>2,'title'=>'QA: updater preservation report','category'=>'other','description'=>'Исходное описание отчёта должно сохраниться','status'=>'new','created_at'=>time(),'updated_at'=>time(),'submission_key'=>bin2hex(random_bytes(32))]));
$before=[];foreach (['users','topics','posts','forums','uii_cases','uii_history','uii_bug_reports'] as $table) {$before[$table]=rows($table_prefix.$table);}
$configHash=hash_file('sha256',$site.'/config.php');$package=$private.'/future-131.zip';future($package,'1.3.1','v018');
$github=new class($package) extends G {
 private $file;public function __construct($file) {$this->file=$file;}
 public function latest(string $repository,string $token): array {return ['version'=>strpos($this->file,'132')!==false ? '1.3.2' : '1.3.1','release_id'=>1,'asset_id'=>2,'sha256'=>hash_file('sha256',$this->file),'size'=>filesize($this->file),'notes'=>'QA release','url'=>'https://github.com/anrhangels/uii-forum/releases'];}
 public function download(string $repository,string $token,array $release,string $destination): void {copy($this->file,$destination);}
};
$updater=new U($site,$github);$updater->configure('anrhangels/uii-forum','secret_fixture',false);check($updater->settings()['has_token'],'Token configured');
$state=$updater->exclusive(fn()=>$updater->prepare());check($state['status']==='prepared' && !U::maintenance($site),'Prepare does not close site');sameData($before);
$updater->exclusive(fn()=>$updater->install($state['id'],$db,$table_prefix));check(U::maintenance($site),'Maintenance protects installation');check($updater->current()==='1.3.1','New code installed');sameData($before);check(hash_file('sha256',$site.'/config.php')===$configHash && file_get_contents(UII_EVIDENCE_PATH.'/'.$token.'.bin')===$image,'Config and photo retained');
$dump=$updater->backup($state['id']);$restored=new SQLite3(':memory:');check($restored->exec(file_get_contents($dump)),'SQL snapshot restores into empty SQLite DB');check((int)$restored->querySingle('SELECT COUNT(*) FROM '.$table_prefix.'users')===count($before['users']),'Restored users preserved');$restored->close();
$updater->exclusive(fn()=>$updater->rollback($state['id']));check($updater->current()==='1.3.0' && !U::maintenance($site) && !is_file($site.'/styles/uii_modern/theme/update-probe.css'),'Pre-migration file rollback');sameData($before);
$state=$updater->exclusive(fn()=>$updater->prepare());$updater->exclusive(fn()=>$updater->install($state['id'],$db,$table_prefix));$cache->purge();
$done=$updater->exclusive(fn()=>$updater->migrate($state['id'],$phpbb_container->get('migrator'),$phpbb_container->get('ext.manager'),$config));check($done && !U::maintenance($site) && (int)$config['uii_test_update']===1,'Additive migration finished');sameData($before);
try {$updater->rollback($state['id']);throw new LogicException('Rollback after migration accepted');}catch (RuntimeException $e) {}
future($private.'/future-132.zip','1.3.2','v019',true);
$github=new class($private.'/future-132.zip') extends G {
 private $file;public function __construct($file){$this->file=$file;}
 public function latest(string $repository,string $token): array {return ['version'=>'1.3.2','release_id'=>3,'asset_id'=>4,'sha256'=>hash_file('sha256',$this->file),'size'=>filesize($this->file),'notes'=>'QA unsafe migration','url'=>'https://github.com/anrhangels/uii-forum/releases'];}
 public function download(string $repository,string $token,array $release,string $destination): void {copy($this->file,$destination);}
};
$updater=new U($site,$github);$state=$updater->exclusive(fn()=>$updater->prepare());$updater->exclusive(fn()=>$updater->install($state['id'],$db,$table_prefix));$cache->purge();
try {$updater->migrate($state['id'],$phpbb_container->get('migrator'),$phpbb_container->get('ext.manager'),$config);throw new LogicException('Destructive migration accepted');}catch (RuntimeException $e) {check(empty($updater->state()['migration_started']),'Unsafe migration blocked before start');}
$updater->rollback($state['id']);sameData($before);check($updater->current()==='1.3.1' && !U::maintenance($site),'Unsafe update rolled back safely');
file_put_contents($private.'/preservation.json',json_encode(['users'=>count($before['users']),'cases'=>count($before['uii_cases']),'config_sha256'=>$configHash]));
echo "PASS full updater: staged GitHub package, private SQL/files backup, maintenance, users/cases/photos/config retained, rollback, additive migration, destructive migration blocked\n";
