<?php
namespace uii\portal\service;
/** Streaming snapshot into a private file. Restore is deliberately manual, into a separate DB. */
final class update_backup
{
    public static function database($db,string $prefix,string $destination): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$prefix)) {throw new \RuntimeException('Некорректный префикс БД.');}
        $mysql=$db->get_sql_layer()==='mysqli';$sqlite=$db->get_sql_layer()==='sqlite3';
        if (!$mysql && !$sqlite) {throw new \RuntimeException('Автоматическая резервная копия поддерживает MySQL/MariaDB и SQLite. Для этой СУБД нужен ручной перенос.');}
        $handle=@fopen($destination,'xb');if (!$handle) {throw new \RuntimeException('Не удалось создать резервную копию БД.');}$transaction=false;
        $write=static function(string $text) use ($handle) {if (ftell($handle)+strlen($text)>268435456 || fwrite($handle,$text)!==strlen($text)) {throw new \RuntimeException('Не хватает места для резервной копии БД либо она превышает 256 МиБ.');}};
        $db->sql_return_on_error(true);
        $query=static function($sql) use ($db) {$r=$db->sql_query($sql);if ($r===false) {throw new \RuntimeException('Не удалось прочитать таблицу резервной копии.');}return $r;};
        try {
            $tables=[];$r=$query($mysql ? 'SHOW TABLES' : "SELECT name FROM sqlite_master WHERE type='table'");
            while ($row=$db->sql_fetchrow($r)) {$name=(string)reset($row);if (strpos($name,$prefix)===0 && preg_match('/^[A-Za-z0-9_]+$/D',$name)) {$tables[]=$name;}}$db->sql_freeresult($r);
            if (!$tables) {throw new \RuntimeException('Таблицы сайта не найдены.');}
            if (!$db->sql_transaction('begin')) {throw new \RuntimeException('Не удалось начать резервное копирование.');}$transaction=true;
            $write("-- UII private snapshot; import into an EMPTY database, never over active users' data.\n");if ($mysql) {$write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");}
            foreach ($tables as $table) {
                $binary=[];$indexes=[];
                if ($mysql) {
                    $r=$query('SHOW CREATE TABLE `'.$table.'`');$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);$ddl=array_values($row)[1];
                    if (!preg_match('/ENGINE=InnoDB\b/i',$ddl)) {throw new \RuntimeException('Таблица '.$table.' не использует InnoDB. Нужна проверка согласованной резервной копии вручную.');}
                    $r=$query('SHOW COLUMNS FROM `'.$table.'`');while ($column=$db->sql_fetchrow($r)) {if (preg_match('/blob|binary|^bit\b/i',$column['Type'])) {$binary[$column['Field']]=true;}}$db->sql_freeresult($r);
                } else {
                    $r=$query("SELECT sql FROM sqlite_master WHERE type='table' AND name='".$db->sql_escape($table)."'");$row=$db->sql_fetchrow($r);$ddl=$row['sql'];$db->sql_freeresult($r);
                    $r=$query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='".$db->sql_escape($table)."' AND sql IS NOT NULL");while ($row=$db->sql_fetchrow($r)) {$indexes[]=$row['sql'];}$db->sql_freeresult($r);
                }
                $write($ddl.";\n");$offset=0;
                do {
                    $r=$db->sql_query_limit('SELECT * FROM `'.$table.'`',100,$offset);if ($r===false) {throw new \RuntimeException('Ошибка чтения резервной копии.');}$rows=0;
                    while ($row=$db->sql_fetchrow($r)) {
                        $values=[];foreach ($row as $column=>$value) {
                            if ($value===null) {$values[]='NULL';}
                            elseif ($mysql && isset($binary[$column])) {$values[]="X'".bin2hex((string)$value)."'";}
                            else {$values[]="'".$db->sql_escape((string)$value)."'";}
                        }
                        $columns=array_map(static function($name) {if (!preg_match('/^[A-Za-z0-9_]+$/D',$name)) {throw new \RuntimeException('Недопустимое имя столбца.');}return '`'.$name.'`';},array_keys($row));
                        $write('INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");$rows++;
                    }
                    $db->sql_freeresult($r);$offset+=$rows;
                } while ($rows===100);
                foreach ($indexes as $index) {$write($index.";\n");}
            }
            if ($mysql) {$write("SET FOREIGN_KEY_CHECKS=1;\n");}
            if (!$db->sql_transaction('commit')) {throw new \RuntimeException('Не удалось завершить резервную копию.');}$transaction=false;
        } catch (\Throwable $e) {if ($transaction) {$db->sql_transaction('rollback');}throw $e;}
        finally {fclose($handle);$db->sql_return_on_error(false);@chmod($destination,0600);}
    }
}
