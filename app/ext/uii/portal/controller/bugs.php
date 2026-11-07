<?php
namespace uii\portal\controller;
use uii\portal\service\bug_reports as Reports;
class bugs
{
    private $helper;private $template;private $user;private $auth;private $request;private $config;private $reports;
    public function __construct($helper,$template,$user,$auth,$db,$prefix,$request,$config)
    {$this->helper=$helper;$this->template=$template;$this->user=$user;$this->auth=$auth;$this->request=$request;$this->config=$config;$this->reports=new Reports($db,$prefix);}
    private function access(bool $admin=false): void
    {
        if (empty($this->user->data['is_registered']) || !empty($this->user->data['uii_blocked']) || !$this->auth->acl_get($admin ? 'a_uii_bug_reports' : 'u_uii_bug_report')) {throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Нет доступа к баг-репортам.');}
        if (empty($this->config['uii_bug_reports_ready'])) {throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null,'Сначала примените миграцию v016.');}
    }
    private function choices(string $selected=''): void
    {foreach (Reports::STATUSES as $value=>$label) {$this->template->assign_block_vars('statuses',['VALUE'=>$value,'LABEL'=>$label,'SELECTED'=>$value===$selected]);}}
    private function rowVars(array $row): array
    {
        return ['ID'=>(int)$row['report_id'],'NUMBER'=>Reports::number((int)$row['report_id']),'TITLE'=>$row['title'],'AUTHOR'=>$row['username'] ?? 'Учётная запись удалена','AUTHOR_ID'=>(int)$row['user_id'],'CATEGORY'=>Reports::CATEGORIES[$row['category']] ?? 'Другое','DATE'=>$this->user->format_date($row['created_at']),'STATUS'=>Reports::STATUSES[$row['status']] ?? 'Неизвестен','TONE'=>Reports::TONES[$row['status']] ?? 'neutral','URL'=>$this->helper->route('uii_portal_bug_detail',['id'=>(int)$row['report_id']])];
    }
    public function create()
    {
        $this->access();$error='';$data=['title'=>'','category'=>'','description'=>'','reproduction_steps'=>'','expected_behavior'=>'','evidence_url'=>''];$key=$this->request->variable('submission_key','');
        add_form_key('uii_bug_report');
        if ($this->request->server('REQUEST_METHOD','GET')==='POST') {
            foreach ($data as $field=>$value) {$data[$field]=htmlspecialchars_decode($this->request->variable($field,'',true),ENT_COMPAT);}
            try {
                if (!check_form_key('uii_bug_report')) {throw new \InvalidArgumentException('Срок действия формы истёк. Обновите страницу.');}
                $id=$this->reports->submit((int)$this->user->data['user_id'],$data,$key,time());
                redirect($this->helper->route('uii_portal_bug_success',['id'=>$id]));
            } catch (\InvalidArgumentException $e) {$error=$e->getMessage();}
            catch (\Throwable $e) {error_log('UII bug report submission failed: '.get_class($e));$error='Не удалось сохранить отчёт. Попробуйте отправить эту форму ещё раз. При повторной ошибке обратитесь к администратору.';}
        }
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) {$key=bin2hex(random_bytes(32));}
        foreach (Reports::CATEGORIES as $value=>$label) {$this->template->assign_block_vars('categories',['VALUE'=>$value,'LABEL'=>$label,'SELECTED'=>$data['category']===$value]);}
        $this->template->assign_vars(['BUG_FIELDS'=>$data,'BUG_ERROR'=>$error,'SUBMISSION_KEY'=>$key,'FORM_ACTION'=>$this->helper->route('uii_portal_bug_create')]);
        return $this->helper->render('@uii_portal/bug_form.html','Сообщить об ошибке');
    }
    public function success($id)
    {
        $this->access();
        if (!$this->reports->owned((int)$id,(int)$this->user->data['user_id'])) {throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Подтверждение не найдено.');}
        $this->template->assign_vars(['BUG_NUMBER'=>Reports::number((int)$id),'BUG_NEW_URL'=>$this->helper->route('uii_portal_bug_create')]);return $this->helper->render('@uii_portal/bug_success.html','Отчёт отправлен');
    }
    public function listing()
    {
        $this->access(true);$status=$this->request->variable('status','');$order=$this->request->variable('order','newest');$page=$this->request->variable('page',1);
        if ($status!=='' && !isset(Reports::STATUSES[$status])) {throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Неизвестный статус.');}
        $order=$order==='oldest' ? 'oldest' : 'newest';$listing=$this->reports->listing($status,$order,$page);$this->choices($status);
        foreach ($listing['rows'] as $row) {$this->template->assign_block_vars('reports',$this->rowVars($row));}
        $pages=$listing['pages'];$page=$listing['page'];
        // At most seven links, even with many reports.
        $numbers=array_unique(array_merge([1,$pages],range(max(1,$page-2),min($pages,$page+2))));sort($numbers);
        foreach ($numbers as $number) {$this->template->assign_block_vars('bug_pages',['NUMBER'=>$number,'CURRENT'=>$number===$page,'URL'=>$this->helper->route('uii_portal_bug_admin',['page'=>$number,'status'=>$status,'order'=>$order])]);}
        $this->template->assign_vars(['BUG_LIST_URL'=>$this->helper->route('uii_portal_bug_admin'),'BUG_TOTAL'=>$listing['total'],'BUG_ORDER'=>$order,'BUG_ALL_STATUS'=>$status==='','BUG_PAGE'=>$page,'BUG_PAGES'=>$pages]);
        return $this->helper->render('@uii_portal/bug_list.html','Баг-репорты');
    }
    public function detail($id)
    {
        $this->access(true);$id=(int)$id;$row=$this->reports->get($id);
        if (!$row) {throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Баг-репорт не найден.');}
        $error='';add_form_key('uii_bug_status');
        if ($this->request->server('REQUEST_METHOD','GET')==='POST') {
            try {
                if (!check_form_key('uii_bug_status')) {throw new \InvalidArgumentException('Срок действия формы истёк. Обновите страницу.');}
                $this->reports->changeStatus($id,$this->request->variable('status',''),$this->request->variable('version',0),time());
                redirect($this->helper->route('uii_portal_bug_detail',['id'=>$id,'saved'=>1]));
            } catch (\InvalidArgumentException $e) {$error=$e->getMessage();}
            catch (\Throwable $e) {error_log('UII bug status update failed: '.get_class($e));$error='Не удалось изменить статус. Обновите страницу и попробуйте ещё раз.';}
        }
        $proof='';if ($row['evidence_url']!=='') {try {$proof=\uii\portal\domain\workflow::url($row['evidence_url']);}catch (\InvalidArgumentException $e) {}}
        $this->choices($row['status']);$this->template->assign_vars($this->rowVars($row));
        $this->template->assign_vars(['BUG_DESCRIPTION'=>$row['description'],'BUG_STEPS'=>$row['reproduction_steps'],'BUG_EXPECTED'=>$row['expected_behavior'],'BUG_EVIDENCE'=>$proof,'BUG_VERSION'=>(int)$row['version'],'BUG_EXPORT'=>Reports::export($row),'BUG_ERROR'=>$error,'BUG_SAVED'=>$this->request->variable('saved',0)===1,'BUG_LIST_URL'=>$this->helper->route('uii_portal_bug_admin'),'FORM_ACTION'=>$this->helper->route('uii_portal_bug_detail',['id'=>$id])]);
        return $this->helper->render('@uii_portal/bug_detail.html',Reports::number($id));
    }
}
