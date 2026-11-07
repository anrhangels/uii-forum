<?php
if (PHP_SAPI!=='cli') { exit(1); }
require dirname(__DIR__).'/app/ext/uii/portal/service/image_optimizer.php';
use uii\portal\service\image_optimizer as O;
if (!extension_loaded('gd')) { throw new RuntimeException('Run with GD enabled'); }
$dir=sys_get_temp_dir().'/uii-compression-'.bin2hex(random_bytes(8));mkdir($dir,0700);
function expect($value,$message) { if (!$value) { throw new RuntimeException($message); } }
function jpeg(string $path,int $width,int $height): void {
 $im=imagecreatetruecolor($width,$height);
 for ($y=0;$y<$height;$y++) { $c=imagecolorallocate($im,$y%255,($y*3)%255,($y*7)%255);imageline($im,0,$y,$width-1,$y,$c); }
 imagejpeg($im,$path,100);unset($im);
}
try {
 $file=$dir.'/large.jpg';jpeg($file,3200,1800);$before=filesize($file);$r=O::optimize($file);$size=getimagesize($file);
 expect($r['optimized'] && $r['size']<$before && $size[0]===2560 && $size[1]===1440,'JPEG resize/compression failed');
 echo 'PASS JPEG: '.$before.' -> '.$r['size']." bytes; 3200x1800 -> 2560x1440\n";
 $file=$dir.'/transparent.png';$im=imagecreatetruecolor(200,120);imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocatealpha($im,10,40,90,127));imagefilledrectangle($im,20,20,100,100,imagecolorallocatealpha($im,40,100,180,60));imagepng($im,$file,0);unset($im);
 $r=O::optimize($file);$im=imagecreatefrompng($file);$alpha=imagecolorsforindex($im,imagecolorat($im,0,0))['alpha'];$partial=imagecolorsforindex($im,imagecolorat($im,30,30))['alpha'];unset($im);
 expect($r['optimized'] && $alpha===127 && $partial===60,'PNG alpha lost');echo 'PASS PNG lossless compression preserves full and partial transparency: '.$r['original_size'].' -> '.$r['size']." bytes\n";
 $hash=hash_file('sha256',$file);$r=O::optimize($file);expect(!$r['optimized'] && hash_file('sha256',$file)===$hash,'Already optimized image grew or changed');echo "PASS no larger or redundant replacement\n";
 $file=$dir.'/rotate.jpg';jpeg($file,640,360);$bytes=file_get_contents($file);$exif="Exif\0\0II".pack('vV',42,8).pack('v',1).pack('vvVV',274,3,1,6).pack('V',0);file_put_contents($file,substr($bytes,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($bytes,2));unset($bytes);$r=O::optimize($file);$size=getimagesize($file);expect($r['optimized'] && $size[0]===360 && $size[1]===640,'EXIF orientation not normalized');echo "PASS EXIF orientation preserved as rotated pixels\n";
 $file=$dir.'/memory.jpg';jpeg($file,1600,900);$hash=hash_file('sha256',$file);$limit=ini_get('memory_limit');ini_set('memory_limit','20M');$r=O::optimize($file);ini_set('memory_limit',$limit);expect(!$r['optimized'] && $hash===hash_file('sha256',$file),'Low-memory original changed');echo "PASS low-memory fallback preserves original\n";
 if (function_exists('imagewebp') && function_exists('imagecreatefromwebp')) {
  $file=$dir.'/noise.webp';$im=imagecreatetruecolor(200,120);mt_srand(7);for($y=0;$y<120;$y++){for($x=0;$x<200;$x++){imagesetpixel($im,$x,$y,mt_rand(0,16777215));}}imagewebp($im,$file,100);unset($im);$r=O::optimize($file);expect($r['optimized'] && getimagesize($file)[2]===IMAGETYPE_WEBP,'WebP compression failed');echo "PASS supported WebP optimized without changing format\n";
 }
 require dirname(__DIR__).'/app/vendor/autoload.php';require dirname(__DIR__).'/app/ext/uii/portal/event/listener.php';
 $phpbb_root_path=$dir.'/';$config=['upload_path'=>'attachments'];mkdir($dir.'/attachments');$native=$dir.'/attachments/native_1.jpg';jpeg($native,640,360);$original=filesize($native);
 $listener=(new ReflectionClass(uii\portal\event\listener::class))->newInstanceWithoutConstructor();$event=new ArrayObject(['is_image'=>true,'filedata'=>['physical_filename'=>'native_1.jpg','filesize'=>$original,'mimetype'=>'image/jpeg','extension'=>'jpg']]);$listener->compressAttachment($event);clearstatcache(true,$native);expect($event['filedata']['filesize']===filesize($native) && filesize($native)<$original && $event['filedata']['mimetype']==='image/jpeg','Native attachment metadata mismatch');
 $hash=hash_file('sha256',$native);$forged=new ArrayObject(['is_image'=>true,'filedata'=>['physical_filename'=>'../native_1.jpg','filesize'=>$original]]);$listener->compressAttachment($forged);expect(hash_file('sha256',$native)===$hash,'Attachment traversal not rejected');unlink($native);rmdir($dir.'/attachments');echo "PASS native attachment event: actual filesize, same MIME and path traversal rejection\n";
 expect(count(glob($dir.'/.uii-image-*'))===0,'Temporary files leaked');echo "PASS temporary candidates cleaned\n";
} finally { foreach (glob($dir.'/*') ?: [] as $file) { unlink($file); }foreach (glob($dir.'/.uii-image-*') ?: [] as $file) { unlink($file); }rmdir($dir); }
