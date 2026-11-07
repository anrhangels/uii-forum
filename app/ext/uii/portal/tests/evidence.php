<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/service/evidence.php';
require dirname(__DIR__).'/service/case_schema.php';
require dirname(__DIR__).'/service/image_optimizer.php';
require dirname(__DIR__).'/domain/workflow.php';
require dirname(__DIR__).'/controller/dashboard.php';
require dirname(__DIR__, 4).'/vendor/autoload.php';
use uii\portal\service\evidence as E;
use uii\portal\domain\workflow as W;
function check_form_key($key) { return $GLOBALS['photo_csrf'] ?? false; }
class UploadController extends uii\portal\controller\dashboard { public function detail($id) { return 'detail'; } }
$count=0;
function verify($value, $message) { global $count; if (!$value) { throw new RuntimeException($message); } $count++; }
function rejected(callable $fn, $message) { try { $fn(); } catch (InvalidArgumentException $e) { verify(true,$message); return; } throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/uii-photo-test-'.bin2hex(random_bytes(8));
mkdir($root.'/files/uii_evidence',0750,true);
$token=str_repeat('a',32); $storage=new E($root); $path=$storage->path($token);
try {
    file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aB1sAAAAASUVORK5CYII='));
    verify(E::inspect($path)['mime']==='image/png','PNG content detected');
    verify(count(E::slots('request'))===2,'Requests do not accept uploads');
    verify(array_keys(E::slots('complaint'))===['document_url','protocol'],'Only complaint protocol accepts photos');
    verify(count(E::slots('inspection'))===3,'Three inspection stages');
    verify(E::limit('complaint')===2 && E::limit('inspection')===3 && E::limit('request')===2,'Per-kind photo limits');
    verify(array_keys(E::uploadSlots('inspection'))===['start_url','document_url','end_url'],'Inspection uploads only start and end');
    E::checkCapacity('complaint',[],'protocol'); verify(true,'First complaint photo accepted');
    rejected(fn()=>E::checkCapacity('complaint',[['slot'=>'protocol']],'protocol'),'Second complaint photo rejected');
    E::checkCapacity('inspection',[['slot'=>'start_url']],'end_url'); verify(true,'Second inspection stage accepted');
    rejected(fn()=>E::checkCapacity('inspection',[['slot'=>'start_url'],['slot'=>'end_url']],'end_url'),'Third inspection photo rejected');
    rejected(fn()=>E::checkCapacity('inspection',[['slot'=>'start_url']],'start_url'),'Duplicate inspection stage rejected');
    E::checkCapacity('inspection',[],'document_url'); verify(true,'Inspection protocol accepted');
    rejected(fn()=>E::checkCapacity('request',[],'protocol'),'Request upload rejected');
    $inspection=['photos'=>[['slot'=>'start_url','token'=>$token],['slot'=>'end_url','token'=>str_repeat('b',32)]],'decision'=>'Проверка завершена','violations'=>'Не установлены'];
    W::closeInspection($inspection,1); verify(true,'Two photos complete an inspection without a third document');
    rejected(fn()=>W::closeInspection($inspection,0),'Inspection must be started');
    $missingEnd=$inspection;array_pop($missingEnd['photos']);
    rejected(fn()=>W::closeInspection($missingEnd,1),'End evidence required');
    rejected(fn()=>$storage->path('../config.php'),'Path traversal rejected');
    rejected(fn()=>$storage->store(['error'=>0,'tmp_name'=>$path]),'Local files are not HTTP uploads');
    rejected(fn()=>$storage->store(['error'=>['0'],'tmp_name'=>[$path]]),'Malformed upload rejected');
    $photos=[['slot'=>'protocol','token'=>$token,'mime'=>'image/png','size'=>filesize($path)]];
    verify(W::hasMaterial(['photos'=>$photos],'protocol'),'Photo satisfies protocol stage');
    verify(!W::hasMaterial(['photos'=>$photos],'document_url'),'Protocol photo cannot substitute other proof');
    W::closeComplaint(['photos'=>$photos,'document_url'=>'https://example.test/proof','decision'=>'Решение','violations'=>'Не установлены']);
    verify(true,'Complaint can close with photographed protocol');
    foreach (['start_url','end_url','document_url'] as $slot) {
        verify(W::hasMaterial(['photos'=>[['slot'=>$slot,'token'=>$token]]],$slot),'Inspection photo satisfies its stage');
    }
    // Real download controller with isolated case data and no production database.
    define('USER_FOUNDER',3); $phpbb_root_path=$root.'/';
    $db=new class($photos) {
        public $row;
        function __construct($photos) { $this->row=['case_id'=>7,'kind'=>'complaint','author_id'=>11,'assigned_id'=>12,'payload'=>json_encode(['photos'=>$photos])]; }
        function sql_query($sql) { return 1; }
        function sql_fetchrow($r) { return $this->row; }
        function sql_freeresult($r) {}
    };
    $user=(object)['data'=>['is_registered'=>true,'user_id'=>11,'user_type'=>0]];
    $auth=new class { function acl_get($key) { return true; } };
    $request=new class($token) {
        private $token;
        function __construct($token) { $this->token=$token; }
        function server($key,$default='') { return $default; }
        function is_set_post($key) { return false; }
        function variable($key,$default) { return $key==='token' ? $this->token : $default; }
    };
    $controller=new uii\portal\controller\dashboard(null,null,$user,$auth,$db,'test_',$request);
    $level=new ReflectionProperty($controller,'actorLevel'); $level->setAccessible(true); $level->setValue($controller,1);
    $response=$controller->photos(7);
    verify($response->getFile()->getPathname()===$path,'Author can download original');
    verify(strpos($response->headers->get('Content-Disposition'),'attachment')===0,'Download uses attachment disposition');
    verify($response->headers->get('X-Content-Type-Options')==='nosniff','No MIME sniffing');
    verify(strpos($response->headers->get('Cache-Control'),'no-store')!==false,'Private download not cached');
    $user->data['user_id']=12; verify($controller->photos(7) instanceof Symfony\Component\HttpFoundation\BinaryFileResponse,'Assigned staff can download');
    $user->data['user_id']=99;
    try { $controller->photos(7); throw new RuntimeException('Foreign case leaked'); }
    catch (Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) { verify(true,'Foreign case denied'); }
    $level->setValue($controller,2); verify($controller->photos(7) instanceof Symfony\Component\HttpFoundation\BinaryFileResponse,'Manager can download');
    $user->data['is_registered']=false;
    try { $controller->photos(7); throw new RuntimeException('Guest leaked'); }
    catch (Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) { verify(true,'Guest denied'); }
    $user->data['is_registered']=true;
    $template=new class { public $error=''; function assign_var($key,$value) { if ($key==='PHOTO_ERROR') { $this->error=$value; } } };
    $uploadRequest=new class {
        public $post=['photo_slot'=>'protocol','version'=>1]; public $reads=0;
        function server($key,$default='') { return $default; }
        function is_set_post($key) { return $key==='upload_photo'; }
        function variable($key,$default) { return $this->post[$key] ?? $default; }
        function file($name) { $this->reads++; throw new RuntimeException('Rejected upload reached file storage'); }
    };
    $upload=new UploadController(null,$template,$user,$auth,$db,'test_',$uploadRequest);
    $level->setValue($upload,2); $GLOBALS['photo_csrf']=true;
    $db->row+=['closed_at'=>0,'version'=>1];
    verify($upload->photos(7)==='detail' && str_contains($template->error,'уже загружено'),'Controller rejects second complaint photo');
    $db->row['payload']='{}'; $db->row['closed_at']=1;
    $upload->photos(7); verify(str_contains($template->error,'только для чтения'),'Closed case upload rejected');
    $db->row['closed_at']=0; $GLOBALS['photo_csrf']=false;
    $upload->photos(7); verify(str_contains($template->error,'формы истёк'),'Invalid CSRF rejected before file access');
    $GLOBALS['photo_csrf']=true; $uploadRequest->post['version']=0;
    $upload->photos(7); verify(str_contains($template->error,'Дело изменилось'),'Stale upload rejected');
    $uploadRequest->post=['photo_slot'=>'end_url','version'=>1]; $db->row['kind']='inspection';
    $db->row['payload']=json_encode(['photos'=>[['slot'=>'start_url'],['slot'=>'end_url']]]);
    $upload->photos(7); verify(str_contains($template->error,'уже загружено'),'Controller rejects third inspection photo');
    $db->row['payload']=json_encode(['photos'=>[['slot'=>'end_url']]]);
    $upload->photos(7); verify(str_contains($template->error,'уже загружено'),'Controller rejects duplicate inspection stage');
    $uploadRequest->post['photo_slot']='unknown';$db->row['payload']='{}';
    $upload->photos(7); verify(str_contains($template->error,'не предусмотрено'),'Controller rejects third upload category');
    verify($uploadRequest->reads===0,'Rejected requests never read or store the uploaded file');
    file_put_contents($path,'<?php echo "not an image";');
    rejected(fn()=>E::inspect($path),'Executable text rejected');
    file_put_contents($path,'<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    rejected(fn()=>E::inspect($path),'SVG rejected');
    $handle=fopen($path,'w'); ftruncate($handle,E::MAX_BYTES+1); fclose($handle);
    rejected(fn()=>E::inspect($path),'Oversize file rejected');
} finally {
    if (is_file($path)) { unlink($path); }
    rmdir($root.'/files/uii_evidence'); rmdir($root.'/files'); rmdir($root);
}
echo "Passed photo checks: $count\n";
