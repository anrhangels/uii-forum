<?php
namespace uii\portal\service;
final class web_updater
{
    private $root;private $directory;private $github;
    public static function directory(string $root): string
    {
        $root=rtrim(realpath($root) ?: $root,'/');
        if (defined('UII_UPDATES_PATH')) {return rtrim(UII_UPDATES_PATH,'/');}
        if (defined('UII_EVIDENCE_PATH') && strpos(UII_EVIDENCE_PATH,rtrim($root,'/').'/')!==0) {return dirname(UII_EVIDENCE_PATH).'/updates';}
        return dirname(rtrim($root,'/')).'/uii-private/updates';
    }
    public static function maintenance(string $root): bool {return @is_file(self::directory($root).'/maintenance.json');}
    public function __construct(string $root,?github_releases $github=null)
    {
        $this->root=rtrim(realpath($root) ?: $root,'/');$this->directory=self::directory($this->root);$this->github=$github ?: new github_releases();
        if (!is_dir($this->directory) && !@mkdir($this->directory,0700,true)) {throw new \RuntimeException('Создайте приватную папку обновлений вне корня сайта: '.$this->directory.'. PHP должен иметь в неё запись.');}
        $real=realpath($this->directory);if (!$real || $real===$this->root || strpos($real,$this->root.'/')===0 || is_link($this->directory)) {throw new \RuntimeException('Обновления и токен должны храниться вне публичного корня сайта.');}$this->directory=$real;
        if (!is_writable($real)) {throw new \RuntimeException('Приватная папка обновлений недоступна PHP для записи.');}
        @chmod($real,0700);
    }
    public function current(): string {$data=json_decode(file_get_contents($this->root.'/ext/uii/portal/composer.json'),true);return (string)$data['version'];}
    private function read(string $name): array {$file=$this->directory.'/'.$name;return is_file($file) ? (json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR) ?: []) : [];}
    private function write(string $name,array $data): void
    {
        $path=$this->directory.'/'.$name;$temp=$path.'.'.bin2hex(random_bytes(6));$raw=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (file_put_contents($temp,$raw,LOCK_EX)!==strlen($raw)) {throw new \RuntimeException('Не удалось записать состояние обновления.');}@chmod($temp,0600);
        if (!rename($temp,$path)) {@unlink($temp);throw new \RuntimeException('Не удалось сохранить состояние обновления.');}
    }
    public function state(): array {return $this->read('state.json');}
    public function settings(): array {$data=$this->read('credentials.json');return ['repository'=>$data['repository'] ?? 'anrhangels/uii-forum','has_token'=>!empty($data['token'])];}
    private function credentials(): array {return $this->read('credentials.json')+['repository'=>'anrhangels/uii-forum','token'=>''];}
    public function exclusive(callable $work)
    {
        $lock=fopen($this->directory.'/update.lock','c');if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {throw new \RuntimeException('Другая операция обновления уже выполняется.');}
        try {return $work();} finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function configure(string $repository,string $token,bool $clear): void
    {
        if (self::maintenance($this->root)) {throw new \RuntimeException('Сначала завершите или откатите текущую установку.');}
        $data=$this->credentials();$repository=github_releases::repository($repository);
        if ($repository!==$data['repository']) {$data['token']='';}$data['repository']=$repository;
        if ($clear) {$data['token']='';}elseif ($token!=='') {if (strlen($token)>255 || !preg_match('/^[A-Za-z0-9_]+$/D',$token)) {throw new \InvalidArgumentException('Недопустимый формат токена GitHub.');}$data['token']=$token;}
        $this->write('credentials.json',$data);$this->write('release.json',[]);
    }
    public function check(): array
    {
        $credentials=$this->credentials();$release=$this->github->latest($credentials['repository'],$credentials['token']);$release['checked_at']=time();$release['repository']=$credentials['repository'];$this->write('release.json',$release);return $release;
    }
    public function release(): array {return $this->read('release.json');}
    public function prepare(): array
    {
        if (self::maintenance($this->root)) {throw new \RuntimeException('Завершите текущую установку.');}
        if (!class_exists('ZipArchive')) {throw new \RuntimeException('Включите PHP ZipArchive в панели хостинга.');}
        $credentials=$this->credentials();$release=$this->check();if (version_compare($release['version'],$this->current(),'<=')) {throw new \RuntimeException('Новой версии нет.');}
        $id=bin2hex(random_bytes(16));$job=$this->directory.'/'.$id;if (!mkdir($job,0700)) {throw new \RuntimeException('Не удалось подготовить загрузку.');}
        $zip=$job.'/package.zip';$this->github->download($credentials['repository'],$credentials['token'],$release,$zip);
        $manifest=update_package::inspect($zip,$credentials['repository'],$this->current());if ($manifest['version']!==$release['version']) {throw new \RuntimeException('Версия релиза не совпадает с пакетом.');}
        $free=function_exists('disk_free_space') ? @disk_free_space($this->directory) : false;if ($free!==false && $free<$manifest['total_bytes']*3+67108864) {throw new \RuntimeException('Недостаточно свободного места для обновления и резервных копий.');}
        update_package::inspect($zip,$credentials['repository'],$this->current(),$job.'/stage');
        $state=['id'=>$id,'status'=>'prepared','from'=>$this->current(),'version'=>$manifest['version'],'repository'=>$credentials['repository'],'sha256'=>$release['sha256'],'manifest'=>$manifest,'created_at'=>time(),'migration_started'=>false];$this->write('state.json',$state);return $state;
    }
    private function job(string $id): array
    {
        $state=$this->state();if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !hash_equals($state['id'] ?? '',$id)) {throw new \RuntimeException('Страница обновления устарела. Обновите её.');}return $state;
    }
    private function path(string $relative): string
    {
        if (!update_package::allowed($relative)) {throw new \RuntimeException('Попытка изменить защищённый файл.');}
        $parts=explode('/',$relative);$path=$this->root;
        foreach ($parts as $part) {$path.='/'.$part;if (is_link($path)) {throw new \RuntimeException('Установка через символические ссылки запрещена.');}}
        return $path;
    }
    public function install(string $id,$db,string $prefix): void
    {
        $state=$this->job($id);if ($state['status']!=='prepared' || self::maintenance($this->root)) {throw new \RuntimeException('Пакет уже устанавливается или был установлен.');}
        $job=$this->directory.'/'.$id;
        if (!hash_equals($state['sha256'],hash_file('sha256',$job.'/package.zip'))) {throw new \RuntimeException('Пакет изменился после проверки.');}
        $files=$state['manifest']['files'];$size=0;
        foreach ($files as $relative=>$definition) {
            $target=$this->path($relative);$staged=$job.'/stage/'.$relative;
            if (!is_file($staged) || !hash_equals($definition['sha256'],hash_file('sha256',$staged))) {throw new \RuntimeException('Подготовленный файл изменился. Загрузите пакет заново.');}
            if ((is_file($target) && (!is_writable($target) || !is_writable(dirname($target)))) || (!is_file($target) && !$this->writableParent(dirname($target)))) {throw new \RuntimeException('PHP не может заменить '.$relative.'. Проверьте владельца файлов.');}
            if (is_file($target)) {$size+=filesize($target);}
        }
        $free=function_exists('disk_free_space') ? @disk_free_space($this->directory) : false;if ($free!==false && $free<$size+$state['manifest']['total_bytes']+67108864) {throw new \RuntimeException('Недостаточно места для резервной копии.');}
        $state['status']='backing_up';$state['originals']=[];$this->write('state.json',$state);$this->write('maintenance.json',['job'=>$id,'since'=>time()]);
        try {
            update_backup::database($db,$prefix,$job.'/database.sql');$state['database_complete']=true;
            foreach ($files as $relative=>$definition) {
                $target=$this->path($relative);$exists=is_file($target);$state['originals'][$relative]=$exists;
                if ($exists) {$backup=$job.'/backup/'.$relative;if (!is_dir(dirname($backup)) && !mkdir(dirname($backup),0700,true)) {throw new \RuntimeException('Не удалось создать резервную копию файлов.');}if (!copy($target,$backup)) {throw new \RuntimeException('Не удалось сохранить исходный файл.');}}
            }
            $state['status']='installing';$this->write('state.json',$state);
            // Services/routes/metadata are replaced last, after their PHP classes and templates exist.
            $paths=array_keys($files);usort($paths,static function($a,$b) {$weight=static function($p) {return substr($p,-5)==='.json' ? 3 : (substr($p,-4)==='.yml' ? 2 : 1);};return $weight($a)<=>$weight($b) ?: strcmp($a,$b);});
            foreach ($paths as $relative) {$this->replace($job.'/stage/'.$relative,$this->path($relative));}
            $state['status']='files_installed';$this->write('state.json',$state);
        } catch (\Throwable $e) {
            // No database migration has started. Only the files in this job may be restored.
            if (($state['status'] ?? '')==='installing') {$this->restore($state);}
            $state['status']='failed_before_migration';$this->write('state.json',$state);@unlink($this->directory.'/maintenance.json');throw $e;
        }
    }
    private function writableParent(string $path): bool {while (!is_dir($path)) {$parent=dirname($path);if ($parent===$path) {return false;}$path=$parent;}return !is_link($path) && is_writable($path);}
    private function replace(string $source,string $target): void
    {
        if (!is_dir(dirname($target)) && !mkdir(dirname($target),0755,true)) {throw new \RuntimeException('Не удалось создать каталог кода.');}
        $temporary=$target.'.uii-'.bin2hex(random_bytes(5));if (!copy($source,$temporary)) {throw new \RuntimeException('Ошибка записи обновления.');}
        @chmod($temporary,is_file($target) ? fileperms($target)&0777 : 0644);
        if (!rename($temporary,$target)) {@unlink($temporary);throw new \RuntimeException('Не удалось заменить файл.');}
        if (substr($target,-4)==='.php' && function_exists('opcache_invalidate')) {@opcache_invalidate($target,true);}
    }
    private function restore(array $state): void
    {
        foreach (($state['originals'] ?? []) as $relative=>$exists) {$target=$this->path($relative);if ($exists) {$this->replace($this->directory.'/'.$state['id'].'/backup/'.$relative,$target);}elseif (is_file($target) && !unlink($target)) {throw new \RuntimeException('Не удалось откатить новый файл. Режим обслуживания сохранён.');}}
    }
    public function cancel(string $id): void
    {
        $state=$this->job($id);if (!in_array($state['status'],['prepared','backing_up','failed_before_migration'],true)) {throw new \RuntimeException('На этом этапе отмена недоступна. Восстановите файлы или продолжите миграции.');}
        $state['status']='cancelled';$this->write('state.json',$state);if (is_file($this->directory.'/maintenance.json') && !unlink($this->directory.'/maintenance.json')) {throw new \RuntimeException('Не удалось снять режим обслуживания. Проверьте права приватной папки.');}
    }
    public function rollback(string $id): void
    {
        $state=$this->job($id);if (!empty($state['migration_started'])) {throw new \RuntimeException('Миграция уже началась. Автоматический откат БД запрещён, чтобы не потерять новые данные. Используйте резервную копию для ручного восстановления.');}
        if (!in_array($state['status'],['installing','files_installed'],true)) {throw new \RuntimeException('Нет установленного кода для отката.');}
        $this->restore($state);$state['status']='rolled_back';$this->write('state.json',$state);@unlink($this->directory.'/maintenance.json');
    }
    public function migrate(string $id,$migrator,$extensions,$config): bool
    {
        $state=$this->job($id);if (!in_array($state['status'],['files_installed','migrating'],true)) {throw new \RuntimeException('Сначала установите проверенные файлы.');}
        if ($this->current()!==$state['version']) {throw new \RuntimeException('Версия кода не совпадает с установленным пакетом.');}
        $finder=$extensions->get_finder();$files=$finder->extension_directory('/migrations')->find_from_extension('uii/portal',$this->root.'/ext/uii/portal/',false);
        $classes=$finder->get_classes_from_files($files);if (!$classes) {throw new \RuntimeException('Не найдены миграции расширения. Установка остановлена.');}$migrator->set_migrations($classes);
        foreach ($migrator->get_installable_migrations() as $name) {
            if ($migrator->unfulfillable($name)!==false) {throw new \RuntimeException('Неполная цепочка зависимостей миграции. Установка остановлена до изменения БД.');}
            $migration=$migrator->get_migration($name);$schema=$migration->update_schema();
            if (array_diff(array_keys($schema),['add_tables','add_columns','add_index'])) {throw new \RuntimeException('Миграция требует ручной проверки: разрешены только добавления в схему. Можно откатить код, пока миграция не началась.');}
            foreach ($migration->update_data() as $instruction) {
                $operation=$instruction[0] ?? '';
                if (!in_array($operation,['config.add','config.update','permission.add','permission.permission_set','module.add'],true) || (strpos($operation,'config.')===0 && strpos($instruction[1][0] ?? '','uii_')!==0)) {throw new \RuntimeException('Миграция содержит произвольный обработчик или изменение системных данных. Нужна ручная проверка; автоматический запуск остановлен.');}
            }
        }
        $state['status']='migrating';$state['migration_started']=true;$this->write('state.json',$state);
        $deadline=microtime(true)+8;$steps=0;
        while (!$migrator->finished() && microtime(true)<$deadline && $steps++<20) {$migrator->update();}
        if (!$migrator->finished()) {return false;}
        foreach ($classes as $name) {$migrationState=$migrator->migration_state($name);if (!$migrationState || !$migrationState['migration_schema_done'] || !$migrationState['migration_data_done']) {throw new \RuntimeException('Не все миграции завершены. Режим обслуживания сохранён.');}}
        $config->set('uii_update_installed',$state['version']);$config->increment('assets_version',1);$state['status']='complete';$state['finished_at']=time();$this->write('state.json',$state);@unlink($this->directory.'/maintenance.json');return true;
    }
    public function backup(string $id): string {$state=$this->job($id);$file=$this->directory.'/'.$id.'/database.sql';if (empty($state['database_complete']) || !is_file($file) || !in_array($state['status'],['files_installed','migrating','complete','rolled_back','failed_before_migration','installing'],true)) {throw new \RuntimeException('Резервная копия пока недоступна.');}return $file;}
}
