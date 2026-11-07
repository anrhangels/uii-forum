<?php
namespace uii\portal\service;
final class statistics
{
    public static function employee($db, string $prefix, int $id, int $now): array
    {
        $r=$db->sql_query('SELECT SUM(CASE WHEN author_id = '.$id.' THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN assigned_id = '.$id.' AND closed_at = 0 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN assigned_id = '.$id.' AND closed_at = 0 AND deadline_at > 0 AND deadline_at <= '.$now.' AND answered_at = 0 THEN 1 ELSE 0 END) AS overdue FROM '.$prefix.'uii_cases WHERE author_id = '.$id.' OR assigned_id = '.$id);
        $totals=$db->sql_fetchrow($r) ?: []; $db->sql_freeresult($r);
        // The closer is the actor of the close event, not the current assignee.
        $r=$db->sql_query("SELECT COUNT(DISTINCT h.case_id) AS total FROM ".$prefix."uii_history h JOIN ".$prefix."uii_cases c ON c.case_id = h.case_id WHERE h.actor_id = ".$id." AND h.action = 'close' AND c.closed_at > 0 AND c.status <> 'cancelled'");
        $closed=$db->sql_fetchrow($r); $db->sql_freeresult($r);
        return ['STAT_OPENED'=>(int)($totals['opened'] ?? 0),'STAT_ACTIVE'=>(int)($totals['active'] ?? 0),'STAT_OVERDUE'=>(int)($totals['overdue'] ?? 0),'STAT_CLOSED'=>(int)($closed['total'] ?? 0)];
    }
}
