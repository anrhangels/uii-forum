<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/service/avatars.php';
use uii\portal\service\avatars as A;
foreach(['male'=>'avatar-male.webp','female'=>'avatar-female.webp','unspecified'=>'avatar-neutral.svg','../../etc/passwd'=>'avatar-neutral.svg','https://example.test/x'=>'avatar-neutral.svg'] as $input=>$expected){if(A::file($input)!==$expected){throw new RuntimeException('Avatar whitelist failed');}}
require dirname(__DIR__,4).'/vendor/autoload.php';
require dirname(__DIR__).'/event/listener.php';
$listener=(new ReflectionClass(\uii\portal\event\listener::class))->newInstanceWithoutConstructor();
$event=new ArrayObject(['add_notifications_override'=>false,'notified_users'=>[2]]);$listener->stopNotifications($event);
if(!$event['add_notifications_override'] || $event['notified_users']!==[]){throw new RuntimeException('Notifications override failed');}
$direct=new ArrayObject(['notify_users'=>[2=>['notification.method.board']]]);$listener->stopNotificationRecipients($direct);if($direct['notify_users']!==[]){throw new RuntimeException('Direct notifications failed');}
echo "Passed fixed identity/disabled notification checks: 7\n";
