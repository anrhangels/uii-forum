<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/domain/workflow.php';
use uii\portal\domain\workflow as W;
$count = 0;
function check($value, $message) { global $count; if (!$value) { throw new RuntimeException($message); } $count++; }
check(W::deadline(1000) === 173800, '48 часов');
check(W::requestState(200, 0, false, 199) === 'waiting', 'До срока');
check(W::requestState(200, 0, false, 200) === 'overdue', 'Граница срока');
check(W::requestState(200, 200, false, 300) === 'answered_on_time', 'Ответ на границе');
check(W::requestState(200, 201, false, 300) === 'answered_late', 'Поздний ответ');
check(W::requestState(200, 0, true, 300) === 'closed', 'Закрытый запрос');
check(W::nickname('Ivan_Petrov'), 'Игровой ник');
check(!W::nickname('admin<script>'), 'Недопустимый ник');
foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://user:pass@example.test/'] as $url) {
    try { W::url($url); check(false, 'Небезопасная ссылка'); } catch (InvalidArgumentException $e) { check(true, 'Отклонено'); }
}
try { W::closeComplaint([]); check(false, 'Пустое дело'); } catch (InvalidArgumentException $e) { check(true, 'Отклонено'); }
W::closeComplaint(['protocol'=>'Опрос проведён', 'document_url'=>'https://example.test/proof', 'decision'=>'Нарушение не установлено', 'violations'=>'Не установлены']);
check(true, 'Закрытие с обязательными данными');
echo "Passed: $count\n";
