<?php
namespace uii\portal\migrations;

class v015 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v014']; }
    public function update_schema()
    {
        return ['add_tables'=>[$this->table_prefix.'uii_case_submissions'=>[
            'COLUMNS'=>[
                'request_key'=>['VCHAR:64',''],
                'author_id'=>['UINT',0],
                'kind'=>['VCHAR:20',''],
                'claimed'=>['BOOL',0],
                'case_id'=>['UINT',0],
                'expires_at'=>['TIMESTAMP',0],
            ],
            'PRIMARY_KEY'=>'request_key',
            'KEYS'=>['expires'=>['INDEX','expires_at']],
        ]]];
    }
    public function update_data()
    {
        return [
            ['config.add',['uii_case_forms_ready',1]],
            ['config.update',['max_filesize',5242880]],
            ['config.update',['uii_timer_start','notification']],
            ['custom',[[$this,'imageLimits']]],
        ];
    }
    public function imageLimits()
    {
        $this->db->sql_query('UPDATE '.$this->table_prefix.'extension_groups SET max_filesize = 5242880 WHERE cat_id = '.ATTACHMENT_CATEGORY_IMAGE);
    }
    // No destructive revert: existing cases, files and submission records are preserved.
}
