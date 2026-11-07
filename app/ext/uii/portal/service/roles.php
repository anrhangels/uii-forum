<?php
namespace uii\portal\service;
final class roles
{
    const LEVELS=['UII_STAFF'=>1,'UII_DEPUTY'=>2,'UII_CHIEF'=>3,'UII_FACTION'=>4];
    public static function label(int $level): string
    {
        return [1=>'Сотрудник УИИ',2=>'Заместитель начальника',3=>'Начальник УИИ',4=>'Руководство фракции'][$level] ?? 'Не назначена';
    }
    public static function level($db,$prefix,int $id): int
    {
        $r=$db->sql_query('SELECT g.group_name FROM '.$prefix.'groups g JOIN '.$prefix.'user_group ug ON ug.group_id = g.group_id WHERE ug.user_pending = 0 AND ug.user_id = '.$id);
        $level=0;
        while ($g=$db->sql_fetchrow($r)) { $level=max($level,self::LEVELS[$g['group_name']] ?? 0); }
        $db->sql_freeresult($r); return $level;
    }
    public static function effective($db,$prefix,array $user): int
    {
        // Основатель - единственный технический обход; общий a_ не означает руководство УИИ.
        return (int)($user['user_type'] ?? 0)===USER_FOUNDER ? 4 : self::level($db,$prefix,(int)$user['user_id']);
    }
    public static function mayManage(int $actor,int $target,int $newLevel): bool
    {
        if ($actor===4) { return $newLevel>=1 && $newLevel<=4; }
        return $actor>=2 && $target<=1 && $newLevel===1;
    }
}
