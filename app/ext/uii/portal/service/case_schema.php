<?php
namespace uii\portal\service;

/** Per-kind field contracts. Historical payload keys are never deleted. */
final class case_schema
{
    public static function fields(string $kind, bool $editing, bool $started, bool $manager): array
    {
        $base=[
            'complaint'=>[
                'applicant'=>['Заявитель','text',true],
                'subject'=>['Фигурант','text',true],
                'description'=>['Описание жалобы','textarea',true],
                'comment'=>['Комментарий','textarea',false],
            ],
            'request'=>[
                'subject'=>['Адресат запроса (игровой никнейм)','text',true],
                'description'=>['Запрашиваемые материалы','textarea',true],
                'comment'=>['Комментарий','textarea',false],
            ],
            'inspection'=>[
                'inspection_type'=>['Тип проверки','choice',true],
                'planned_at'=>['Дата и время проверки (МСК)','text',false],
                'description'=>['Описание проверки','textarea',true],
                'comment'=>['Комментарий','textarea',false],
            ],
        ][$kind] ?? [];
        if ($editing && $kind!=='request') {
            $base+=['decision'=>['Результат рассмотрения','textarea',false],'violations'=>['Нарушения либо «Не установлены»','textarea',false]];
            if ($kind==='complaint') { $base['punishment']=['Наказание, если назначено','textarea',false]; }
        }
        if ($editing && $kind==='request' && $started) {
            $base['answer_url']=['Ссылка на ответ','url',false];
            if ($manager) { $base['completion_note']=['Если ответ не поступил','textarea',false]; }
        }
        return $base;
    }
    public static function slots(string $kind): array
    {
        return [
            'complaint'=>['document_url'=>'Фото протокола жалобы','protocol'=>'Фото ДО'],
            'request'=>['document_url'=>'Скриншот запроса','notification_url'=>'Доказательство уведомления'],
            'inspection'=>['start_url'=>'Скриншот начала','document_url'=>'Протокол проверки','end_url'=>'Скриншот окончания'],
        ][$kind] ?? [];
    }
    public static function uploadSlots(string $kind, bool $creating = false): array
    {
        $slots=self::slots($kind);
        if ($creating && $kind==='complaint') { unset($slots['protocol']); }
        return $slots;
    }
    public static function visibleFields(string $kind): array
    {
        $fields=self::fields($kind,true,true,true);$labels=[];
        foreach ($fields as $key=>$definition) { $labels[$key]=$definition[0]; }
        // Old URL evidence remains readable; new forms never accept these URLs.
        foreach (self::slots($kind) as $key=>$label) {
            if (substr($key,-4)==='_url' && $key!=='answer_url') { $labels[$key]=$label; }
        }
        return $labels;
    }
    public static function actions(string $kind, bool $creating, bool $started): array
    {
        if ($creating) { return [$kind==='inspection' ? 'register' : 'save']; }
        if ($kind==='inspection') { return $started ? ['save','close','cancel'] : ['save','start','cancel']; }
        if ($kind==='request') { return $started ? ['save','close'] : ['save','notify']; }
        return ['save','close'];
    }
}
