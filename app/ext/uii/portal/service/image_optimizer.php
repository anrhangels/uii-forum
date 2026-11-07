<?php
namespace uii\portal\service;

/** Bounded, format-preserving optimization of a newly uploaded local image. */
final class image_optimizer
{
    const MAX_EDGE=2560;
    const QUALITY=85;

    public static function optimize(string $path): array
    {
        clearstatcache(true,$path);
        $original=is_file($path) ? (int)filesize($path) : 0;
        $result=['original_size'=>$original,'size'=>$original,'optimized'=>false];
        $info=@getimagesize($path);
        $formats=[IMAGETYPE_JPEG=>['imagecreatefromjpeg','imagejpeg'],IMAGETYPE_PNG=>['imagecreatefrompng','imagepng'],IMAGETYPE_WEBP=>['imagecreatefromwebp','imagewebp']];
        if (!$info || $info[0]<1 || $info[1]<1 || $info[0]>12000 || $info[1]>12000 || !$original || $original>8388608 || !isset($formats[$info[2]]) || $info[0]*$info[1]>24000000) { return $result; }
        [$decode,$encode]=$formats[$info[2]];
        if (!function_exists($decode) || !function_exists($encode)) { return $result; }
        $header=file_get_contents($path,false,null,0,($info[2]===IMAGETYPE_JPEG ? $original : min($original,262144)));
        // Animated images must not silently become still frames.
        if (($info[2]===IMAGETYPE_PNG && strpos($header,'acTL')!==false) || ($info[2]===IMAGETYPE_WEBP && strpos($header,'ANIM')!==false)) { return $result; }
        $orientation=$info[2]===IMAGETYPE_JPEG ? self::orientation($header) : 1;
        if ($orientation===null) { return $result; }
        $ratio=min(1,self::MAX_EDGE/max($info[0],$info[1]));
        $width=max(1,(int)round($info[0]*$ratio));$height=max(1,(int)round($info[1]*$ratio));
        // Conservative estimate, also capped when PHP has no memory_limit.
        $limit=self::memoryLimit();
        $estimate=$info[0]*$info[1]*8 + $width*$height*8 + $original + 16777216;
        if ($orientation!==1) { $estimate+=$info[0]*$info[1]*8; }
        if (memory_get_usage(true)+$estimate>$limit) { return $result; }
        $image=null;$scaled=null;$candidate=null;
        try {
            $image=@$decode($path);
            if (!$image) { return $result; }
            if (in_array($orientation,[2,5,7],true)) { imageflip($image,IMG_FLIP_HORIZONTAL); }
            elseif ($orientation===4) { imageflip($image,IMG_FLIP_VERTICAL); }
            $angle=[3=>180,5=>90,6=>-90,7=>-90,8=>90][$orientation] ?? 0;
            if ($angle) { $rotated=imagerotate($image,$angle,0);if (!$rotated) { return $result; }$image=$rotated; }
            $sourceWidth=imagesx($image);$sourceHeight=imagesy($image);
            $ratio=min(1,self::MAX_EDGE/max($sourceWidth,$sourceHeight));
            $width=max(1,(int)round($sourceWidth*$ratio));$height=max(1,(int)round($sourceHeight*$ratio));
            if ($ratio<1) {
                $scaled=imagecreatetruecolor($width,$height);
                imagealphablending($scaled,false);imagesavealpha($scaled,true);
                imagefill($scaled,0,0,imagecolorallocatealpha($scaled,0,0,0,127));
                if (!imagecopyresampled($scaled,$image,0,0,0,0,$width,$height,$sourceWidth,$sourceHeight)) { return $result; }
                $image=$scaled;
            }
            imagesavealpha($image,true);
            $candidate=tempnam(dirname($path),'.uii-image-');
            if ($candidate===false) { return $result; }
            $ok=$info[2]===IMAGETYPE_PNG ? @imagepng($image,$candidate,9) : @$encode($image,$candidate,self::QUALITY);
            clearstatcache(true,$candidate);$size=(int)filesize($candidate);$check=@getimagesize($candidate);
            if (!$ok || !$check || $check[2]!==$info[2] || $check[0]!==$width || $check[1]!==$height || $size<=0 || $size>=(int)floor($original*.95)) { return $result; }
            // Same directory, atomic replacement; keep original unless all checks pass.
            @chmod($candidate,fileperms($path)&0777);
            if (!@rename($candidate,$path)) { return $result; }
            $candidate=null;clearstatcache(true,$path);
            return ['original_size'=>$original,'size'=>$size,'optimized'=>true,'width'=>$width,'height'=>$height];
        } catch (\Throwable $error) {
            return $result;
        } finally {
            // GD objects are released when references leave scope, including PHP 8.5.
            $image=null;$scaled=null;
            if (is_string($candidate) && is_file($candidate)) { @unlink($candidate); }
        }
    }
    private static function memoryLimit(): int
    {
        $raw=trim((string)ini_get('memory_limit'));
        if ($raw==='' || $raw==='-1') { return 134217728; }
        $bytes=(int)$raw;$unit=strtolower(substr($raw,-1));
        if ($unit==='g') { $bytes*=1073741824; } elseif ($unit==='m') { $bytes*=1048576; } elseif ($unit==='k') { $bytes*=1024; }
        return min(134217728,$bytes);
    }
    /** Read only the inline TIFF orientation; malformed metadata is left untouched. */
    private static function orientation(string $header): ?int
    {
        $position=strpos($header,"Exif\0\0");
        if ($position===false) { return 1; }
        $tiff=substr($header,$position+6);$length=strlen($tiff);
        if ($length<8 || !in_array(substr($tiff,0,2),['II','MM'],true)) { return null; }
        $little=substr($tiff,0,2)==='II';
        $read=static function(int $offset,int $bytes) use ($tiff,$length,$little): ?int {
            if ($offset<0 || $offset+$bytes>$length) { return null; }
            return unpack($bytes===2 ? ($little?'v':'n') : ($little?'V':'N'),substr($tiff,$offset,$bytes))[1];
        };
        if ($read(2,2)!==42) { return null; }
        $offset=$read(4,4);$count=$offset===null ? null : $read($offset,2);
        if ($count===null || $count>256 || $offset+2+$count*12>$length) { return null; }
        for ($i=0;$i<$count;$i++) {
            $entry=$offset+2+$i*12;
            if ($read($entry,2)!==274) { continue; }
            if ($read($entry+2,2)!==3 || $read($entry+4,4)!==1) { return null; }
            $value=$read($entry+8,2);return $value>=1 && $value<=8 ? $value : null;
        }
        return 1;
    }
}
