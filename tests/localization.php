<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('IN_PHPBB',true);
$root=dirname(__DIR__).'/app/language/';
function dictionary(string $file): array { $lang=[]; require $file; return $lang; }
function placeholders(string $text): array {
    preg_match_all('/%(?:\d+\$)?[+\-0-9.]*[bcdeEfFgGosuxX]/',$text,$matches);
    $items=[];
    foreach ($matches[0] as $item) { preg_match('/%(?:(\d+)\$)?[^a-zA-Z]*([a-zA-Z])$/',$item,$m); $items[]=($m[1] ?: count($items)+1).':'.$m[2]; }
    $items=array_values(array_unique($items)); sort($items); return $items;
}
$missing=[];$formats=[];$count=0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'en',FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension()!=='php') { continue; }
    $relative=substr($file->getPathname(),strlen($root.'en/'));
    $ru=$root.'ru/'.$relative;
    if (!is_file($ru)) { $missing[]=$relative; continue; }
    $en=dictionary($file->getPathname());$translated=dictionary($ru);
    foreach ($en as $key=>$value) {
        if (!array_key_exists($key,$translated)) { $missing[]=$relative.':'.$key;continue; }
        if (is_string($value) && is_string($translated[$key]) && placeholders($value)!==placeholders($translated[$key])) { $formats[]=$relative.':'.$key; }
        $count++;
    }
}
echo json_encode(['checked'=>$count,'missing'=>$missing,'formats'=>$formats],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
if ($missing || $formats) { exit(1); }
