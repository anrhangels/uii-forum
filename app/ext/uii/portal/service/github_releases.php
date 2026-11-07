<?php
namespace uii\portal\service;

/** HTTPS only; credentials are never forwarded to redirected asset hosts. */
class github_releases
{
    const ASSET='uii-web-update.zip';
    private $client;
    public function __construct($client=null) {$this->client=$client;}
    public static function repository(string $value): string
    {
        $value=preg_replace('~^https://github\.com/~i','',trim($value));$value=preg_replace('~\.git$~','',rtrim($value,'/'));
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_.-]{1,100}$~D',$value) || preg_match('~/(?:\.|\.\.)$~D',$value)) {throw new \InvalidArgumentException('Укажите репозиторий GitHub в формате владелец/имя.');}
        return $value;
    }
    protected function request(string $url,string $token,string $accept,int $maximum,?string $destination=null)
    {
        $client=$this->client ?: new \GuzzleHttp\Client();$target=$destination ? @fopen($destination,'xb') : null;$content='';$written=0;
        if ($destination && !$target) {throw new \RuntimeException('Не удалось создать файл загрузки.');}
        try {
            for ($redirect=0;$redirect<4;$redirect++) {
                $parts=parse_url($url);$host=strtolower($parts['host'] ?? '');
                if (($parts['scheme'] ?? '')!=='https' || !in_array($host,['api.github.com','github.com','release-assets.githubusercontent.com','objects.githubusercontent.com'],true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443)) {throw new \RuntimeException('GitHub вернул недопустимый адрес загрузки.');}
                $headers=['Accept'=>$accept,'User-Agent'=>'UII-Updater','X-GitHub-Api-Version'=>'2026-03-10'];
                if ($host==='api.github.com' && $token!=='') {$headers['Authorization']='Bearer '.$token;}
                try {$response=$client->request('GET',$url,['headers'=>$headers,'verify'=>true,'timeout'=>30,'connect_timeout'=>7,'http_errors'=>false,'allow_redirects'=>false,'stream'=>true]);}
                catch (\Throwable $e) {throw new \RuntimeException('Не удалось подключиться к GitHub по HTTPS. Проверьте исходящие соединения и сертификаты сервера.');}
                $status=$response->getStatusCode();
                if (in_array($status,[301,302,303,307,308],true)) {$url=$response->getHeaderLine('Location');$response->getBody()->close();continue;}
                if ($status===404) {throw new \RuntimeException('Релиз не найден либо нет доступа к репозиторию. Опубликуйте Release; для приватного репозитория проверьте токен Contents: read.');}
                if ($status!==200) {throw new \RuntimeException('GitHub отклонил запрос (HTTP '.$status.'). Проверьте токен и лимит API.');}
                if ((int)$response->getHeaderLine('Content-Length')>$maximum) {throw new \RuntimeException('Файл GitHub превышает допустимый размер.');}
                $body=$response->getBody();
                while (!$body->eof()) {
                    try {$chunk=$body->read(65536);} catch (\Throwable $e) {throw new \RuntimeException('Загрузка с GitHub прервалась. Повторите попытку.');}$written+=strlen($chunk);if ($written>$maximum) {throw new \RuntimeException('Превышен лимит загрузки.');}
                    if ($target) {if (fwrite($target,$chunk)!==strlen($chunk)) {throw new \RuntimeException('На диске не хватает места для обновления.');}}
                    else {$content.=$chunk;}
                }
                $body->close();return $destination ? $written : $content;
            }
            throw new \RuntimeException('Слишком много перенаправлений при загрузке GitHub.');
        } finally {if (is_resource($target)) {fclose($target);}}
    }
    public function latest(string $repository,string $token): array
    {
        $repository=self::repository($repository);$data=json_decode($this->request('https://api.github.com/repos/'.$repository.'/releases/latest',$token,'application/vnd.github+json',1048576),true,64,JSON_THROW_ON_ERROR);
        $version=ltrim((string)($data['tag_name'] ?? ''),'v');
        if (!empty($data['draft']) || !empty($data['prerelease']) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$version)) {throw new \RuntimeException('Нужен опубликованный стабильный релиз с тегом v1.3.0 или новее.');}
        foreach (($data['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '')!==self::ASSET || ($asset['state'] ?? '')!=='uploaded') {continue;}
            $digest=(string)($asset['digest'] ?? '');
            if (!preg_match('/^sha256:([a-f0-9]{64})$/D',$digest,$hash)) {throw new \RuntimeException('GitHub не предоставил SHA-256 файла. Загрузите пакет как новый Release asset.');}
            if ((int)$asset['size']<1 || (int)$asset['size']>16777216 || (int)$asset['id']<1) {throw new \RuntimeException('Недопустимый размер или идентификатор пакета.');}
            return ['version'=>$version,'release_id'=>(int)$data['id'],'asset_id'=>(int)$asset['id'],'sha256'=>$hash[1],'size'=>(int)$asset['size'],'notes'=>mb_substr((string)($data['body'] ?? ''),0,10000),'url'=>'https://github.com/'.$repository.'/releases/tag/v'.$version];
        }
        throw new \RuntimeException('В Release отсутствует файл '.self::ASSET.'. Архив Source code не является пакетом обновления УИИ.');
    }
    public function download(string $repository,string $token,array $release,string $destination): void
    {
        $this->request('https://api.github.com/repos/'.self::repository($repository).'/releases/assets/'.(int)$release['asset_id'],$token,'application/octet-stream',16777216,$destination);
        if (filesize($destination)!==(int)$release['size'] || !hash_equals($release['sha256'],hash_file('sha256',$destination))) {throw new \RuntimeException('SHA-256 или размер скачанного пакета не совпадает с данными GitHub.');}
    }
}
