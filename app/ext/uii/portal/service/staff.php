<?php
namespace uii\portal\service;
final class staff
{
    const FILTERS=['all'=>'Все сотрудники','online'=>'В сети','active'=>'Активные','blocked'=>'Заблокированные','transferred'=>'Переведены из отдела','left'=>'Покинули организацию'];
    const REASONS=['other'=>'Доступ ограничен руководством','transferred'=>'Переведён из отдела','left'=>'Покинул организацию'];
    public static function source(string $prefix, int $normal, int $founder): array
    {
        $role='CASE WHEN u.user_type = '.$founder.' THEN 4 ELSE COALESCE(r.employee_role,0) END';
        $from=' FROM '.$prefix.'users u LEFT JOIN (SELECT ug.user_id, MAX(CASE g.group_name WHEN \'UII_STAFF\' THEN 1 WHEN \'UII_DEPUTY\' THEN 2 WHEN \'UII_CHIEF\' THEN 3 WHEN \'UII_FACTION\' THEN 4 ELSE 0 END) AS employee_role FROM '.$prefix.'user_group ug JOIN '.$prefix.'groups g ON g.group_id = ug.group_id WHERE ug.user_pending = 0 GROUP BY ug.user_id) r ON r.user_id = u.user_id LEFT JOIN (SELECT session_user_id, MAX(session_time) AS last_seen FROM '.$prefix.'sessions WHERE session_viewonline = 1 GROUP BY session_user_id) s ON s.session_user_id = u.user_id';
        return ['role'=>$role,'from'=>$from,'where'=>'u.user_type IN ('.$normal.','.$founder.') AND ('.$role.') > 0'];
    }
    public static function condition(string $status, int $cutoff): string
    {
        return [
            'all'=>'1=1','online'=>'u.uii_blocked = 0 AND COALESCE(s.last_seen,0) >= '.$cutoff,
            'active'=>'u.uii_blocked = 0','blocked'=>'u.uii_blocked = 1',
            'transferred'=>"u.uii_blocked = 1 AND u.uii_block_reason = 'transferred'",
            'left'=>"u.uii_blocked = 1 AND u.uii_block_reason = 'left'",
        ][$status] ?? '1=1';
    }
    public static function reason(string $reason): string
    {
        if (!isset(self::REASONS[$reason])) { throw new \InvalidArgumentException('Выберите причину блокировки.'); }
        return $reason;
    }
}
