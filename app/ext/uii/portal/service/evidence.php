<?php
namespace uii\portal\service;

/** Private uploaded images; the controller checks case access before every download. */
final class evidence
{
    const MAX_BYTES = 5242880;
    const QUOTA_BYTES = 524288000;
    private $directory;
    private $lock;

    public function __construct(string $root)
    {
        $directory=defined('UII_EVIDENCE_PATH') ? UII_EVIDENCE_PATH : rtrim($root, '/').'/files/uii_evidence';
        if (!is_string($directory) || $directory==='') { throw new \InvalidArgumentException('Не задан каталог фотографий.'); }
        $this->directory=rtrim($directory,'/');
    }
    public function usage(): int
    {
        $used=0;
        foreach (glob($this->directory.'/*.bin') ?: [] as $file) { $used+=filesize($file); }
        return $used;
    }
    public static function slots(string $kind): array { return case_schema::slots($kind); }
    public static function uploadSlots(string $kind): array { return case_schema::uploadSlots($kind); }
    public static function limit(string $kind): int { return count(self::uploadSlots($kind)); }
    public static function checkCapacity(string $kind, array $photos, string $slot): void
    {
        if (!isset(self::uploadSlots($kind)[$slot])) { throw new \InvalidArgumentException('Это изображение не предусмотрено для выбранного типа дела.'); }
        if (count($photos)>=self::limit($kind)) { throw new \InvalidArgumentException('Все изображения этого типа дела уже загружены.'); }
        foreach ($photos as $photo) {
            if (($photo['slot'] ?? '')===$slot) { throw new \InvalidArgumentException('Фото этого этапа уже загружено.'); }
        }
    }
    public static function assertPostSize($request): void
    {
        $raw=trim((string)ini_get('post_max_size'));$limit=(int)$raw;$unit=strtolower(substr($raw,-1));
        if ($unit==='g') { $limit*=1073741824; } elseif ($unit==='m') { $limit*=1048576; } elseif ($unit==='k') { $limit*=1024; }
        if ($limit>0 && (int)$request->server('CONTENT_LENGTH','0')>$limit) { throw new \InvalidArgumentException('Общий размер формы превышает лимит сервера. Допустимо до трёх изображений по 5 МиБ; проверьте post_max_size (не менее 20M).'); }
    }
    public static function inspect(string $path): array
    {
        $size = is_file($path) ? filesize($path) : 0;
        if (!$size || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Размер изображения должен быть от 1 байта до 5 МиБ.');
        }
        $info = @getimagesize($path);
        $types = [IMAGETYPE_JPEG=>'image/jpeg', IMAGETYPE_PNG=>'image/png', IMAGETYPE_WEBP=>'image/webp'];
        if (!$info || !isset($types[$info[2]]) || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 24000000) {
            throw new \InvalidArgumentException('Допустимы изображения JPG, PNG и WebP до 24 мегапикселей.');
        }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime!==$types[$info[2]]) { throw new \InvalidArgumentException('Содержимое файла не соответствует формату JPG, PNG или WebP.'); }
        return ['mime'=>$types[$info[2]], 'size'=>$size];
    }
    public function store(array $upload): array
    {
        $errors=[UPLOAD_ERR_INI_SIZE=>'Файл превышает лимит PHP. Допустимо до 5 МиБ; администратору нужно проверить upload_max_filesize.',UPLOAD_ERR_FORM_SIZE=>'Изображение превышает 5 МиБ.',UPLOAD_ERR_PARTIAL=>'Изображение получено не полностью. Выберите файл повторно.',UPLOAD_ERR_NO_FILE=>'Выберите изображение.',UPLOAD_ERR_NO_TMP_DIR=>'Сервер не настроил временный каталог загрузки.',UPLOAD_ERR_CANT_WRITE=>'Сервер не смог записать временный файл.',UPLOAD_ERR_EXTENSION=>'Загрузка остановлена расширением PHP.'];
        if (isset($upload['error']) && is_scalar($upload['error']) && isset($errors[(int)$upload['error']])) { throw new \InvalidArgumentException($errors[(int)$upload['error']]); }
        if (!isset($upload['error'], $upload['tmp_name']) || !in_array($upload['error'], [UPLOAD_ERR_OK, '0'], true) || !is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            throw new \InvalidArgumentException('Не удалось получить фото. Выберите один файл JPG, PNG или WebP до 5 МиБ.');
        }
        $name=$upload['name'] ?? '';
        if (!is_string($name) || basename($name)!==$name || strpos($name,'\\')!==false) { throw new \InvalidArgumentException('Недопустимое имя изображения.'); }
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $allowed=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        if (!isset($allowed[$extension])) { throw new \InvalidArgumentException('Допустимы только файлы JPG, JPEG, PNG и WebP.'); }
        $info = self::inspect($upload['tmp_name']);
        if ($info['mime']!==$allowed[$extension]) { throw new \InvalidArgumentException('Расширение изображения не соответствует его содержимому.'); }
        $compression=image_optimizer::optimize($upload['tmp_name']);
        $info['size']=$compression['size'];
        $info['original_size']=$compression['original_size'];
        $info['optimized']=$compression['optimized'];
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \InvalidArgumentException('Каталог фото недоступен для записи. Обратитесь к администратору.');
        }
        // Also deny direct HTTP access independently of the parent files/.htaccess.
        if (!is_file($this->directory.'/.htaccess') && file_put_contents($this->directory.'/.htaccess', "Require all denied\n") === false) {
            throw new \InvalidArgumentException('Не удалось защитить каталог фото.');
        }
        if (!is_resource($this->lock)) {
            $this->lock = fopen($this->directory.'/.upload.lock', 'c');
            if (!$this->lock || !flock($this->lock, LOCK_EX)) { throw new \InvalidArgumentException('Хранилище занято. Повторите загрузку.'); }
        }
        $used = $this->usage();
        if ($used + $info['size'] > self::QUOTA_BYTES) {
            throw new \InvalidArgumentException('Хранилище фото заполнено (500 МиБ). Обратитесь к администратору.');
        }
        $info['token'] = bin2hex(random_bytes(16));
        if (!move_uploaded_file($upload['tmp_name'], $this->path($info['token']))) {
            throw new \InvalidArgumentException('Не удалось сохранить фото.');
        }
        return $info;
    }
    public function path(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) { throw new \InvalidArgumentException('Некорректный идентификатор фото.'); }
        return $this->directory.'/'.$token.'.bin';
    }
    public function discard(string $token): void
    {
        $path = $this->path($token);
        if (is_file($path)) { unlink($path); }
    }
    public function __destruct()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }
}
