<?php
namespace uii\portal\migrations;
class v005 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v004']; }
    public function update_schema()
    {
        return ['add_index'=>[$this->table_prefix.'uii_cases'=>[
            'kind_closed_updated'=>['kind','closed_at','updated_at'],
            'author_updated'=>['author_id','updated_at'],
        ]]];
    }
    public function update_data()
    {
        return [
            ['config.update',['allow_birthdays',0]],['config.update',['load_birthdays',0]],
            ['config.update',['load_online',0]],['config.update',['feed_enable',0]],
            ['config.update',['attachment_quota',20971520]],['config.update',['max_filesize',262144]],
            ['config.update',['max_attachments',3]],['config.update',['avatar_filesize',262144]],
        ];
    }
}
