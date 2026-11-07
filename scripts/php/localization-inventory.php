<?php
// Inventory actual missing language keys, including all installed Russian files.
if (PHP_SAPI!=='cli') { exit(1); }
define('IN_PHPBB',true);
$root=dirname(__DIR__,2).'/app/';
function load_dictionary(string $path): array { $lang=[];require $path;return $lang; }
$remaining=[];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'language/en',FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension()!=='php') { continue; }
    $relative=substr($file->getPathname(),strlen($root.'language/en/'));
    $english=load_dictionary($file->getPathname());
    $translated=is_file($root.'language/ru/'.$relative) ? load_dictionary($root.'language/ru/'.$relative) : [];
    $missing=array_diff_key($english,$translated);
    if ($missing) { $remaining[$relative]=$missing; }
}
file_put_contents(dirname(__DIR__,2).'/docs/localization-gaps.json',json_encode((object)$remaining,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);
echo 'Missing Russian dictionary keys: '.array_sum(array_map('count',$remaining)).PHP_EOL;
