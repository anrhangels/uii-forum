<?php
namespace uii\portal\controller;
use uii\portal\service\web_updater as Updater;
class updates
{
    private $helper;private $template;private $user;private $auth;private $request;private $db;private $prefix;private $config;private $cache;private $container;private $root;
    public function __construct($helper,$template,$user,$auth,$request,$db,$prefix,$config,$cache,$container,$root)
    {$this->helper=$helper;$this->template=$template;$this->user=$user;$this->auth=$auth;$this->request=$request;$this->db=$db;$this->prefix=$prefix;$this->config=$config;$this->cache=$cache;$this->container=$container;$this->root=$root;}
    public function index()
    {
        if (empty($this->user->data['is_registered']) || !empty($this->user->data['uii_blocked']) || !$this->auth->acl_get('a_uii_updates') || !$this->auth->acl_get('a_extensions')) {throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Нужны административные права установки расширений и обновлений УИИ.');}
        $url=$this->helper->route('uii_portal_updates');
        if (empty($this->user->data['session_admin'])) {$this->template->assign_var('U_UII_REAUTH',$url);login_box($url,'Подтвердите пароль администратора для управления обновлениями.','',true,false);}
        $error='';$message='';$state=[];$release=[];$settings=['repository'=>'anrhangels/uii-forum','has_token'=>false];$updater=null;
        add_form_key('uii_updates');
        try {
            $updater=new Updater($this->root);
            $backup=$this->request->variable('backup','');
            if ($backup!=='') {
                $file=$updater->backup($backup);$response=new \Symfony\Component\HttpFoundation\BinaryFileResponse($file);$response->setContentDisposition('attachment','uii-database-before-'.$backup.'.sql');$response->headers->set('Cache-Control','private, no-store');$response->headers->set('X-Content-Type-Options','nosniff');return $response;
            }
            if ($this->request->server('REQUEST_METHOD','GET')==='POST') {
                if (!check_form_key('uii_updates')) {throw new \InvalidArgumentException('Срок действия формы истёк. Обновите страницу.');}
                $action=$this->request->variable('action','');$id=$this->request->variable('job','');
                $updater->exclusive(function() use ($updater,$action,$id,&$message) {
                    if ($action==='settings') {if ($this->request->variable('github_token','')!=='' && !$this->container->get('symfony_request')->isSecure()) {throw new \InvalidArgumentException('Сохранять токен можно только по HTTPS. Для публичного репозитория токен не требуется.');}$updater->configure($this->request->variable('repository',''),$this->request->variable('github_token',''),$this->request->variable('clear_token',0)===1);$message='Настройки сохранены. Токен не отображается и не передаётся другим пользователям.';}
                    elseif ($action==='check') {$updater->check();$message='Проверка GitHub завершена.';}
                    elseif ($action==='prepare') {$updater->prepare();$message='Пакет загружен, SHA-256 и состав проверены. Код и данные сайта ещё не менялись.';}
                    elseif ($action==='install') {
                        if ($this->request->variable('confirm_backup',0)!==1) {throw new \InvalidArgumentException('Подтвердите резервное копирование и установку.');}
                        $updater->install($id,$this->db,$this->prefix);$this->cache->purge();redirect($this->helper->route('uii_portal_updates',['step'=>'migrations']));
                    }
                    elseif ($action==='migrate') {$done=$updater->migrate($id,$this->container->get('migrator'),$this->container->get('ext.manager'),$this->config);$this->cache->purge();$message=$done ? 'Обновление завершено. Пользователи, дела и вложения сохранены.' : 'Шаг миграции выполнен. Нажмите «Продолжить миграции» ещё раз.';}
                    elseif ($action==='cancel') {$updater->cancel($id);$message='Операция отменена. Пользовательские данные не менялись.';}
                    elseif ($action==='rollback') {$updater->rollback($id);$this->cache->purge();$message='Код восстановлен. Пользовательские данные не заменялись.';}
                    else {throw new \InvalidArgumentException('Неизвестное действие.');}
                });
            }
        } catch (\Throwable $e) {
            if (!$e instanceof \InvalidArgumentException) {error_log('UII updater operation failed: '.get_class($e));}
            // GitHub/client exceptions may contain credentials; only our controlled messages are displayed.
            $error=$e instanceof \RuntimeException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Не удалось выполнить обновление. Сайт оставлен в текущем безопасном состоянии; проверьте журнал сервера.';
            if ($e instanceof \phpbb\db\migration\exception) {$error='Миграция не завершилась. Режим обслуживания сохранён. Исправьте причину и продолжите; не восстанавливайте старую БД поверх новых данных автоматически.';}
        }
        if ($updater) {$state=$updater->state();$release=$updater->release();$settings=$updater->settings();}
        $current=$updater ? $updater->current() : json_decode(file_get_contents($this->root.'ext/uii/portal/composer.json'),true)['version'];
        $this->template->assign_vars(['UPDATE_HTTPS'=>$this->container->get('symfony_request')->isSecure(),'UPDATE_ERROR'=>$error,'UPDATE_MESSAGE'=>$message,'UPDATE_READY'=>(bool)$updater,'UPDATE_CURRENT'=>$current,'UPDATE_RELEASE'=>$release,'UPDATE_SETTINGS'=>$settings,'UPDATE_STATE'=>$state,'UPDATE_AVAILABLE'=>isset($release['version']) && version_compare($release['version'],$current,'>'),'UPDATE_MAINTENANCE'=>Updater::maintenance($this->root),'UPDATE_CAN_ROLLBACK'=>empty($state['migration_started']) && in_array($state['status'] ?? '',['installing','files_installed'],true),'UPDATE_BACKUP'=>!empty($state['id']) && in_array($state['status'],['files_installed','migrating','complete','rolled_back','installing'],true) ? $this->helper->route('uii_portal_updates',['backup'=>$state['id']]) : '', 'FORM_ACTION'=>$url]);
        $response=$this->helper->render('@uii_portal/updates.html','Обновления УИИ');$response->headers->set('Cache-Control','private, no-store');return $response;
    }
}
