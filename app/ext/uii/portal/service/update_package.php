<?php
namespace uii\portal\service;
final class update_package
{
    public static function allowed(string $path): bool
    {
        if (!preg_match('~^(?:ext/uii/portal/|styles/uii_modern/|language/ru/)[A-Za-z0-9_./-]+$~D',$path)) {return false;}
        foreach (explode('/',$path) as $part) {if ($part==='' || $part==='.' || $part==='..' || $part==='.git') {return false;}}
        return !preg_match('~(?:^|/)(?:config\.php|\.env)(?:$|/)|\.(?:sql|sqlite3?|db|zip|gz|tar|phar)$~iD',$path) && strpos($path,'/tests/')===false;
    }
    public static function inspect(string $file,string $repository,string $current,?string $destination=null): array
    {
        if (!class_exists('ZipArchive')) {throw new \RuntimeException('Для установки нужен PHP-модуль ZipArchive.');}
        $zip=new \ZipArchive();if ($zip->open($file)!==true) {throw new \RuntimeException('Повреждённый ZIP обновления.');}
        try {
            if ($zip->numFiles<2 || $zip->numFiles>4096) {throw new \RuntimeException('Недопустимое количество файлов пакета.');}
            $stat=$zip->statName('manifest.json');if (!$stat || $stat['size']>1048576) {throw new \RuntimeException('Отсутствует корректный manifest.json.');}
            $raw=$zip->getFromName('manifest.json',1048577);if (strlen($raw)>1048576) {throw new \RuntimeException('Манифест слишком большой.');}$manifest=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? 0)!==1 || ($manifest['product'] ?? '')!=='uii/portal' || ($manifest['repository'] ?? '')!==$repository || !preg_match('/^\d+\.\d+\.\d+$/D',$manifest['version'] ?? '') || version_compare($manifest['version'],$current,'<=') || version_compare($current,$manifest['min_installed'] ?? '999.0.0','<') || version_compare(PHP_VERSION,$manifest['min_php'] ?? '999.0','<') || version_compare(PHPBB_VERSION,'3.3.19','<') || version_compare(PHPBB_VERSION,'3.4.0','>=')) {throw new \RuntimeException('Пакет не подходит для текущей версии УИИ, PHP или phpBB. Понижение версии запрещено.');}
            $files=$manifest['files'] ?? [];if (!is_array($files) || !$files || count($files)>4095) {throw new \RuntimeException('Некорректный список файлов.');}
            $seen=[];$total=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $entry=$zip->statIndex($i);$name=$entry['name'];$fold=strtolower($name);
                if (isset($seen[$fold])) {throw new \RuntimeException('Повторяющиеся имена в ZIP.');}$seen[$fold]=true;
                $zip->getExternalAttributesIndex($i,$os,$attributes);$kind=($attributes>>16)&0170000;
                if ($kind!==0 && $kind!==0100000) {throw new \RuntimeException('Ссылки, каталоги и специальные файлы в пакете запрещены.');}
                if ($name==='manifest.json') {continue;}
                if (strpos($name,'payload/')!==0) {throw new \RuntimeException('Посторонний файл в пакете.');}$relative=substr($name,8);$definition=$files[$relative] ?? null;
                if (!self::allowed($relative) || !is_array($definition) || ($definition['size'] ?? -1)!==$entry['size'] || $entry['size']>8388608 || !preg_match('/^[a-f0-9]{64}$/D',$definition['sha256'] ?? '')) {throw new \RuntimeException('Недопустимый файл обновления.');}
                $total+=$entry['size'];if ($total>67108864) {throw new \RuntimeException('Распакованное обновление превышает 64 МиБ.');}
                $stream=$zip->getStream($name);if (!$stream) {throw new \RuntimeException('Не удалось прочитать ZIP.');}
                $hash=hash_init('sha256');$length=0;$out=null;
                try {
                    if ($destination) {$target=$destination.'/'.$relative;if (!is_dir(dirname($target)) && !mkdir(dirname($target),0700,true)) {throw new \RuntimeException('Не удалось подготовить каталог обновления.');}$out=fopen($target,'xb');if (!$out) {throw new \RuntimeException('Не удалось распаковать файл.');}}
                    while (!feof($stream)) {$chunk=fread($stream,65536);if ($chunk===false) {throw new \RuntimeException('Ошибка чтения ZIP.');}$length+=strlen($chunk);if ($length>$entry['size']) {throw new \RuntimeException('Размер ZIP не совпадает с манифестом.');}hash_update($hash,$chunk);if ($out && fwrite($out,$chunk)!==strlen($chunk)) {throw new \RuntimeException('Не хватает места на диске.');}}
                } finally {fclose($stream);if (is_resource($out)) {fclose($out);}}
                if ($length!==$entry['size'] || !hash_equals($definition['sha256'],hash_final($hash))) {throw new \RuntimeException('Повреждён файл обновления.');}
            }
            if (count($seen)!==count($files)+1 || !isset($files['ext/uii/portal/composer.json'])) {throw new \RuntimeException('Состав пакета не соответствует манифесту.');}
            if ($destination) {$meta=json_decode(file_get_contents($destination.'/ext/uii/portal/composer.json'),true);if (($meta['version'] ?? '')!==$manifest['version']) {throw new \RuntimeException('Версии расширения и пакета различаются.');}}
            $manifest['total_bytes']=$total;return $manifest;
        } finally {$zip->close();}
    }
}
