<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/service/roles.php';
use uii\portal\service\roles as R;
foreach ([[4,3,4,true],[3,1,1,true],[3,1,4,false],[2,3,1,false],[1,1,1,false],[2,1,1,true]] as $case) {
    if (R::mayManage($case[0],$case[1],$case[2])!==$case[3]) { throw new RuntimeException('Role hierarchy failed'); }
}
echo "Passed role policies: 6\n";
