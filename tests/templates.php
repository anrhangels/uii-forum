<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__).'/app/';
require $root.'vendor/autoload.php';
spl_autoload_register(static function($class) use ($root) {
    if (strpos($class,'phpbb\\')===0) { $path=$root.str_replace('\\','/',$class).'.php'; if (is_file($path)) { require_once $path; } }
});
$twig=new Twig\Environment(new Twig\Loader\ArrayLoader(),['cache'=>false]);
$twig->setLexer(new phpbb\template\twig\lexer($twig));
$twig->addExtension(new class extends Twig\Extension\AbstractExtension { public function getOperators() { return (new ReflectionClass(phpbb\template\twig\extension::class))->newInstanceWithoutConstructor()->getOperators(); } });
foreach (['includeparser','includejs','includecss','defineparser'] as $parser) {
    $class='phpbb\\template\\twig\\tokenparser\\'.$parser; $twig->addTokenParser(new $class());
}
foreach (['lang','path'] as $fn) { $twig->addFunction(new Twig\TwigFunction($fn,static function() { return ''; })); }
$twig->addFilter(new Twig\TwigFilter('subset',static function($value) { return $value; }));
$count=0;
foreach ([$root.'styles/uii_modern/template',$root.'ext/uii/portal/styles/all/template',dirname(__DIR__).'/deployment/installer'] as $directory) {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension()!=='html') { continue; }
        $source=file_get_contents($file->getPathname());
        // Event expansion requires a running extension manager; parse the actual remaining syntax.
        $source=preg_replace('~<!-- EVENT .*? -->|\{% EVENT .*? %\}~','',$source);
        $twig->parse($twig->tokenize(new Twig\Source($source,$file->getPathname())));
        $count++;
    }
}
echo "Passed template syntax: $count\n";
