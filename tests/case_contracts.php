<?php
if (PHP_SAPI!=='cli') { exit(1); }
require dirname(__DIR__).'/app/ext/uii/portal/service/case_schema.php';
require dirname(__DIR__).'/app/ext/uii/portal/service/case_status.php';
require dirname(__DIR__).'/app/ext/uii/portal/domain/workflow.php';
use uii\portal\service\case_schema as S;
use uii\portal\service\case_status as B;
use uii\portal\domain\workflow as W;
$count=0;
function expect($value,$message) { global $count; if (!$value) { throw new RuntimeException($message); } $count++; }
foreach (['complaint','request','inspection'] as $kind) {
 foreach ([false,true] as $editing) {
  $fields=S::fields($kind,$editing,true,true);
  expect(!array_intersect(array_keys($fields),['basis','assigned_id','publication_url','protocol','protocol_person','protocol_questions','protocol_answers']),'Removed fields');
 }
 expect(!isset(S::fields($kind,false,false,false)['answer_url']),'No answer on create');
}
expect(!isset(S::fields('inspection',true,true,true)['subject']),'No employee on inspection');
expect(S::actions('inspection',true,false)===['register'],'One registration action');
expect(!isset(S::uploadSlots('complaint',true)['protocol']) && isset(S::uploadSlots('complaint',false)['protocol']),'DO only at final stage');
expect(isset(S::fields('request',true,true,true)['completion_note']) && !isset(S::fields('request',true,true,false)['completion_note']),'No-answer manager permission');
expect(!isset(S::fields('request',true,false,true)['answer_url']),'No answer before notification');
$row=['started_at'=>100,'deadline_at'=>200,'answered_at'=>150,'closed_at'=>160];$data=['answer_url'=>'https://example.test/answer'];
expect(W::responseOutcome($row,$data,300)==='answered_on_time','Timely verified response');
foreach (['started_at'=>0,'deadline_at'=>0,'answered_at'=>0,'closed_at'=>0] as $key=>$value) { $bad=$row;$bad[$key]=$value;expect(W::responseOutcome($bad,$data,300)==='','Missing timestamp '.$key); }
foreach ([['answered_at',99],['closed_at',149],['closed_at',301],['deadline_at',99]] as [$key,$value]) {$bad=$row;$bad[$key]=$value;expect(W::responseOutcome($bad,$data,300)==='','Invalid chronology '.$key);}
expect(W::responseOutcome($row,[],300)==='','Missing proof');
expect(W::responseOutcome($row,['answer_url'=>'javascript:alert(1)'],300)==='','Unsafe proof');
$row['answered_at']=210;$row['closed_at']=220;expect(W::responseOutcome($row,$data,300)==='answered_late','Verified late answer');
foreach (['created','planned','in_progress'] as $state) {expect(!B::describe(['kind'=>'inspection','status'=>$state],300)['visible'],'Hide inspection decoration');}
expect(B::describe(['kind'=>'complaint','status'=>'created'],300)['visible'],'Keep other kinds badges');
foreach ([0,200] as $deadline) {expect(B::describe(['kind'=>'request','status'=>'answered_on_time','closed_at'=>0,'answered_at'=>150,'deadline_at'=>$deadline],300)['label']==='В работе','No early answer badge');}
$ini=parse_ini_file(dirname(__DIR__).'/deployment/php/uii-uploads.ini');expect($ini['upload_max_filesize']==='5M' && $ini['post_max_size']==='20M' && (int)$ini['max_file_uploads']>=3,'Docker PHP limits');
expect(strpos(file_get_contents(dirname(__DIR__).'/deployment/apache/uii-uploads.conf'),'20971520')!==false,'Apache request limit');
echo "Passed case contracts: $count\n";
