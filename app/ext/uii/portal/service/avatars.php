<?php
namespace uii\portal\service;
final class avatars
{
    public static function file(string $gender): string { return ['male'=>'avatar-male.webp','female'=>'avatar-female.webp'][$gender] ?? 'avatar-neutral.svg'; }
    public static function label(string $gender): string { return ['male'=>'Мужской','female'=>'Женский'][$gender] ?? 'Не указан'; }
    public static function url(string $gender): string { return generate_board_url().'/ext/uii/portal/styles/all/theme/avatars/'.self::file($gender); }
}
