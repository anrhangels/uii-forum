<?php
if (PHP_SAPI!=='cli') { exit(1); }
require __DIR__.'/../../runtime/board/config.php';
$db=new mysqli($dbhost,$dbuser,$dbpasswd,$dbname);$db->set_charset('utf8mb4');
if (($argv[1] ?? '')==='overdue') {
 $id=(int)($argv[2] ?? 0);
 $r=$db->query("SELECT title,kind FROM {$table_prefix}uii_cases WHERE case_id = $id");$row=$r->fetch_assoc();
 if (!$row || !str_starts_with($row['title'],'QA:') || $row['kind']!=='request') { throw new RuntimeException('Только тестовые запросы QA'); }
 $end=time()-60;$start=$end-172800;
 $db->query("UPDATE {$table_prefix}uii_cases SET started_at=$start, deadline_at=$end WHERE case_id=$id");
 echo "QA deadline moved to past\n";
} elseif (($argv[1] ?? '')==='user') {
 $name=$argv[2] ?? '';
 if (!str_starts_with($name,'Qatest_')) { throw new RuntimeException('Только QA-аккаунты'); }
 $r=$db->query("SELECT user_id FROM {$table_prefix}users WHERE username='".$db->real_escape_string($name)."'");echo $r->fetch_assoc()['user_id'] ?? 0;
} elseif (($argv[1] ?? '')==='foreign-case') {
 $id=(int)($argv[2] ?? 0);
 $r=$db->query("SELECT case_id,topic_id FROM {$table_prefix}uii_cases WHERE title LIKE 'QA:%' AND author_id<>$id AND assigned_id<>$id ORDER BY case_id DESC LIMIT 1");
 echo json_encode($r->fetch_assoc());

}
