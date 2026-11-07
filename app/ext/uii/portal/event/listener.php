<?php
namespace uii\portal\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
    private $user;
    private $request;
    private $template;
    private $helper;
    private $actorLevel;
    private $actorKey;
    public function __construct(\phpbb\user $user, \phpbb\request\request_interface $request, $template = null, $helper = null)
    {
        $this->user = $user;
        $this->request = $request;
        $this->template = $template;
        $this->helper = $helper;
    }
    public static function getSubscribedEvents()
    {
        return ['core.user_setup_after'=>'guard', 'core.permissions'=>'permissions',
            'core.notification_manager_add_notifications_before'=>'stopNotifications',
            'core.notification_manager_add_notifications_for_users_modify_data'=>'stopNotificationRecipients',
            'core.viewtopic_modify_post_row'=>'fixedPostAvatar',
            'core.modify_uploaded_file'=>'compressAttachment',
            'core.page_header_after'=>'simplifyPage'];
    }
    public function permissions($event)
    {
        $permissions=$event['permissions'];
        $permissions['u_uii_bug_report']=['lang'=>'ACL_U_UII_BUG_REPORT','cat'=>'misc'];
        $permissions['a_uii_updates']=['lang'=>'ACL_A_UII_UPDATES','cat'=>'misc'];
        $permissions['a_uii_bug_reports']=['lang'=>'ACL_A_UII_BUG_REPORTS','cat'=>'misc'];
        $event['permissions']=$permissions;
        $this->user->add_lang_ext('uii/portal','permissions');
    }
    private function role(): int
    {
        global $db, $table_prefix;
        $key=(int)($this->user->data['user_id'] ?? 0).':'.(int)($this->user->data['user_type'] ?? 0).':'.(int)!empty($this->user->data['is_registered']);
        if ($this->actorLevel === null || $this->actorKey !== $key) {
            $this->actorKey=$key;
            $this->actorLevel = !empty($this->user->data['is_registered'])
                ? \uii\portal\service\roles::effective($db, $table_prefix, $this->user->data) : 0;
        }
        return $this->actorLevel;
    }
    public function guard()
    {
        global $phpbb_container,$db,$table_prefix,$auth,$phpEx;
        global $phpbb_root_path;
        $updatePath=(string)parse_url($this->request->server('REQUEST_URI',''),PHP_URL_PATH);
        $updateAdmin=!empty($this->user->data['is_registered']) && $auth->acl_get('a_uii_updates') && $auth->acl_get('a_extensions');
        $updateControl=($updateAdmin && preg_match('~/uii/admin/updates/?$~D',$updatePath)) || (basename($this->user->page['page_name'])==='ucp.'.$phpEx && in_array($this->request->variable('mode',''),['login','logout'],true));
        if (PHP_SAPI!=='cli' && \uii\portal\service\web_updater::maintenance($phpbb_root_path) && !$updateControl) {
            http_response_code(503);header('Retry-After: 120');header('Cache-Control: no-store');header('Content-Type: text/plain; charset=utf-8');
            exit('УИИ обновляется. Пользователи и дела сохранены. Повторите попытку через несколько минут.');
        }
        $script=basename($this->user->page['page_name']);
        $isLogout=$script==='ucp.'.$phpEx && $this->request->variable('mode','')==='logout';
        $level=!empty($this->user->data['is_registered']) ? $this->role() : 0;
        $this->template = $this->template ?: $phpbb_container->get('template');
        $this->helper = $this->helper ?: $phpbb_container->get('controller.helper');
        $this->user->add_lang_ext('uii/portal', 'portal');
        try { $this->template->assign_vars([
            'U_UII_COMPLAINTS'=>$this->helper->route('uii_portal_cases',['kind'=>'complaint']),
            'U_UII_REQUESTS'=>$this->helper->route('uii_portal_cases',['kind'=>'request']),
            'U_UII_INSPECTIONS'=>$this->helper->route('uii_portal_cases',['kind'=>'inspection']),
            'U_UII_ARCHIVE'=>$this->helper->route('uii_portal_cases',['archive'=>1,'kind'=>'all']),
            'U_UII_TASKS'=>$this->helper->route('uii_portal_tasks'),
            'U_UII_ALL_CASES'=>$this->helper->route('uii_portal_cases',['kind'=>'all']),
            'U_UII_EMPLOYEES'=>$this->helper->route('uii_portal_employees'),
            'U_UII_ADMIN'=>$this->helper->route('uii_portal_admin'),
            'U_UII_NAVIGATION'=>$this->helper->route('uii_portal_navigation'),
            'U_UII_SETTINGS'=>$this->helper->route('uii_portal_settings'),
            'U_UII_PROFILE'=>$this->helper->route('uii_portal_profile',['id'=>(int)$this->user->data['user_id']]),
            'U_UII_ACTIVITY'=>$this->helper->route('uii_portal_activity'),
            'S_UII_MANAGE'=>$level>=2,
            'S_UII_UPDATES'=>$auth->acl_get('a_uii_updates') && $auth->acl_get('a_extensions'),
            'U_UII_UPDATES'=>$this->helper->route('uii_portal_updates'),
            'S_UII_BUG_ADMIN'=>$auth->acl_get('a_uii_bug_reports'),
            'U_UII_BUG_ADMIN'=>$this->helper->route('uii_portal_bug_admin'),
            'S_UII_AUDIT'=>$level>=3,
            'S_UII_SYSTEM_ADMIN'=>$level===4 && $auth->acl_get('a_'),
            'U_UII_ACCOUNT'=>$this->helper->route('uii_portal_account'),
            'UII_USERNAME'=>$this->user->data['username'] ?? '',
            'UII_AVATAR'=>\uii\portal\service\avatars::url($this->user->data['uii_gender'] ?? 'unspecified'),
        ]);
        if (!empty($this->user->data['is_registered'])) { $this->assignNavigation($level); }
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException $e) {
            // Совместимость на время обновления кэша маршрутов.
        }
        // A missing route contains no private data; let the kernel render a real 404 for guests too.
        if (basename($this->request->server('SCRIPT_FILENAME',''))==='app.'.$phpEx && (empty($this->user->data['is_registered']) || $level<1) && $this->isPublicErrorRequest($phpbb_container)) {return;}
        if ($script==='ucp.'.$phpEx && in_array($this->request->variable('mode',''),['terms','privacy'],true)) { $this->deny('Страница отключена.',E_USER_WARNING); }
        if (!empty($this->user->data['is_registered'])) {
            if (!empty($this->user->data['uii_blocked']) && !$isLogout) {
                $this->deny('Учётная запись заблокирована. Обратитесь к руководству.', E_USER_WARNING);
            }
            if (!empty($this->user->data['uii_force_password'])) {
                $uri=parse_url($this->request->server('REQUEST_URI',''), PHP_URL_PATH);
                if ((string)$uri!==(string)parse_url($this->helper->route('uii_portal_password'),PHP_URL_PATH) && !$isLogout) {
                    redirect($this->helper->route('uii_portal_password'));
                }
            }
            if ($level<1 && !$isLogout && !$this->isBugRoute()) { $this->deny('Нет доступа к рабочему пространству.',E_USER_WARNING); }
            if (defined('ADMIN_START') && $level<4) { $this->deny('Системная панель доступна только руководству с административными правами.',E_USER_WARNING); }
            global $db,$auth,$table_prefix,$phpbb_root_path,$phpEx;
            $script=basename($this->user->page['page_name']);
            if($script==='mcp.'.$phpEx && $level<4){$this->deny('Системная модерация доступна только руководству фракции.',E_USER_WARNING);}
            $mode=$this->request->variable('mode','');
            $section=$this->request->variable('i','');
            if (ctype_digit($section)) {
                $moduleClass=defined('ADMIN_START') ? 'acp' : 'ucp';
                $r=$db->sql_query('SELECT module_basename,module_mode FROM '.MODULES_TABLE." WHERE module_class='".$moduleClass."' AND module_id = ".(int)$section);
                $module=$db->sql_fetchrow($r);$db->sql_freeresult($r);
                if($module){$section=$module['module_basename'];$mode=$module['module_mode'];}
            }
            if (defined('ADMIN_START') && \uii\portal\service\acp_policy::disabled($section,$mode)) { $this->deny('Этот раздел отключён в рабочем портале УИИ.',E_USER_WARNING); }
            $path=(string)parse_url($this->request->server('REQUEST_URI',''),PHP_URL_PATH);
            if(preg_match('~/notifications?(?:/|$)~',$path)){$this->deny('Функция отключена.',E_USER_WARNING);}
            if($script==='ucp.'.$phpEx && !in_array($mode,['login','logout','sendpassword','reset_password','delete_cookies','confirm'],true)) {
                $disabled=in_array($section,['pm','ucp_pm','ucp_notifications','notifications'],true) || in_array($mode,['notification_list','notification_options','avatar','compose','view'],true);
                if($disabled || $this->request->server('REQUEST_METHOD','GET')==='POST'){$this->deny('Функция отключена. Используйте настройки аккаунта.',E_USER_WARNING);}
                redirect($this->helper->route('uii_portal_account'));
            }
            if($script==='memberlist.'.$phpEx) {
                if($mode==='email'){$this->deny('Функция отключена.',E_USER_WARNING);}
                if($mode==='viewprofile'){redirect($this->helper->route('uii_portal_profile',['id'=>$this->request->variable('u',(int)$this->user->data['user_id'])]));}
                if(!($mode==='searchuser' && $level===4)){redirect($this->helper->route('uii_portal_employees'));}
            }
            if($script==='posting.'.$phpEx && ($this->request->is_set_post('save') || $this->request->variable('draft_id',0))){$this->deny('Черновики отключены.',E_USER_WARNING);}
            $topic=$this->request->variable('t',0);
            $post=$this->request->variable('p',0);
            if ($script==='file.'.$phpEx) {
                $attachment=$this->request->variable('id',0);
                $r=$db->sql_query('SELECT topic_id, in_message FROM '.ATTACHMENTS_TABLE.' WHERE attach_id = '.(int)$attachment);
                $file=$db->sql_fetchrow($r);$db->sql_freeresult($r);
                if ($file && $file['in_message']) { $this->deny('Вложения личных сообщений отключены.',E_USER_WARNING); }
                if ($file) { $topic=(int)$file['topic_id']; }
            }
            if ($post && !$topic) {
                $r=$db->sql_query('SELECT topic_id FROM '.POSTS_TABLE.' WHERE post_id = '.(int)$post);
                $record=$db->sql_fetchrow($r);$db->sql_freeresult($r);$topic=$record ? (int)$record['topic_id'] : 0;
            }
            if ($topic) {
                $r=$db->sql_query('SELECT * FROM '.$table_prefix.'uii_cases WHERE topic_id = '.(int)$topic);
                $record=$db->sql_fetchrow($r);$db->sql_freeresult($r);
                if ($record) {
                    $allowed=$level>=2 || in_array((int)$this->user->data['user_id'],[(int)$record['author_id'],(int)$record['assigned_id']],true);
                    if (!$allowed || ($script==='posting.'.$phpEx && $record['closed_at'])) {
                        $this->deny('Нет доступа к операции с этим делом.',E_USER_WARNING);
                    }
                    if ($script==='posting.'.$phpEx && $post && $this->request->variable('mode','')==='edit') {
                        $first=$db->sql_query('SELECT topic_first_post_id FROM '.TOPICS_TABLE.' WHERE topic_id = '.(int)$record['topic_id']);
                        $topicRecord=$db->sql_fetchrow($first); $db->sql_freeresult($first);
                        if ($topicRecord && (int)$topicRecord['topic_first_post_id']===$post) {
                            if ($this->request->server('REQUEST_METHOD','GET')==='POST') { $this->deny('Материалы дела редактируются в карточке.',E_USER_WARNING); }
                            redirect($this->helper->route('uii_portal_edit',['id'=>(int)$record['case_id']]));
                        }
                    }
                    $this->template->assign_var('U_UII_CASE',$this->helper->route('uii_portal_case',['id'=>(int)$record['case_id']]));
                    $this->template->assign_vars(['UII_CASE_NUMBER'=>(int)$record['case_id'],'UII_CASE_ASSIGNED'=>(int)$record['assigned_id'],'UII_CASE_CLOSED'=>(bool)$record['closed_at']]);
                }
            }
            if ($script==='viewforum.'.$phpEx) {
                $forum=$this->request->variable('f',0);
                global $config;
                foreach (['complaint','request','inspection'] as $kind) {
                    if ($forum && $forum==($config['uii_forum_'.$kind] ?? 0)) {
                        redirect($this->helper->route('uii_portal_cases',['kind'=>$kind]));
                    }
                }
            }
            if ($script==='index.'.$phpEx && !defined('ADMIN_START')) { $this->dashboard(); }
            return;
        }
        global $phpbb_root_path, $phpEx;
        $script = basename($this->user->page['page_name']);
        $mode = $this->request->variable('mode', '');
        if ($script === 'ucp.' . $phpEx && in_array($mode, ['login', 'logout', 'sendpassword', 'reset_password', 'confirm'], true)) {
            return;
        }
        if (in_array($script, ['faq.' . $phpEx, 'cron.' . $phpEx], true)) {
            // Технические страницы не содержат документы отдела.
            return;
        }
        redirect(append_sid($phpbb_root_path . 'ucp.' . $phpEx, 'mode=login'));
    }
    private function isPublicErrorRequest($container): bool
    {
        try {
            $route=$container->get('router')->match($container->get('symfony_request')->getPathInfo());
            return ($route['_route'] ?? '')==='uii_portal_not_found';
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {return true;}
        catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException $e) {return false;}
    }
    private function isBugRoute(): bool
    {
        // The dedicated controller enforces the exact submit/admin ACL on every action.
        $path=(string)parse_url($this->request->server('REQUEST_URI',''),PHP_URL_PATH);
        if (preg_match('~/uii/bugs/(?:new|sent/[0-9]+)/?$~D',$path)) {return true;}
        if (preg_match('~/uii/admin/updates/?$~D',$path)) {return true;}
        if (preg_match('~/uii/admin/bugs(?:/[0-9]+)?/?$~D',$path)) {return true;}
        return false;
    }
    private function assignNavigation(int $level): void
    {
        global $db,$table_prefix,$config,$auth;
        $administrator=$level===4 && $auth->acl_get('a_');
        $state=\uii\portal\service\navigation::load($db,$table_prefix,!empty($config['uii_navigation_ready']));
        $catalog=\uii\portal\service\navigation::catalog();
        foreach (\uii\portal\service\navigation::visible($state['layout'],$level,$administrator,$auth->acl_get('u_uii_bug_report'),$auth->acl_get('a_uii_bug_reports'),$auth->acl_get('a_uii_updates') && $auth->acl_get('a_extensions')) as $group) {
            $this->template->assign_block_vars('nav_groups',['TITLE'=>htmlspecialchars($group['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')]);
            foreach ($group['items'] as $id) {
                [$label,$icon,$route,$args]=$catalog[$id];
                $url=$id==='home' ? generate_board_url().'/' : $this->helper->route($route,$args);
                if ($id==='staff_admin') { $url.='#manage'; }
                $this->template->assign_block_vars('nav_groups.items',['LABEL'=>$label,'ICON'=>$icon,'URL'=>$url]);
            }
        }
    }
    private function deny(string $message,int $severity=E_USER_WARNING): void
    {
        http_response_code(403);
        trigger_error($message,$severity);
    }
    public function stopNotifications($event)
    {
        $event['add_notifications_override']=true;
        $event['notified_users']=[];
    }
    public function stopNotificationRecipients($event) { $event['notify_users']=[]; }
    public function fixedPostAvatar($event)
    {
        // phpBB already selects u.* for each post: no extra query per author.
        $row = $event['row'];
        $avatar = \uii\portal\service\avatars::url($row['uii_gender'] ?? 'unspecified');
        $post=$event['post_row'];
        $post['U_PM']='';$post['U_EMAIL']='';$post['RANK_TITLE']='';$post['RANK_IMG']='';
        $post['POSTER_AVATAR']='<img class="avatar fixed-avatar" src="'.htmlspecialchars($avatar,ENT_QUOTES,'UTF-8').'" width="64" height="64" alt="Аватар сотрудника">';
        $event['post_row']=$post;
    }
    public function compressAttachment($event)
    {
        if (empty($event['is_image'])) { return; }
        global $phpbb_root_path,$config;
        $data=$event['filedata'];
        if (!empty($data['error'])) { return; }
        $filename=$data['physical_filename'] ?? '';
        if ($filename==='' || basename($filename)!==$filename || !preg_match('/^[a-zA-Z0-9_.-]+$/D',$filename)) { return; }
        $directory=realpath($phpbb_root_path.($config['upload_path'] ?? 'files'));
        if (!$directory) { return; }
        $path=$directory.'/'.$filename;
        if (!is_file($path) || is_link($path)) { return; }
        $result=\uii\portal\service\image_optimizer::optimize($path);
        if ($result['optimized']) { $data['filesize']=$result['size'];$event['filedata']=$data; }
    }
    public function simplifyPage($event)
    {
        global $db,$table_prefix;
        if($this->role()<4){$this->template->assign_vars(['U_ACP'=>'','U_MCP'=>'']);}
        $this->template->assign_vars(['U_PRIVACY'=>'','U_TERMS_USE'=>'','S_COOKIE_NOTICE'=>false,'S_NOTIFICATIONS_DISPLAY'=>false,'S_DISPLAY_PM'=>false,'S_NOTIFY_ALLOWED'=>false,'S_WATCH_TOPIC'=>false,'S_WATCH_FORUM'=>false,'U_WATCH_TOPIC'=>'','U_WATCH_FORUM'=>'','U_PRIVATEMSGS'=>'','U_BOOKMARK_TOPIC'=>'','S_BOOKMARK_TOPIC'=>false,'S_SAVE_ALLOWED'=>false,'S_LOAD_DRAFTS'=>false]);
    }
    private function dashboard()
    {
        global $db,$auth,$table_prefix,$config;
        if ($this->role()<1) { return; }
        $id=(int)$this->user->data['user_id'];
        $manager=$this->role()>=2;
        $where=$manager ? '1=1' : '(author_id = '.$id.' OR assigned_id = '.$id.')';
        $r=$db->sql_query('SELECT COUNT(*) AS total, SUM(CASE WHEN closed_at = 0 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN closed_at > 0 THEN 1 ELSE 0 END) AS archived, SUM(CASE WHEN closed_at = 0 AND answered_at = 0 AND deadline_at > 0 AND deadline_at <= '.time().' THEN 1 ELSE 0 END) AS overdue FROM '.$table_prefix.'uii_cases WHERE '.$where);
        $totals=$db->sql_fetchrow($r);$db->sql_freeresult($r);
        $this->template->assign_vars(['CURRENT_USERNAME'=>$this->user->data['username'],'UII_ROLE'=>\uii\portal\service\roles::label($this->role()), 'UII_ARCHIVED'=>(int)$totals['archived'],'UII_ACTIVE'=>(int)$totals['active'],'UII_OVERDUE'=>(int)$totals['overdue']]);
        $r=$db->sql_query_limit('SELECT c.case_id, c.kind, c.status, c.title, c.updated_at, c.closed_at, c.answered_at, c.deadline_at, u.username AS author_name, u.uii_gender AS author_gender FROM '.$table_prefix.'uii_cases c LEFT JOIN '.$table_prefix.'users u ON u.user_id = c.author_id WHERE '.$where.' ORDER BY c.updated_at DESC, c.case_id DESC',8);
        while ($row=$db->sql_fetchrow($r)) {
            $this->template->assign_block_vars('recent_cases',['AUTHOR_NAME'=>$row['author_name'] ?? 'Сотрудник недоступен','AUTHOR_AVATAR'=>\uii\portal\service\avatars::url($row['author_gender'] ?? 'unspecified'),'TITLE'=>$row['title'],'NUMBER'=>(int)$row['case_id'],'URL'=>$this->helper->route('uii_portal_case',['id'=>(int)$row['case_id']]),'DATE'=>$this->user->format_date($row['updated_at']),'SHOW_STATUS'=>\uii\portal\service\case_status::describe($row,time())['visible'],'STATUS'=>\uii\portal\service\case_status::describe($row,time())['label'],'TONE'=>\uii\portal\service\case_status::describe($row,time())['tone'],'CLOSED'=>(bool)$row['closed_at'],'OVERDUE'=>!$row['closed_at'] && !$row['answered_at'] && $row['deadline_at']>0 && $row['deadline_at']<=time()]);
        }
        $db->sql_freeresult($r);

    }

}
