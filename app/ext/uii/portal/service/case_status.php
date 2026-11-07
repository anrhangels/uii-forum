<?php
namespace uii\portal\service;

/** Shared presentation for every case badge; never changes stored workflow state. */
final class case_status
{
    public static function describe(array $row, int $now): array
    {
        $state=$row['status'] ?? 'created';
        if ($state!=='cancelled') {
            if (!empty($row['closed_at'])) { $state='closed'; }
            elseif (($row['kind'] ?? '')==='request' && !empty($row['deadline_at'])) {
                $state=!empty($row['answered_at']) ? 'in_progress' : \uii\portal\domain\workflow::requestState((int)$row['deadline_at'],0,false,$now);
            }
        }
        if (($row['kind'] ?? '')==='request' && empty($row['closed_at']) && in_array($state,['answered_on_time','answered_late'],true)) { $state='in_progress'; }
        $labels=['created'=>'Создано','planned'=>'Запланирована','in_progress'=>'В работе','waiting'=>'Ожидает ответа','overdue'=>'Срок истёк','answered_on_time'=>'Ответ получен вовремя','answered_late'=>'Ответ получен с опозданием','closed'=>'Завершено','cancelled'=>'Отменена'];
        $tones=['created'=>'created','planned'=>'planned','in_progress'=>'progress','waiting'=>'waiting','overdue'=>'overdue','answered_on_time'=>'answered','answered_late'=>'late','closed'=>'closed','cancelled'=>'cancelled'];
        $tone=$tones[$state] ?? 'neutral';
        if ($state==='waiting' && (int)$row['deadline_at']-$now<=6*3600) { $tone='urgent'; }
        return ['label'=>$labels[$state] ?? $state,'tone'=>$tone,'visible'=>(($row['kind'] ?? '')!=='inspection' || !in_array($state,['created','planned','in_progress'],true))];
    }
}
