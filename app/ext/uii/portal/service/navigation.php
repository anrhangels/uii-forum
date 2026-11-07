<?php
namespace uii\portal\service;
final class navigation
{
    public static function catalog(): array
    {
        return [
            'home'=>['Главная','home','index',[],1],
            'tasks'=>['Мои задачи','tasks','uii_portal_tasks',[],1],
            'complaints'=>['Жалобы','file','uii_portal_cases',['kind'=>'complaint'],1],
            'requests'=>['Служебные запросы','clock','uii_portal_cases',['kind'=>'request'],1],
            'inspections'=>['Проверки состава','inspect','uii_portal_cases',['kind'=>'inspection'],1],
            'archive'=>['Архив','archive','uii_portal_cases',['kind'=>'all','archive'=>1],1],
            'employees'=>['Сотрудники','team','uii_portal_employees',[],1],
            'account'=>['Мой профиль','account','uii_portal_account',[],1],
            'bug_report'=>['Сообщить об ошибке','journal','uii_portal_bug_create',[],0],
            'updates'=>['Обновления','settings','uii_portal_updates',[],0],
            'bug_admin'=>['Баг-репорты','inspect','uii_portal_bug_admin',[],0],
            'settings'=>['Настройки','settings','uii_portal_settings',[],1],
            'admin'=>['Обзор управления','inspect','uii_portal_admin',[],2],
            'staff_admin'=>['Аккаунты сотрудников','team','uii_portal_employees',[],2],
            'activity'=>['Журнал действий','journal','uii_portal_activity',[],3],
            'navigation'=>['Настройка меню','settings','uii_portal_navigation',[],4],
        ];
    }
    public static function defaults(): array
    {
        return [
            ['id'=>'workspace','title'=>'Рабочее пространство','items'=>['home','tasks']],
            ['id'=>'cases','title'=>'Дела отдела','items'=>['complaints','requests','inspections','archive']],
            ['id'=>'department','title'=>'Отдел','items'=>['employees','account','settings','bug_report']],
            ['id'=>'management','title'=>'Управление','items'=>['admin','staff_admin','activity','navigation','bug_admin','updates']],
        ];
    }
    public static function validate($layout): array
    {
        if (!is_array($layout) || !array_is_list($layout) || count($layout)<1 || count($layout)>8) { throw new \InvalidArgumentException('В меню должно быть от 1 до 8 групп.'); }
        $catalog=self::catalog();$ids=[];$items=[];$clean=[];
        foreach ($layout as $group) {
            if (!is_array($group) || !isset($group['id'],$group['title'],$group['items']) || !is_string($group['id']) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$group['id']) || isset($ids[$group['id']])) { throw new \InvalidArgumentException('Некорректная или повторная группа меню.'); }
            if (!is_string($group['title']) || !preg_match('/^.{1,40}$/uD',trim($group['title'])) || preg_match('/[<>\x00-\x1f\x7f]/u',$group['title'])) { throw new \InvalidArgumentException('Название группы - обычный текст от 1 до 40 символов, без HTML и переносов строк.'); }
            if (!is_array($group['items']) || !array_is_list($group['items']) || count($group['items'])>count($catalog)) { throw new \InvalidArgumentException('Некорректный список пунктов меню.'); }
            foreach ($group['items'] as $item) {
                if (!is_string($item) || !isset($catalog[$item]) || isset($items[$item])) { throw new \InvalidArgumentException('Неизвестный или повторный пункт меню.'); }
                $items[$item]=true;
            }
            $ids[$group['id']]=true;
            $clean[]=['id'=>$group['id'],'title'=>trim($group['title']),'items'=>$group['items']];
        }
        if (count($items)!==count($catalog)) { throw new \InvalidArgumentException('Все рабочие ссылки должны оставаться в меню. Перенесите пункты из удаляемой группы.'); }
        return $clean;
    }
    public static function visible(array $layout, int $level, bool $administrator, bool $bugSend=false, bool $bugAdmin=false, bool $updates=false): array
    {
        $catalog=self::catalog();$result=[];
        foreach ($layout as $group) {
            $group['items']=array_values(array_filter($group['items'],static function($id) use($catalog,$level,$administrator,$bugSend,$bugAdmin,$updates) {
                return isset($catalog[$id]) && $level >= $catalog[$id][4] && ($id!=='navigation' || $administrator) && ($id!=='bug_report' || $bugSend) && ($id!=='bug_admin' || $bugAdmin) && ($id!=='updates' || $updates);
            }));
            if ($group['items']) { $result[]=$group; }
        }
        return $result;
    }
    public static function load($db, string $prefix, bool $ready): array
    {
        if (!$ready) { return ['version'=>0,'layout'=>self::defaults()]; }
        $r=$db->sql_query('SELECT version, payload FROM '.$prefix.'uii_navigation WHERE nav_id = 1');
        $row=$db->sql_fetchrow($r);$db->sql_freeresult($r);
        if (!$row) { throw new \RuntimeException('Конфигурация меню отсутствует. Выполните обновление портала.'); }
        try { $layout=self::validate(json_decode($row['payload'],true)); $invalid=false; }
        catch (\InvalidArgumentException $e) { $layout=self::defaults(); $invalid=true; }
        return ['version'=>(int)$row['version'],'layout'=>$layout,'invalid'=>$invalid];
    }
}
