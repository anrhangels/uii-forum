<?php
if (PHP_SAPI!=='cli') { exit(1); }
$path=$argv[1] ?? dirname(__DIR__,2).'/app/config.php';
require $path;
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table_prefix)) { throw new RuntimeException('Invalid table prefix'); }
$d=new mysqli($dbhost,$dbuser,$dbpasswd,$dbname,(int)($dbport ?: 3306));$d->set_charset('utf8mb4');
$out=['time_utc'=>gmdate('c'),'database_version'=>$d->server_info,'note'=>'CLI PHP values do not describe Apache PHP. No workload is generated.'];
foreach (['variables'=>"SHOW VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','max_connections','tmp_table_size','max_heap_table_size','sort_buffer_size','join_buffer_size','thread_cache_size')",'status'=>"SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Created_tmp_tables','Created_tmp_disk_tables','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests')",'table_sizes'=>"SELECT table_name,table_rows,data_length,index_length FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '%uii_%'"] as $name=>$sql) {$out[$name]=$d->query($sql)->fetch_all(MYSQLI_ASSOC);}
foreach (['cases'=>"SELECT case_id,title FROM {$table_prefix}uii_cases WHERE closed_at=0 ORDER BY updated_at DESC,case_id DESC LIMIT 26",'history'=>"SELECT actor_id,created_at,action FROM {$table_prefix}uii_history WHERE case_id=1 ORDER BY history_id DESC LIMIT 50",'statistics'=>"SELECT COUNT(*) FROM {$table_prefix}uii_cases WHERE author_id=2 OR assigned_id=2"] as $name=>$sql) {$out['explain'][$name]=$d->query('EXPLAIN '.$sql)->fetch_all(MYSQLI_ASSOC);}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
