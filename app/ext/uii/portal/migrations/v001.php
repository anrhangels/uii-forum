<?php
namespace uii\portal\migrations;

class v001 extends \phpbb\db\migration\migration
{
    public function update_schema()
    {
        return ['add_tables' => [
            $this->table_prefix . 'uii_cases' => [
                'COLUMNS' => [
                    'case_id' => ['UINT', null, 'auto_increment'],
                    'topic_id' => ['UINT', 0],
                    'kind' => ['VCHAR:20', 'complaint'],
                    'title' => ['VCHAR:255', ''],
                    'author_id' => ['UINT', 0],
                    'assigned_id' => ['UINT', 0],
                    'status' => ['VCHAR:30', 'created'],
                    'created_at' => ['TIMESTAMP', 0],
                    'updated_at' => ['TIMESTAMP', 0],
                    'started_at' => ['TIMESTAMP', 0],
                    'deadline_at' => ['TIMESTAMP', 0],
                    'answered_at' => ['TIMESTAMP', 0],
                    'closed_at' => ['TIMESTAMP', 0],
                    'version' => ['UINT', 1],
                    'payload' => ['MTEXT_UNI', ''],
                ],
                'PRIMARY_KEY' => 'case_id',
                'KEYS' => ['assigned' => ['INDEX', ['assigned_id', 'status']], 'deadline' => ['INDEX', 'deadline_at'], 'topic' => ['INDEX', 'topic_id']],
            ],
            $this->table_prefix . 'uii_history' => [
                'COLUMNS' => [
                    'history_id' => ['UINT', null, 'auto_increment'],
                    'case_id' => ['UINT', 0],
                    'actor_id' => ['UINT', 0],
                    'created_at' => ['TIMESTAMP', 0],
                    'action' => ['VCHAR:50', ''],
                    'details' => ['MTEXT_UNI', ''],
                ],
                'PRIMARY_KEY' => 'history_id',
                'KEYS' => ['case_time' => ['INDEX', ['case_id', 'created_at']]],
            ],
        ]];
    }
    public function update_data()
    {
        return [
            ['permission.add', ['u_uii_use']],
            ['permission.add', ['m_uii_manage']],
            ['permission.add', ['a_uii_accounts']],
            ['config.add', ['uii_timer_start', 'notification']],
            ['config.update', ['require_activation', 3]],
            ['config.update', ['allow_sig', 0]],
        ];
    }
    // Архивы дел сохраняются при отключении и удалении данных расширения.
}
