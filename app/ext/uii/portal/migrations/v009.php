<?php
namespace uii\portal\migrations;
class v009 extends \phpbb\db\migration\migration
{
    public static function depends_on(){return ['\\uii\\portal\\migrations\\v008'];}
    public function update_schema(){return ['add_index'=>[$this->table_prefix.'uii_cases'=>['activity_created'=>['created_at'],'activity_closed'=>['closed_at']]]];}
}
