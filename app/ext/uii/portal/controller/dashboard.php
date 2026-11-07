<?php
namespace uii\portal\controller;

class dashboard
{
    private $helper;
    private $template;
    private $user;
    private $auth;
    private $db;
    private $prefix;
    private $request;
    private $actorLevel;
    private $configValues = [];
    public function __construct($helper, $template, $user, $auth, $db, $prefix, $request = null)
    {
        $this->helper=$helper; $this->template=$template; $this->user=$user;
        $this->auth=$auth; $this->db=$db; $this->prefix=$prefix; $this->request=$request ?: $GLOBALS['request'];
    }
    private function role(): int
    {
        if ($this->actorLevel===null) { $this->actorLevel=\uii\portal\service\roles::effective($this->db,$this->prefix,$this->user->data); }
        return $this->actorLevel;
    }
    private function requireAccess()
    {
        if (empty($this->user->data['is_registered']) || ($this->role()<1 || (!$this->auth->acl_get('u_uii_use') && $this->user->data['user_type']!=USER_FOUNDER))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Нет доступа к документам отдела.');
        }
    }
    private function eventLabel(string $action): string
    {
        return ['register'=>'Проверка зарегистрирована','save'=>'Сохранены материалы','close'=>'Дело завершено','notify'=>'Подтверждено начало срока','start'=>'Проверка начата','cancel'=>'Проверка отменена','account_create'=>'Создан сотрудник','account_update'=>'Обновлены данные сотрудника','account_reset'=>'Пароль сброшен','account_block'=>'Аккаунт заблокирован','account_restore'=>'Аккаунт восстановлен','navigation_update'=>'Изменено меню','photo_upload'=>'Добавлено фото','timer_setting'=>'Изменён регламент срока'][$action] ?? 'Изменение';
    }
    private function eventDetails(string $json): string
    {
        $details=json_decode($json,true) ?: [];$lines=[];
        if (!empty($details['user_id'])) { $lines[]='Сотрудник №'.(int)$details['user_id']; }
        if (!empty($details['block_reason'])) { $lines[]='Причина: '.(\uii\portal\service\staff::REASONS[$details['block_reason']] ?? 'Ограничен доступ'); }
        if (!empty($details['block_note'])) { $lines[]='Комментарий: '.$details['block_note']; }
        if (isset($details['gender'])) { $lines[]='Пол персонажа: '.\uii\portal\service\avatars::label($details['gender']); }
        if (isset($details['title'])) { $lines[]='Название: '.$details['title']; }
        if (isset($details['role'])) { $roles=[1=>'Сотрудник',2=>'Заместитель начальника',3=>'Начальник',4=>'Руководство фракции'];$lines[]='Роль: '.($roles[$details['role']] ?? 'Не назначена'); }
        if (isset($details['groups'])) { $lines[]='Групп меню: '.(int)$details['groups']; }
        if (isset($details['mode'])) { $lines[]='Начало срока: '.($details['mode']==='publication' ? 'публикация' : 'уведомление'); }
        $labels=['photo'=>'Фото','applicant'=>'Заявитель','subject'=>'Фигурант / адресат','description'=>'Описание','basis'=>'Основание','protocol_person'=>'Опрашиваемый','protocol_questions'=>'Вопросы','protocol_answers'=>'Ответы','protocol'=>'Протокол','document_url'=>'Документ','decision'=>'Решение','violations'=>'Нарушения','punishment'=>'Наказание','comment'=>'Комментарий','notification_url'=>'Уведомление','publication_url'=>'Публикация','answer_url'=>'Ответ','completion_note'=>'Если ответ не поступил','inspection_type'=>'Тип проверки','planned_at'=>'Дата проверки','start_url'=>'Начало проверки','end_url'=>'Окончание проверки'];
        foreach (($details['changes'] ?? []) as $key=>$value) { if (in_array($key,['basis','publication_url','protocol','protocol_person','protocol_questions','protocol_answers'],true)) { continue; } $lines[]=($labels[$key] ?? 'Поле').': '.(string)$value; }
        return implode("\n",$lines);
    }
    private function peopleVars(array $row): array
    {
        return [
            'AUTHOR_NAME'=>$row['author_name'] ?? 'Сотрудник недоступен',
            'AUTHOR_AVATAR'=>\uii\portal\service\avatars::url($row['author_gender'] ?? 'unspecified'),
            'AUTHOR_URL'=>$this->helper->route('uii_portal_profile',['id'=>(int)$row['author_id']]),
            'ASSIGNED_NAME'=>$row['author_name'] ?? 'Сотрудник недоступен',
            'SHOW_STATUS'=>\uii\portal\service\case_status::describe($row,time())['visible'],
        ];
    }
    private function caseSelect(bool $materials = false): string
    {
        return 'SELECT '.($materials ? 'c.*' : 'c.case_id, c.kind, c.title, c.author_id, c.assigned_id, c.status, c.updated_at, c.started_at, c.deadline_at, c.answered_at, c.closed_at').', au.username AS author_name, au.uii_gender AS author_gender, ass.username AS assigned_name FROM '.$this->prefix.'uii_cases c LEFT JOIN '.$this->prefix.'users au ON au.user_id = c.author_id LEFT JOIN '.$this->prefix.'users ass ON ass.user_id = c.assigned_id';
    }
    private function statusLabel(array $row): string
    {
        return \uii\portal\service\case_status::describe($row,time())['label'];
    }
    private function deadlineInfo(array $row): array
    {
        $now=time();
        $tone=\uii\portal\service\case_status::describe($row,$now)['tone'];
        if ($row['kind']!=='request' || !$row['deadline_at'] || $row['answered_at'] || $row['closed_at']) { return ['tone'=>$tone,'remaining'=>'']; }
        $seconds=(int)$row['deadline_at']-$now;
        if ($seconds<=0) { return ['tone'=>$tone,'remaining'=>'Срок истёк']; }
        return ['tone'=>$tone,'remaining'=>'Осталось: '.intdiv((int)ceil($seconds/60),60).' ч '.((int)ceil($seconds/60)%60).' мин'];
    }
    public function cases()
    {
        $this->requireAccess();
        $kind=$this->request->variable('kind', 'complaint');
        if (!in_array($kind, ['complaint','request','inspection','all'], true)) { $kind='complaint'; }
        $query=trim($this->request->variable('q', '', true));
        $archive=$this->request->variable('archive', 0);
        $state=$this->request->variable('state','');
        $start=max(0, $this->request->variable('start', 0));
        $where=($kind==='all' ? '1=1' : "kind = '".$this->db->sql_escape($kind)."'").' AND closed_at '.($archive ? '> 0' : '= 0');
        if ($this->role()<2) {
            $id=(int)$this->user->data['user_id'];
            $where.=' AND (author_id = '.$id.' OR assigned_id = '.$id.')';
        }
        if ($kind==='request') {
            $states=['overdue'=>'deadline_at > 0 AND deadline_at <= '.time().' AND answered_at = 0 AND closed_at = 0','waiting'=>'deadline_at > '.time().' AND answered_at = 0 AND closed_at = 0','answered_late'=>'closed_at > 0 AND started_at > 0 AND deadline_at > started_at AND answered_at > deadline_at AND answered_at <= closed_at','answered_on_time'=>'closed_at > 0 AND started_at > 0 AND deadline_at > started_at AND answered_at >= started_at AND answered_at <= deadline_at AND answered_at <= closed_at'];
            if (isset($states[$state])) { $where.=' AND ('.$states[$state].')'; }
        }
        if ($query !== '') {
            $where.=" AND title " . $this->db->sql_like_expression($this->db->get_any_char() . $this->db->sql_escape($query) . $this->db->get_any_char());
        }
        $result=$this->db->sql_query_limit($this->caseSelect().' WHERE '.$where.' ORDER BY c.updated_at DESC, c.case_id DESC', 26, $start);
        $count=0;
        while ($row=$this->db->sql_fetchrow($result)) {
            if (++$count>25) { break; }
            $this->template->assign_block_vars('cases', [...$this->peopleVars($row),
                'NUMBER'=>(int)$row['case_id'], 'TITLE'=>$row['title'], 'STATUS'=>$this->statusLabel($row),'TONE'=>$this->deadlineInfo($row)['tone'],'REMAINING'=>$this->deadlineInfo($row)['remaining'],
                'UPDATED'=>$this->user->format_date($row['updated_at']),
                'URL'=>$this->helper->route('uii_portal_case', ['id'=>(int)$row['case_id']]),
            ]);
        }
        $this->db->sql_freeresult($result);
        $titles=['complaint'=>'Жалобы','request'=>'Служебные запросы','inspection'=>'Проверки состава','all'=>$archive ? 'Архив отдела' : 'Все дела'];
        $this->template->assign_vars(['PORTAL_TITLE'=>$titles[$kind], 'QUERY'=>$query,
            'CAN_CREATE'=>$kind!=='all' && !$archive,'CREATE_URL'=>$this->helper->route('uii_portal_create',['kind'=>$kind]), 'FORM_URL'=>$this->helper->route('uii_portal_cases'), 'IS_REQUEST'=>$kind==='request', 'KIND'=>$kind, 'ARCHIVE'=>$archive,
            'PREVIOUS_URL'=>$start>0 ? $this->helper->route('uii_portal_cases', ['kind'=>$kind,'q'=>$query,'archive'=>$archive,'state'=>$state,'start'=>max(0,$start-25)]) : '',
            'NEXT_URL'=>$count>25 ? $this->helper->route('uii_portal_cases', ['kind'=>$kind,'q'=>$query,'archive'=>$archive,'state'=>$state,'start'=>$start+25]) : '',
        ]);
        foreach ([''=>'Все состояния','waiting'=>'Ожидающие ответа','overdue'=>'Просроченные','answered_on_time'=>'Ответ вовремя','answered_late'=>'Ответ с опозданием'] as $key=>$label) { $this->template->assign_block_vars('states',['VALUE'=>$key,'LABEL'=>$label,'SELECTED'=>$state===$key]); }
        return $this->helper->render('@uii_portal/cases.html', $titles[$kind]);
    }
    public function detail($id)
    {
        $this->requireAccess();
        $result=$this->db->sql_query($this->caseSelect(true).' WHERE case_id = '.(int)$id);
        $row=$this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        if (!$row) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Дело не найдено.'); }
        if ($this->role()<2 && !in_array((int)$this->user->data['user_id'], [(int)$row['author_id'],(int)$row['assigned_id']], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Нет доступа к этому делу.');
        }
        $this->template->assign_vars(['EDIT_URL'=>!$row['closed_at'] ? $this->helper->route('uii_portal_edit',['id'=>(int)$row['case_id']]) : '', 'CASE_NUMBER'=>(int)$row['case_id'],'CASE_TITLE'=>$row['title'],'CASE_STATUS'=>$this->statusLabel($row),'CASE_TONE'=>$this->deadlineInfo($row)['tone'],'CASE_REMAINING'=>$this->deadlineInfo($row)['remaining'],'CASE_CREATED'=>$this->user->format_date($row['created_at']), 'CASE_ASSIGNED'=>(int)$row['assigned_id'], 'CASE_DEADLINE'=>$row['deadline_at'] ? $this->user->format_date($row['deadline_at']) : '', 'CASE_TOPIC'=>$row['topic_id'] ? generate_board_url().'/viewtopic.php?t='.(int)$row['topic_id'] : '']);
        $this->template->assign_vars($this->peopleVars($row));
        $fields=json_decode($row['payload'], true) ?: [];
        $outcome=$row['kind']==='request' ? \uii\portal\domain\workflow::responseOutcome($row,$fields,time()) : '';
        $outcomeLabels=['answered_on_time'=>'Ответ получен вовремя','answered_late'=>'Ответ получен с опозданием'];
        $this->template->assign_vars(['RESPONSE_OUTCOME'=>$outcomeLabels[$outcome] ?? ($row['kind']==='request' && $row['closed_at'] ? ($row['answered_at'] ? 'Недостаточно данных для оценки срока ответа' : 'Ответ не поступил') : ''),'RESPONSE_TONE'=>$outcome==='answered_on_time' ? 'answered' : ($outcome==='answered_late' ? 'late' : 'neutral')]);
        if ($row['kind']==='complaint') {
            $stages=['Жалоба создана'=>true,'Фото протокола жалобы'=>\uii\portal\domain\workflow::hasMaterial($fields,'document_url'),'Фото ДО'=>\uii\portal\domain\workflow::hasMaterial($fields,'protocol'),'Решение'=>!empty($fields['decision']),'Дело закрыто'=>(bool)$row['closed_at']];
        } elseif ($row['kind']==='request') {
            $stages=['Запрос создан'=>true,'Срок запущен'=>(bool)$row['started_at'],'Ответ зарегистрирован'=>(bool)$row['answered_at'],'Запрос завершён'=>(bool)$row['closed_at']];
        } else {
            $stages=['Проверка зарегистрирована'=>true,'Проверка начата'=>(bool)$row['started_at'],'Результат оформлен'=>!empty($fields['decision']),'Проверка завершена'=>(bool)$row['closed_at']];
        }
        foreach ($stages as $label=>$done) { $this->template->assign_block_vars('stages',['LABEL'=>$label,'DONE'=>$done]); }
        $photoSlots=[];foreach (($fields['photos'] ?? []) as $photo) { $photoSlots[$photo['slot'] ?? '']=true; }
        foreach (\uii\portal\service\case_schema::visibleFields($row['kind']) as $key=>$label) {
            if (isset($fields[$key]) && is_string($fields[$key]) && $fields[$key]!=='' && !isset($photoSlots[$key])) {
                $url='';$isUrl=substr($key,-4)==='_url';
                if ($isUrl) { try { $url=\uii\portal\domain\workflow::url($fields[$key]); } catch (\InvalidArgumentException $exception) { continue; } }
                $this->template->assign_block_vars('fields',['LABEL'=>$label,'VALUE'=>$fields[$key],'URL'=>$url,'IS_IMAGE'=>$isUrl && $key!=='answer_url']);
            }
        }
        $this->photoBlocks($row,$fields);
        $history=$this->db->sql_query_limit('SELECT actor_id, created_at, action FROM '.$this->prefix.'uii_history WHERE case_id = '.(int)$id.' ORDER BY history_id DESC', 50);
        while ($event=$this->db->sql_fetchrow($history)) {
            $this->template->assign_block_vars('history',['ACTOR'=>(int)$event['actor_id'],'DATE'=>$this->user->format_date($event['created_at']),'ACTION'=>$this->eventLabel($event['action'])]);
        }
        $this->db->sql_freeresult($history);
        return $this->helper->render('@uii_portal/case.html', 'Дело №'.(int)$row['case_id']);
    }
    private function photoBlocks(array $row, array $fields): void
    {
        $slots=\uii\portal\service\evidence::slots($row['kind']);
        foreach (($fields['photos'] ?? []) as $photo) {
            if (!isset($slots[$photo['slot'] ?? ''])) { continue; }
            $this->template->assign_block_vars('photos',[
                'LABEL'=>$slots[$photo['slot']], 'SIZE'=>(int)ceil($photo['size']/1024),
                'URL'=>$this->helper->route('uii_portal_photos',['id'=>(int)$row['case_id'],'token'=>$photo['token']]),'PREVIEW'=>$this->helper->route('uii_portal_photo_viewer',['id'=>(int)$row['case_id'],'token'=>$photo['token'],'inline'=>1]),
            ]);
        }
        $this->template->assign_vars(['HAS_PHOTO_SECTION'=>!empty($fields['photos']) && (bool)$slots,'PHOTO_COUNT'=>count($fields['photos'] ?? []),'PHOTO_HEADING'=>'Прикреплённые изображения']);
    }
    public function photoViewer($id)
    {
        if ($this->request->server('REQUEST_METHOD','GET')!=='GET') {throw new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(['GET']);}
        // Reuse exactly the same authorization and file validation as binary downloads.
        $response=$this->photos($id);
        $token=$this->request->variable('token','');
        $this->template->assign_vars(['PHOTO_RAW'=>$this->helper->route('uii_portal_photos',['id'=>(int)$id,'token'=>$token,'inline'=>1]),'PHOTO_DOWNLOAD'=>$this->helper->route('uii_portal_photos',['id'=>(int)$id,'token'=>$token]),'PHOTO_BACK'=>$this->helper->route('uii_portal_case',['id'=>(int)$id])]);
        $page=$this->helper->render('@uii_portal/photo_viewer.html','Просмотр изображения');
        $page->headers->set('Cache-Control','private, no-store');$page->headers->set('X-Content-Type-Options','nosniff');
        return $page;
    }
    public function photos($id)
    {
        $this->requireAccess();
        $id=(int)$id;
        $r=$this->db->sql_query('SELECT * FROM '.$this->prefix.'uii_cases WHERE case_id = '.$id);
        $row=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
        if (!$row) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Дело не найдено.'); }
        if ($this->role()<2 && !in_array((int)$this->user->data['user_id'],[(int)$row['author_id'],(int)$row['assigned_id']],true)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Нет доступа к фото этого дела.');
        }
        $data=json_decode($row['payload'],true) ?: [];
        $slots=\uii\portal\service\evidence::slots($row['kind']);
        global $phpbb_root_path;
        $storage=new \uii\portal\service\evidence($phpbb_root_path);
        if ($this->request->server('REQUEST_METHOD','GET')==='POST' || $this->request->is_set_post('upload_photo')) {
            $saved=null; $transaction=false;
            try {
                \uii\portal\service\evidence::assertPostSize($this->request);
                if (!check_form_key('uii_photo')) { throw new \InvalidArgumentException('Срок действия формы истёк. Обновите страницу.'); }
                if ($row['closed_at']) { throw new \InvalidArgumentException('Завершённое дело доступно только для чтения.'); }
                $slot=$this->request->variable('photo_slot','');
                \uii\portal\service\evidence::checkCapacity($row['kind'],$data['photos'] ?? [],$slot);
                $version=$this->request->variable('version',0);
                if ($version!==(int)$row['version']) { throw new \InvalidArgumentException('Дело изменилось. Обновите страницу и выберите фото заново.'); }
                $saved=$storage->store($this->request->file('photo'));
                $saved['slot']=$slot;
                $data['photos'][]=$saved;
                $this->db->sql_transaction('begin'); $transaction=true;
                $update=['payload'=>json_encode($data,JSON_UNESCAPED_UNICODE),'version'=>$version+1,'updated_at'=>time()];
                if ($row['kind']==='complaint' && $row['status']==='created') { $update['status']='in_progress'; }
                $this->db->sql_query('UPDATE '.$this->prefix.'uii_cases SET '.$this->db->sql_build_array('UPDATE',$update).' WHERE case_id = '.$id.' AND version = '.$version.' AND closed_at = 0');
                if (!$this->db->sql_affectedrows()) { throw new \InvalidArgumentException('Дело изменилось. Обновите страницу и выберите фото заново.'); }
                $history=['case_id'=>$id,'actor_id'=>(int)$this->user->data['user_id'],'created_at'=>time(),'action'=>'photo_upload','details'=>json_encode(['changes'=>['photo'=>$slots[$slot]]],JSON_UNESCAPED_UNICODE)];
                $this->db->sql_query('INSERT INTO '.$this->prefix.'uii_history '.$this->db->sql_build_array('INSERT',$history));
                $this->db->sql_transaction('commit'); $transaction=false;
            } catch (\Throwable $e) {
                if ($transaction) { $this->db->sql_transaction('rollback'); }
                if ($saved) { $storage->discard($saved['token']); }
                if (!$e instanceof \InvalidArgumentException) { throw $e; }
                $this->template->assign_var('PHOTO_ERROR',$e->getMessage());
                return $this->detail($id);
            }
            redirect($this->helper->route('uii_portal_case',['id'=>$id]));
        }
        $token=$this->request->variable('token','');
        foreach (($data['photos'] ?? []) as $photo) {
            if ($photo['token']!==$token || !isset($slots[$photo['slot']])) { continue; }
            $path=$storage->path($token);
            if (!is_file($path)) { break; }
            $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $response=new \Symfony\Component\HttpFoundation\BinaryFileResponse($path);
            $response->headers->set('Content-Type',$photo['mime']);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('Cache-Control','private, no-store');
            $response->headers->set('Content-Security-Policy',"default-src 'none'; sandbox");
            $response->setContentDisposition($this->request->variable('inline',0) ? 'inline' : 'attachment','photo-'.$id.'-'.substr($token,0,8).'.'.$extensions[$photo['mime']]);
            return $response;
        }
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Фото не найдено.');
    }
    public function form($id = 0)
    {
        $this->requireAccess();
        if (!$this->configValue('uii_case_forms_ready',0)) { throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null,'Сначала примените обновление форм УИИ и миграцию v015.'); }
        $id=(int)$id;$creating=$id===0;$actor=(int)$this->user->data['user_id'];$manager=$this->role()>=2;
        $kind=$this->request->variable('kind','complaint');
        if (!isset(\uii\portal\service\case_schema::slots($kind)['document_url'])) { $kind='complaint'; }
        $row=['case_id'=>0,'kind'=>$kind,'title'=>'','status'=>'created','version'=>0,'payload'=>'{}','author_id'=>$actor,'assigned_id'=>$actor,'author_name'=>$this->user->data['username'],'started_at'=>0,'deadline_at'=>0,'answered_at'=>0,'closed_at'=>0];
        if (!$creating) {
            $r=$this->db->sql_query($this->caseSelect(true).' WHERE c.case_id = '.$id);
            $row=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);
            if (!$row) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Дело не найдено.'); }
            if (!$manager && !in_array($actor,[(int)$row['author_id'],(int)$row['assigned_id']],true)) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Нет доступа к изменению дела.'); }
            if ($row['closed_at']) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Завершённое дело доступно только для чтения.'); }
        }
        $kind=$row['kind'];
        $definitions=\uii\portal\service\case_schema::fields($kind,!$creating,(bool)$row['started_at'],$manager);
        $data=json_decode($row['payload'],true) ?: [];$previous=$data;$error='';$newFiles=[];
        $tickets=new \uii\portal\service\case_submissions($this->db,$this->prefix);
        $ticket=$this->request->variable('creation_key','');
        add_form_key('uii_case');
        if ($this->request->server('REQUEST_METHOD','GET')==='POST' || $this->request->is_set_post('save')) {
            $transaction=false;$storage=null;
            try {
                \uii\portal\service\evidence::assertPostSize($this->request);
                if (!check_form_key('uii_case')) { throw new \InvalidArgumentException('Срок действия формы истёк. Обновите страницу и выберите изображения повторно.'); }
                $now=time();
                $action=$this->request->variable('action',$creating && $kind==='inspection' ? 'register' : 'save');
                if (!in_array($action,\uii\portal\service\case_schema::actions($kind,$creating,(bool)$row['started_at']),true)) { throw new \InvalidArgumentException('Это действие недоступно на текущем этапе дела.'); }
                $row['title']=trim($this->request->variable('title','',true));
                if ($row['title']==='' || utf8_strlen($row['title'])>200) { throw new \InvalidArgumentException('Название должно содержать от 1 до 200 символов.'); }
                foreach ($definitions as $key=>[$label,$type,$required]) {
                    $data[$key]=trim($this->request->variable($key,'',true));
                    if (strlen($data[$key])>20000) { throw new \InvalidArgumentException('Слишком большой текст в поле «'.$label.'».'); }
                    if ($required && $data[$key]==='') { throw new \InvalidArgumentException('Заполните поле «'.$label.'».'); }
                    if ($type==='url' && $data[$key]!=='') { \uii\portal\domain\workflow::url($data[$key]); }
                }
                if ($creating) { $data['schema_version']=2; }
                $version=$creating ? 0 : $this->request->variable('version',0);
                if (!$creating && $version!==(int)$row['version']) { throw new \InvalidArgumentException('Дело уже изменено другим сотрудником. Обновите страницу перед повторным сохранением.'); }
                $this->db->sql_transaction('begin');$transaction=true;
                if ($creating) {
                    $replay=$tickets->claim($ticket,$actor,$kind,$now);
                    if ($replay) { $this->db->sql_transaction('rollback');$transaction=false;redirect($this->helper->route('uii_portal_case',['id'=>$replay])); }
                }
                global $phpbb_root_path;
                $storage=new \uii\portal\service\evidence($phpbb_root_path);
                foreach (\uii\portal\service\case_schema::uploadSlots($kind,$creating) as $slot=>$label) {
                    $upload=$this->request->file('photo_'.$slot);
                    if (!$upload || (isset($upload['error']) && (int)$upload['error']===UPLOAD_ERR_NO_FILE)) { continue; }
                    \uii\portal\service\evidence::checkCapacity($kind,$data['photos'] ?? [],$slot);
                    $saved=$storage->store($upload);$saved['slot']=$slot;$newFiles[]=$saved;
                    $data['photos'][]=$saved;
                }
                [$status,$started,$deadline,$answered,$closed]=$this->caseTransition($row,$data,$action,$manager,$now,$creating);
                // Client-supplied author/assignee IDs are deliberately never read.
                $assigned=$creating ? $actor : (int)$row['assigned_id'];
                $update=['assigned_id'=>$assigned,'title'=>$row['title'],'status'=>$status,'updated_at'=>$now,'started_at'=>$started,'deadline_at'=>$deadline,'answered_at'=>$answered,'closed_at'=>$closed,'payload'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'version'=>$version+1];
                if (!$creating) {
                    $this->db->sql_query('UPDATE '.$this->prefix.'uii_cases SET '.$this->db->sql_build_array('UPDATE',$update).' WHERE case_id = '.$id.' AND version = '.$version.' AND closed_at = 0');
                    if (!$this->db->sql_affectedrows()) { throw new \InvalidArgumentException('Дело уже изменено другим сотрудником. Обновите страницу перед повторным сохранением.'); }
                } else {
                    $update+=['kind'=>$kind,'author_id'=>$actor,'created_at'=>$now];
                    $this->db->sql_query('INSERT INTO '.$this->prefix.'uii_cases '.$this->db->sql_build_array('INSERT',$update));
                    $id=(int)$this->db->sql_nextid();
                    $forumId=(int)$this->configValue('uii_forum_'.$kind,0);
                    if (!$forumId) { throw new \InvalidArgumentException('Сначала выполните миграции служебных разделов.'); }
                    $topicId=\uii\portal\service\topics::create($forumId,$id,$row['title'],$this->helper->route('uii_portal_case',['id'=>$id],true));
                    $this->db->sql_query('UPDATE '.$this->prefix.'uii_cases SET topic_id = '.$topicId.' WHERE case_id = '.$id);
                    $tickets->complete($ticket,$id);
                }
                if ($closed && !empty($row['topic_id'])) { $this->db->sql_query('UPDATE '.TOPICS_TABLE.' SET topic_status = '.ITEM_LOCKED.' WHERE topic_id = '.(int)$row['topic_id']); }
                $changes=array_diff_assoc(array_filter($data,'is_string'),array_filter($previous,'is_string'));
                if ($newFiles) { $changes['photo']=implode(', ',array_map(static function($photo) use ($kind) { return \uii\portal\service\evidence::slots($kind)[$photo['slot']]; },$newFiles)); }
                $history=['case_id'=>$id,'actor_id'=>$actor,'created_at'=>$now,'action'=>$action,'details'=>json_encode(['status'=>$status,'title'=>$row['title'],'version'=>$version+1,'assigned_id'=>$assigned,'changes'=>$changes],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
                $this->db->sql_query('INSERT INTO '.$this->prefix.'uii_history '.$this->db->sql_build_array('INSERT',$history));
                $this->db->sql_transaction('commit');$transaction=false;
                redirect($this->helper->route('uii_portal_case',['id'=>$id]));
            } catch (\Throwable $exception) {
                if ($transaction) { $this->db->sql_transaction('rollback'); }
                if ($storage) { foreach ($newFiles as $saved) { $storage->discard($saved['token']); } }
                $data['photos']=$previous['photos'] ?? [];
                if (!$exception instanceof \InvalidArgumentException) { throw $exception; }
                $error=$exception->getMessage();
            }
        }
        if ($creating && !preg_match('/^[a-f0-9]{64}$/D',$ticket)) { $ticket=$tickets->issue($actor,$kind,time()); }
        $uploadFields=[];
        foreach (\uii\portal\service\case_schema::uploadSlots($kind,$creating) as $slot=>$label) {
            $savedUrl='';
            foreach (($data['photos'] ?? []) as $photo) { if (($photo['slot'] ?? '')===$slot) { $savedUrl=$this->helper->route('uii_portal_photo_viewer',['id'=>(int)$row['case_id'],'token'=>$photo['token'],'inline'=>1]);break; } }
            $legacy='';
            if (substr($slot,-4)==='_url' && !empty($data[$slot])) { try { $legacy=\uii\portal\domain\workflow::url($data[$slot]); } catch (\InvalidArgumentException $exception) {} }
            $uploadFields[$slot]=['NAME'=>'photo_'.$slot,'LABEL'=>$label,'SAVED_URL'=>$savedUrl,'LEGACY_URL'=>$savedUrl ? '' : $legacy];
        }
        $formFields=[];
        foreach ($definitions as $key=>[$label,$type,$required]) {
            $hints=['planned_at'=>'ГГГГ-ММ-ДД ЧЧ:ММ. Если оставить пустым, будет использовано текущее московское время.','answer_url'=>'Заполните, когда ответ действительно получен, и нажмите «Завершить запрос».','completion_note'=>'Руководитель может завершить запрос без ответа после истечения срока, указав причину.'];
            $formFields[$key]=['NAME'=>$key,'LABEL'=>$label,'TYPE'=>$type,'REQUIRED'=>$required,'VALUE'=>$data[$key] ?? '', 'HINT'=>$hints[$key] ?? '', 'CHOICES'=>$key==='inspection_type' ? ['плановая'=>'Плановая','внеплановая'=>'Внеплановая'] : []];
        }
        $titles=['complaint'=>'Жалоба','request'=>'Служебный запрос','inspection'=>'Проверка состава'];
        $this->template->assign_vars(['FORM_TITLE'=>$creating ? $titles[$kind] : $titles[$kind].' №'.$id,'FORM_ACTION'=>$creating ? $this->helper->route('uii_portal_create',['kind'=>$kind]) : $this->helper->route('uii_portal_edit',['id'=>$id]),'CASE_TITLE'=>$row['title'],'FIELDS'=>$data,'FORM_FIELDS'=>$formFields,'UPLOAD_FIELDS'=>$uploadFields,'CREATION_KEY'=>$ticket,'VERSION'=>(int)$row['version'],'IS_NEW'=>$creating,'HAS_STARTED'=>(bool)$row['started_at'],'CAN_CLOSE_CASE'=>$kind!=='complaint' || $manager,'IS_MANAGER'=>$manager,'RESPONSIBLE_NAME'=>$row['author_name'] ?? $this->user->data['username'],'ERROR'=>$error]);
        return $this->helper->render('@uii_portal/form_'.$kind.'.html','Оформление дела');
    }
    private function caseTransition(array $row, array &$data, string $action, bool $manager, int $now, bool $creating): array
    {
        $status=$row['status'];$started=(int)$row['started_at'];$deadline=(int)$row['deadline_at'];$answered=(int)$row['answered_at'];$closed=0;
        if ($row['kind']==='complaint') {
            if (\uii\portal\domain\workflow::hasMaterial($data,'document_url') || \uii\portal\domain\workflow::hasMaterial($data,'protocol')) { $status='in_progress'; }
            if ($action==='close') {
                if (!$manager) { throw new \InvalidArgumentException('Закрытие жалоб доступно руководителю.'); }
                \uii\portal\domain\workflow::closeComplaint($data);$status='closed';$closed=$now;
            }
        } elseif ($row['kind']==='request') {
            if ($action==='notify' && !$started) {
                foreach (['document_url','notification_url'] as $slot) {
                    if (!\uii\portal\domain\workflow::hasMaterial($data,$slot)) { throw new \InvalidArgumentException('Прикрепите скриншот запроса и доказательство уведомления перед началом срока.'); }
                }
                $started=$now;$deadline=\uii\portal\domain\workflow::deadline($now);
            }
            $answer=trim((string)($data['answer_url'] ?? ''));
            if ($answered && $answer==='') { throw new \InvalidArgumentException('Нельзя удалить уже зарегистрированный ответ.'); }
            if ($action==='close') {
                if (!$started) { throw new \InvalidArgumentException('Сначала подтвердите начало срока.'); }
                if ($answer!=='') { \uii\portal\domain\workflow::url($answer);$answered=$answered ?: $now; }
                elseif (!$manager || $deadline<=$started || $deadline>$now || empty($data['completion_note'])) { throw new \InvalidArgumentException('Добавьте ссылку на ответ. Просроченный запрос без ответа может завершить руководитель, заполнив «Если ответ не поступил».'); }
                $closed=$now;
            } elseif (!$answered && !$creating && !empty($row['started_at']) && $answer!=='') {
                throw new \InvalidArgumentException('Для регистрации ответа нажмите «Завершить запрос». Ответ добавляется на завершающем этапе.');
            }
            $status=$started ? \uii\portal\domain\workflow::requestState($deadline,$answered,(bool)$closed,$now) : 'created';
        } else {
            if (!in_array($data['inspection_type'],['плановая','внеплановая'],true)) { throw new \InvalidArgumentException('Выберите тип проверки.'); }
            if (empty($data['planned_at'])) { $data['planned_at']=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i'); }
            $data['planned_at']=str_replace('T',' ',$data['planned_at']);
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i',$data['planned_at'],new \DateTimeZone('Europe/Moscow'));
            if (!$date || $date->format('Y-m-d H:i')!==$data['planned_at']) { throw new \InvalidArgumentException('Дата и время проверки: ГГГГ-ММ-ДД ЧЧ:ММ (МСК).'); }
            if ($creating) { $status='planned'; }
            if ($action==='start') {
                if (!\uii\portal\domain\workflow::hasMaterial($data,'start_url')) { throw new \InvalidArgumentException('Прикрепите скриншот начала проверки.'); }
                $started=$started ?: $now;$status='in_progress';
            }
            if ($action==='cancel') { $status='cancelled';$closed=$now; }
            if ($action==='close') { \uii\portal\domain\workflow::closeInspection($data,$started);$status='closed';$closed=$now; }
        }
        return [$status,$started,$deadline,$answered,$closed];
    }
    private function configValue($key,$default)
    {
        if (array_key_exists($key,$this->configValues)) { return $this->configValues[$key]; }
        $r=$this->db->sql_query("SELECT config_value FROM ".$this->prefix."config WHERE config_name = '".$this->db->sql_escape($key)."'");
        $row=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
        return $this->configValues[$key]=$row ? $row['config_value'] : $default;
    }
    public function employees()
    {
        $this->requireAccess();
        if (!$this->configValue('uii_navigation_ready',0)) { throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null,'Обновление сотрудников ещё не применено. Выполните scripts/apply-portal.sh.'); }
        $actor=(int)$this->user->data['user_id'];
        $level=$this->role();
        $error=''; $message='';
        add_form_key('uii_accounts');
        if ($this->request->is_set_post('manage')) {
            try {
                if ($level<2 || !check_form_key('uii_accounts')) { throw new \InvalidArgumentException('Нет прав либо истёк срок действия формы.'); }
                global $phpbb_root_path,$phpEx,$phpbb_container;
                require_once $phpbb_root_path.'includes/acp/auth.'.$phpEx;
                require_once $phpbb_root_path.'includes/functions_user.'.$phpEx;
                $action=$this->request->variable('action','create');
                $id=$this->request->variable('user_id',0);
                $newLevel=$this->request->variable('role',1);
                if(!in_array($action,['create','update','reset','block','restore'],true) || ($action==='create' && $id!==0) || ($action!=='create' && $id<=0)){throw new \InvalidArgumentException('Выберите существующего сотрудника и допустимое действие.');}
                if ($id===$actor) { throw new \InvalidArgumentException('Изменение собственной учётной записи здесь запрещено.'); }
                $target=null; $targetLevel=0;
                if ($id) {
                    $r=$this->db->sql_query('SELECT user_id, username, user_type, uii_gender FROM '.$this->prefix.'users WHERE user_id = '.(int)$id);
                    $target=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
                    if (!$target || $target['user_type']==USER_FOUNDER || $target['user_type']==USER_IGNORE) { throw new \InvalidArgumentException('Этот системный аккаунт нельзя изменять.'); }
                    $targetLevel=\uii\portal\service\roles::level($this->db,$this->prefix,$id);
                    if (!$targetLevel) { throw new \InvalidArgumentException('Пользователь не состоит в отделе.'); }
                }
                if (!\uii\portal\service\roles::mayManage($level,$targetLevel,$newLevel)) { throw new \InvalidArgumentException('Недопустимая операция над ролью сотрудника.'); }
                $gender=$this->request->variable('gender',$target['uii_gender'] ?? '');
                if (in_array($action,['create','update'],true) && !in_array($gender,['male','female','unspecified'],true)) { throw new \InvalidArgumentException('Выберите пол персонажа.'); }
                if ($action==='create' && $gender==='unspecified') { throw new \InvalidArgumentException('Выберите пол персонажа.'); }
                if (!in_array($action,['create','update'],true)) { $gender=$target['uii_gender'] ?? 'unspecified'; }
                $password=null;
                if ($action==='create') {
                    $nickname=trim($this->request->variable('nickname',''));
                    if (!\uii\portal\domain\workflow::nickname($nickname) || strlen($nickname)>20) { throw new \InvalidArgumentException('Ник: Name_Surname, до 20 символов.'); }
                    $r=$this->db->sql_query("SELECT user_id FROM ".$this->prefix."users WHERE username_clean = '".$this->db->sql_escape(utf8_clean_string($nickname))."'");
                    $exists=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
                    if ($exists) { throw new \InvalidArgumentException('Такой ник уже существует.'); }
                    $r=$this->db->sql_query("SELECT group_id FROM ".$this->prefix."groups WHERE group_name = 'REGISTERED'");
                    $registered=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
                    $password=bin2hex(random_bytes(12));
                    $id=user_add(['username'=>$nickname,'user_email'=>'','group_id'=>(int)$registered['group_id'],'user_type'=>USER_NORMAL,'user_password'=>$phpbb_container->get('passwords.manager')->hash($password),'uii_force_password'=>1,'uii_gender'=>$gender]);
                    if (!$id) { throw new \InvalidArgumentException('Не удалось создать сотрудника.'); }
                } elseif ($action==='reset') {
                    $password=bin2hex(random_bytes(12)); $nickname=$target['username'];
                    $this->db->sql_query('UPDATE '.$this->prefix.'users SET '.$this->db->sql_build_array('UPDATE',['user_password'=>$phpbb_container->get('passwords.manager')->hash($password),'uii_force_password'=>1]).' WHERE user_id = '.$id);
                } elseif ($action==='block' || $action==='restore') {
                    $blockReason=$action==='block' ? \uii\portal\service\staff::reason($this->request->variable('block_reason','other')) : '';
                    $blockNote=$action==='block' ? trim($this->request->variable('block_note','',true)) : '';
                    if (utf8_strlen($blockNote)>255) { throw new \InvalidArgumentException('Комментарий к блокировке - до 255 символов.'); }
                    $this->db->sql_query('UPDATE '.$this->prefix.'users SET '.$this->db->sql_build_array('UPDATE',['uii_blocked'=>$action==='block' ? 1 : 0,'uii_block_reason'=>$blockReason,'uii_block_note'=>$blockNote]).' WHERE user_id = '.$id);
                } elseif ($action!=='update') { throw new \InvalidArgumentException('Неизвестное действие.'); }
                if ($action==='create' || $action==='update') {
                    $names=array_flip(\uii\portal\service\roles::LEVELS);
                    $r=$this->db->sql_query("SELECT group_id, group_name FROM ".$this->prefix."groups WHERE ".$this->db->sql_in_set('group_name',array_keys(\uii\portal\service\roles::LEVELS)));
                    $groups=[]; while ($g=$this->db->sql_fetchrow($r)) { $groups[]=$g; } $this->db->sql_freeresult($r);
                    foreach ($groups as $g) {
                        if ($g['group_name']===$names[$newLevel]) { group_user_add((int)$g['group_id'],[$id],false,false,true); }
                        else { group_user_del((int)$g['group_id'],[$id]); }
                    }
                    $this->db->sql_query('UPDATE '.$this->prefix.'users SET '.$this->db->sql_build_array('UPDATE',['uii_gender'=>$gender]).' WHERE user_id = '.$id);
                }
                $this->db->sql_query('DELETE FROM '.$this->prefix.'sessions WHERE session_user_id = '.$id);
                $this->db->sql_query('DELETE FROM '.$this->prefix.'sessions_keys WHERE user_id = '.$id);
                $adminAuth=new \auth_admin(); $adminAuth->acl_clear_prefetch($id);
                $this->db->sql_query('INSERT INTO '.$this->prefix.'uii_history '.$this->db->sql_build_array('INSERT',['case_id'=>0,'actor_id'=>$actor,'created_at'=>time(),'action'=>'account_'.$action,'details'=>json_encode(['user_id'=>$id,'role'=>in_array($action,['create','update'],true) ? $newLevel : $targetLevel,'gender'=>$gender,'block_reason'=>$action==='block' ? $blockReason : '', 'block_note'=>$action==='block' ? $blockNote : ''])]));
                if ($password!==null) {
                    $text="УИИ - Данные для входа\n\nЛогин: ".$nickname."\nПароль: ".$password."\nРоль: ".\uii\portal\service\roles::label($action==='create' ? $newLevel : $targetLevel)."\nАдрес форума: ".generate_board_url()."\n\nПри первом входе смените пароль.\n";
                    return new \Symfony\Component\HttpFoundation\Response($text,200,['Content-Type'=>'text/plain; charset=UTF-8','Content-Disposition'=>'attachment; filename="uii-credentials.txt"','Cache-Control'=>'no-store, private','Pragma'=>'no-cache']);
                }
                $message='Изменения сохранены.';
            } catch (\InvalidArgumentException $e) { $error=$e->getMessage(); }
        }
        $status=$this->request->variable('status','all');
        if (!isset(\uii\portal\service\staff::FILTERS[$status])) { $status='all'; }
        $query=trim($this->request->variable('q','',true));
        $roleFilter=$this->request->variable('filter_role',0);
        if ($roleFilter<0 || $roleFilter>4) { $roleFilter=0; }
        $start=max(0,$this->request->variable('start',0));
        $minutes=max(1,min(60,(int)$this->configValue('load_online_time','5')));
        $cutoff=time()-$minutes*60;
        $source=\uii\portal\service\staff::source($this->prefix,USER_NORMAL,USER_FOUNDER);
        $where=$source['where'].' AND '.\uii\portal\service\staff::condition($status,$cutoff);
        if ($roleFilter) { $where.=' AND ('.$source['role'].') = '.$roleFilter; }
        if ($query!=='') { $where.=' AND u.username '.$this->db->sql_like_expression($this->db->get_any_char().$this->db->sql_escape($query).$this->db->get_any_char()); }
        $summary=$this->db->sql_query('SELECT COUNT(*) AS total, SUM(CASE WHEN u.uii_blocked = 1 THEN 1 ELSE 0 END) AS blocked, SUM(CASE WHEN u.uii_blocked = 0 AND COALESCE(s.last_seen,0) >= '.$cutoff.' THEN 1 ELSE 0 END) AS online'.$source['from'].' WHERE '.$source['where']);
        $totals=$this->db->sql_fetchrow($summary);$this->db->sql_freeresult($summary);
        $r=$this->db->sql_query_limit('SELECT u.user_id, u.username, u.user_type, u.uii_gender, u.uii_blocked, u.uii_block_reason, u.uii_block_note, u.user_lastvisit, COALESCE(s.last_seen,0) AS last_seen, '.$source['role'].' AS employee_role'.$source['from'].' WHERE '.$where.' ORDER BY u.uii_blocked ASC, u.username ASC',26,$start);
        $count=0;
        while ($row=$this->db->sql_fetchrow($r)) {
            if (++$count>25) { break; }
            $employeeRole=(int)$row['employee_role'];
            $online=!$row['uii_blocked'] && (int)$row['last_seen'] >= $cutoff;
            $last=max((int)$row['last_seen'],(int)$row['user_lastvisit']);
            $this->template->assign_block_vars('employees',['GENDER'=>$row['uii_gender'],'AVATAR'=>\uii\portal\service\avatars::url($row['uii_gender']),'ROLE'=>$employeeRole,'ROLE_LABEL'=>\uii\portal\service\roles::label($employeeRole),'CAN_EDIT'=>$row['user_type']!=USER_FOUNDER && (int)$row['user_id']!==$actor && \uii\portal\service\roles::mayManage($level,$employeeRole,1),'ID'=>(int)$row['user_id'],'NAME'=>$row['username'],'URL'=>$this->helper->route('uii_portal_profile',['id'=>(int)$row['user_id']]),'BLOCKED'=>(bool)$row['uii_blocked'],'ONLINE'=>$online,'BLOCK_REASON'=>\uii\portal\service\staff::REASONS[$row['uii_block_reason']] ?? 'Доступ ограничен руководством','BLOCK_NOTE'=>$row['uii_block_note'],'LAST'=>$last ? $this->user->format_date($last) : 'Ещё не входил','OPEN'=>$error!=='' && $this->request->variable('user_id',0)===(int)$row['user_id']]);
        }
        $this->db->sql_freeresult($r);
        foreach (\uii\portal\service\staff::FILTERS as $key=>$label) { $this->template->assign_block_vars('staff_filters',['VALUE'=>$key,'LABEL'=>$label,'SELECTED'=>$status===$key]); }
        foreach ([0=>'Все роли',1=>'Сотрудник УИИ',2=>'Заместитель начальника',3=>'Начальник УИИ',4=>'Руководство фракции'] as $key=>$label) { $this->template->assign_block_vars('staff_roles',['VALUE'=>$key,'LABEL'=>$label,'SELECTED'=>$roleFilter===$key]); }
        $base=['status'=>$status,'q'=>$query,'filter_role'=>$roleFilter];
        $this->template->assign_vars(['CAN_MANAGE'=>$level>=2,'CAN_PROMOTE'=>$level===4,'ERROR'=>$error,'SUCCESS'=>$message,'STAFF_QUERY'=>$query,'STAFF_URL'=>$this->helper->route('uii_portal_employees'),'STAFF_TOTAL'=>(int)$totals['total'],'STAFF_ONLINE'=>(int)$totals['online'],'STAFF_BLOCKED'=>(int)$totals['blocked'],'ONLINE_MINUTES'=>$minutes,'PREVIOUS_URL'=>$start ? $this->helper->route('uii_portal_employees',$base+['start'=>max(0,$start-25)]) : '','NEXT_URL'=>$count>25 ? $this->helper->route('uii_portal_employees',$base+['start'=>$start+25]) : '', 'CREATE_ERROR'=>$error!=='' && $this->request->variable('action','')==='create','CREATE_NICKNAME'=>$this->request->variable('action','')==='create' ? $this->request->variable('nickname','',true) : '','CREATE_GENDER'=>$this->request->variable('gender',''),'CREATE_ROLE'=>$this->request->variable('role',1)]);
        return $this->helper->render('@uii_portal/employees.html','Сотрудники УИИ');
    }
    public function password()
    {
        if (empty($this->user->data['is_registered'])) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Требуется вход.'); }
        add_form_key('uii_password'); $error='';
        if ($this->request->is_set_post('save')) {
            global $phpbb_container;
            $manager=$phpbb_container->get('passwords.manager');
            $old=$this->request->variable('old_password','',true);
            $password=$this->request->variable('new_password','',true);
            $confirm=$this->request->variable('confirm_password','',true);
            if (!check_form_key('uii_password')) { $error='Срок действия формы истёк.'; }
            elseif (!$manager->check($old,$this->user->data['user_password'])) { $error='Текущий пароль неверен.'; }
            elseif (strlen($password)<12 || strlen($password)>128 || $password!==$confirm || $password===$old) { $error='Новый пароль: от 12 до 128 байт, должен отличаться от старого. Подтверждение должно совпадать.'; }
            else {
                $id=(int)$this->user->data['user_id'];
                $this->db->sql_query('UPDATE '.$this->prefix.'users SET '.$this->db->sql_build_array('UPDATE',['user_password'=>$manager->hash($password),'user_passchg'=>time(),'uii_force_password'=>0]).' WHERE user_id = '.$id);
                $this->db->sql_query('DELETE FROM '.$this->prefix.'sessions_keys WHERE user_id = '.$id);
                $this->db->sql_query("DELETE FROM ".$this->prefix."sessions WHERE session_user_id = ".$id." AND session_id <> '".$this->db->sql_escape($this->user->session_id)."'");
                redirect($this->helper->route('uii_portal_tasks'));
            }
        }
        $this->template->assign_var('ERROR',$error);
        return $this->helper->render('@uii_portal/password.html','Смена пароля');
    }
    public function account()
    {
        $this->requireAccess();
        $this->template->assign_vars(['ACCOUNT_NAME'=>$this->user->data['username'],'ACCOUNT_ROLE'=>\uii\portal\service\roles::label($this->role()), 'ACCOUNT_SINCE'=>$this->user->format_date($this->user->data['user_regdate']),'ACCOUNT_LAST'=>!empty($this->user->data['user_lastvisit']) ? $this->user->format_date($this->user->data['user_lastvisit']) : 'Первый вход','U_UII_PASSWORD'=>$this->helper->route('uii_portal_password')]);
        $this->template->assign_vars(\uii\portal\service\statistics::employee($this->db,$this->prefix,(int)$this->user->data['user_id'],time()));
        return $this->helper->render('@uii_portal/account.html','Мой аккаунт');
    }
    public function admin()
    {
        $this->requireAccess();
        if ($this->role()<2) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Нет доступа к управлению.'); }
        return $this->helper->render('@uii_portal/admin.html','Управление');
    }
    public function navigation()
    {
        $this->requireAccess();
        if ($this->role()!==4 || !$this->auth->acl_get('a_')) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Меню настраивает администратор форума из руководства фракции.'); }
        if (!$this->configValue('uii_navigation_ready',0)) { throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null,'Сначала выполните обновление портала.'); }
        $state=\uii\portal\service\navigation::load($this->db,$this->prefix,true);
        $error=!empty($state['invalid']) ? 'Сохранённая конфигурация повреждена. Показано стандартное меню; его можно сохранить заново.' : '';$success='';add_form_key('uii_navigation');
        if ($this->request->is_set_post('save_navigation')) {
            $transaction=false;
            try {
                if (!check_form_key('uii_navigation')) { throw new \InvalidArgumentException('Срок действия формы истёк. Обновите страницу.'); }
                $version=$this->request->variable('version',0);
                if ($version!==$state['version']) { throw new \InvalidArgumentException('Меню изменено другим администратором. Обновите страницу.'); }
                $raw=$this->request->raw_variable('layout','',\phpbb\request\request_interface::POST);
                if (!is_string($raw) || strlen($raw)>20000) { throw new \InvalidArgumentException('Слишком большая конфигурация меню.'); }
                $layout=\uii\portal\service\navigation::validate(json_decode($raw,true));
                $this->db->sql_transaction('begin');$transaction=true;
                $this->db->sql_query('UPDATE '.$this->prefix.'uii_navigation SET '.$this->db->sql_build_array('UPDATE',['payload'=>json_encode($layout,JSON_UNESCAPED_UNICODE),'version'=>$version+1]).' WHERE nav_id = 1 AND version = '.$version);
                if (!$this->db->sql_affectedrows()) { throw new \InvalidArgumentException('Меню изменено другим администратором. Обновите страницу.'); }
                $this->db->sql_query('INSERT INTO '.$this->prefix.'uii_history '.$this->db->sql_build_array('INSERT',['case_id'=>0,'actor_id'=>(int)$this->user->data['user_id'],'created_at'=>time(),'action'=>'navigation_update','details'=>json_encode(['groups'=>count($layout),'version'=>$version+1])]));
                $this->db->sql_transaction('commit');$transaction=false;
                redirect($this->helper->route('uii_portal_navigation',['saved'=>1]));
            } catch (\Throwable $exception) {
                if ($transaction) { $this->db->sql_transaction('rollback'); }
                if (!$exception instanceof \InvalidArgumentException) { throw $exception; }
                $error=$exception->getMessage();
            }
        }
        if ($this->request->variable('saved',0)) { $success='Меню сохранено. Изменения действуют для всех сотрудников с учётом их прав.'; }
        $this->template->assign_vars(['NAV_ERROR'=>$error,'NAV_SUCCESS'=>$success,'NAV_VERSION'=>$state['version'],'NAV_DEFAULTS'=>htmlspecialchars(json_encode(\uii\portal\service\navigation::defaults(),JSON_UNESCAPED_UNICODE),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'NAV_LAYOUT'=>htmlspecialchars(json_encode($state['layout'],JSON_UNESCAPED_UNICODE),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')]);
        foreach (\uii\portal\service\navigation::catalog() as $id=>$item) { $this->template->assign_block_vars('nav_catalog',['ID'=>$id,'LABEL'=>$item[0],'LEVEL'=>$item[4]]); }
        return $this->helper->render('@uii_portal/navigation.html','Настройка меню');
    }
    public function settings()
    {
        $this->requireAccess();
        $manager=$this->role()>=3;
        $error=''; $success='';add_form_key('uii_settings');
        if ($this->request->is_set_post('save')) { $error='Для новых запросов срок отсчитывается только от подтверждения уведомления.'; }
        global $phpbb_root_path;
        $used=$manager ? (new \uii\portal\service\evidence($phpbb_root_path))->usage() : 0;
        $this->template->assign_vars(['PHOTO_USED'=>round($used/1048576,1),'PHOTO_QUOTA'=>\uii\portal\service\evidence::QUOTA_BYTES/1048576,'PHOTO_USAGE'=>min(100,(int)ceil($used*100/\uii\portal\service\evidence::QUOTA_BYTES))]);
        $this->template->assign_vars(['CAN_CONFIGURE'=>$manager,'PUBLICATION'=>$this->configValue('uii_timer_start','notification')==='publication','ERROR'=>$error,'SUCCESS'=>$success]);
        return $this->helper->render('@uii_portal/settings.html','Настройки портала');
    }
    public function activity()
    {
        $this->requireAccess();
        if ($this->role()<3) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Журнал доступен начальнику и руководству фракции.'); }
        $start=max(0,$this->request->variable('start',0));
        $r=$this->db->sql_query_limit('SELECT h.*, u.username FROM '.$this->prefix.'uii_history h LEFT JOIN '.$this->prefix.'users u ON u.user_id = h.actor_id ORDER BY h.history_id DESC',51,$start);
        $count=0;
        while ($event=$this->db->sql_fetchrow($r)) {
            if (++$count>50) { break; }
            $this->template->assign_block_vars('activity',['ACTOR'=>$event['username'],'DATE'=>$this->user->format_date($event['created_at']),'ACTION'=>$this->eventLabel($event['action']),'DETAILS'=>$this->eventDetails($event['details']),'CASE'=>(int)$event['case_id'],'CASE_URL'=>$event['case_id'] ? $this->helper->route('uii_portal_case',['id'=>(int)$event['case_id']]) : '']);
        }
        $this->db->sql_freeresult($r);
        $this->template->assign_vars(['PREVIOUS_URL'=>$start>0 ? $this->helper->route('uii_portal_activity',['start'=>max(0,$start-50)]) : '', 'NEXT_URL'=>$count>50 ? $this->helper->route('uii_portal_activity',['start'=>$start+50]) : '']);
        return $this->helper->render('@uii_portal/activity.html','Журнал действий');
    }
    public function profile($id)
    {
        $this->requireAccess();$id=(int)$id;
        $r=$this->db->sql_query('SELECT user_id, user_type, username, uii_gender, user_regdate, user_lastvisit FROM '.$this->prefix.'users WHERE user_id = '.$id);
        $person=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);
        if (!$person) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Сотрудник не найден.'); }
        $role=\uii\portal\service\roles::effective($this->db,$this->prefix,$person);
        if($role<1){throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Сотрудник не найден.');}
        $roles=[0=>'Администратор / не назначено',1=>'Сотрудник УИИ',2=>'Заместитель начальника УИИ',3=>'Начальник УИИ',4=>'Руководство фракции'];
        $this->template->assign_vars(\uii\portal\service\statistics::employee($this->db,$this->prefix,$id,time()));
        $this->template->assign_vars(['PERSON_AVATAR'=>\uii\portal\service\avatars::url($person['uii_gender']),'PERSON_NAME'=>$person['username'],'PERSON_ROLE'=>$roles[$role],'PERSON_CREATED'=>$this->user->format_date($person['user_regdate']),'PERSON_LAST'=>$person['user_lastvisit'] ? $this->user->format_date($person['user_lastvisit']) : 'Ещё не входил']);
        return $this->helper->render('@uii_portal/profile.html','Профиль сотрудника');
    }
    public function tasks()
    {
        $this->requireAccess();
        $manager=$this->role()>=2;
        $filter=$this->request->variable('filter','mine');
        if (!in_array($filter,['mine','all','urgent','overdue','completed'],true)) { $filter='mine'; }
        $start=max(0,$this->request->variable('start',0));
        $where=$filter==='completed' ? 'closed_at > 0' : 'closed_at = 0';
        if (!$manager || $filter==='mine') { $where.=' AND assigned_id = '.(int)$this->user->data['user_id']; }
        if ($filter==='urgent') { $where.=' AND answered_at = 0 AND deadline_at > '.time().' AND deadline_at <= '.(time()+6*3600); }
        if ($filter==='overdue') { $where.=' AND answered_at = 0 AND deadline_at > 0 AND deadline_at <= '.time(); }
        $result=$this->db->sql_query_limit($this->caseSelect(true).' WHERE '.$where.' ORDER BY c.updated_at DESC, c.case_id DESC',51,$start);
        $count=0;
        while ($row=$this->db->sql_fetchrow($result)) {
            if (++$count>50) { break; }
            $data=json_decode($row['payload'],true) ?: [];
            $action='Проверить дело';
            if ($row['kind']==='complaint') {
                $action=!\uii\portal\domain\workflow::hasMaterial($data,'protocol') ? 'Добавить Фото ДО' : (!\uii\portal\domain\workflow::hasMaterial($data,'document_url') ? 'Добавить фото протокола жалобы' : (empty($data['decision']) ? 'Внести решение' : 'Проверить готовность к закрытию'));
            } elseif ($row['kind']==='request') { $action=$row['started_at'] ? 'Проверить ответ на запрос' : 'Подтвердить начало срока'; }
            else { $action=$row['started_at'] ? 'Завершить проверку состава' : 'Начать проверку состава'; }
            $this->template->assign_block_vars('tasks',[...$this->peopleVars($row),'URL'=>$this->helper->route('uii_portal_case',['id'=>(int)$row['case_id']]),'NUMBER'=>(int)$row['case_id'],'TITLE'=>$row['title'],'ACTION'=>$row['status']==='cancelled' ? 'Проверка отменена' : ($row['closed_at'] ? 'Работа завершена' : $action),'STATUS'=>$this->statusLabel($row),'TONE'=>$this->deadlineInfo($row)['tone'],'REMAINING'=>$this->deadlineInfo($row)['remaining'],'OVERDUE'=>$row['deadline_at']>0 && $row['deadline_at']<=time() && !$row['answered_at'] && !$row['closed_at']]);
        }
        $this->db->sql_freeresult($result);
        foreach (['mine'=>'Мои задачи','all'=>'Все задачи','urgent'=>'Срочные','overdue'=>'Просроченные','completed'=>'Завершённые'] as $key=>$label) {
            if ($key==='all' && !$manager) { continue; }
            $this->template->assign_block_vars('filters',['URL'=>$this->helper->route('uii_portal_tasks',['filter'=>$key]),'LABEL'=>$label,'SELECTED'=>$key===$filter]);
        }
        $titles=['mine'=>'Мои задачи','all'=>'Все задачи отдела','urgent'=>'Срочные задачи','overdue'=>'Просроченные запросы','completed'=>'Завершённые задачи'];
        $this->template->assign_vars(['PREVIOUS_URL'=>$start>0 ? $this->helper->route('uii_portal_tasks',['filter'=>$filter,'start'=>max(0,$start-50)]) : '', 'NEXT_URL'=>$count>50 ? $this->helper->route('uii_portal_tasks',['filter'=>$filter,'start'=>$start+50]) : '', 'TASK_TITLE'=>$titles[$filter],'TASK_SCOPE'=>$manager && $filter!=='mine' ? 'Показаны дела отдела, по 50 на странице.' : 'Показаны назначенные вам дела, по 50 на странице.']);
        return $this->helper->render('@uii_portal/tasks.html','Центр задач');
    }
}
