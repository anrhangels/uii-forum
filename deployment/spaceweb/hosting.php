<?php
namespace uii_deploy;
final class hosting
{
    public static function domain(string $value): string
    {
        $value=strtolower(trim($value));
        if (str_contains($value,'://')) { throw new \InvalidArgumentException('Укажите только домен, без https:// и пути.'); }
        if (!filter_var($value,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || !str_contains($value,'.') || filter_var($value,FILTER_VALIDATE_IP)) { throw new \InvalidArgumentException('Укажите настоящий домен сайта, например forum.example.ru.'); }
        return $value;
    }
    public static function prefix(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]{1,50}$/D',$value)) { throw new \InvalidArgumentException('Некорректный префикс таблиц.'); }
        return $value;
    }
    public static function photos(array $rows, string $directory): int
    {
        $count=0;
        foreach ($rows as $payload) {
            $data=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \RuntimeException('Некорректные материалы дела.'); }
            foreach (($data['photos'] ?? []) as $photo) {
                $token=$photo['token'] ?? '';
                if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D',$token)) { throw new \RuntimeException('Некорректная ссылка на фото в базе.'); }
                $path=$directory.'/'.$token.'.bin';
                if (!is_file($path) || !is_readable($path) || (isset($photo['size']) && filesize($path)!==(int)$photo['size'])) { throw new \RuntimeException('Фото из базы отсутствует или повреждено. Перенесите полный private-files.zip.'); }
                $count++;
            }
        }
        return $count;
    }
    public static function settings(string $domain, string $cookie): array
    {
        return ['server_name'=>self::domain($domain),'server_protocol'=>'https://','server_port'=>'443','script_path'=>'','force_server_vars'=>'1','cookie_domain'=>'','cookie_path'=>'/','cookie_secure'=>'1','cookie_name'=>$cookie,'board_disable'=>'1','board_disable_msg'=>'Портал проходит проверку после переноса.','email_enable'=>'0','allow_cdn'=>'0','require_activation'=>'3'];
    }
}
