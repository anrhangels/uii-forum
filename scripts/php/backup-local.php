<?php
require __DIR__.'/../../runtime/board/config.php';
$db=new mysqli($dbhost,$dbuser,$dbpasswd,$dbname);
$db->set_charset('utf8mb4');
$path=__DIR__.'/../../snapshots/database-local-'.date('Ymd-His').'.sql';
$fp=fopen($path,'x'); chmod($path,0600);
fwrite($fp,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$tables=$db->query('SHOW TABLES');
while ($table=$tables->fetch_row()) {
 $name=$table[0]; $create=$db->query('SHOW CREATE TABLE `'.$db->real_escape_string($name).'`')->fetch_row();
 fwrite($fp,$create[1].";\n");
 $rows=$db->query('SELECT * FROM `'.$db->real_escape_string($name).'`');
 while ($row=$rows->fetch_row()) {
  $values=array_map(fn($v)=>$v===null?'NULL':"'".$db->real_escape_string($v)."'",$row);
  fwrite($fp,'INSERT INTO `'.$name.'` VALUES ('.implode(',',$values).");\n");
 }
}
$db->commit();fwrite($fp,"SET FOREIGN_KEY_CHECKS=1;\n");fclose($fp);
echo 'Backup saved: '.basename($path).PHP_EOL;
