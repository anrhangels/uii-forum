<?php
namespace uii\portal\migrations;
class v013 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v012']; }
    public function update_schema()
    {
        return ['add_index'=>[
            $this->table_prefix.'uii_cases'=>['author'=>['author_id']],
            $this->table_prefix.'uii_history'=>['actor_action'=>['actor_id','action','case_id']],
        ]];
    }
}
