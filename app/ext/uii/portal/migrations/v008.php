<?php
namespace uii\portal\migrations;
class v008 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v007']; }
    public function update_data() { return [['custom',[[$this,'dates']]],['config.update',['default_dateformat','d.m.Y H:i']]]; }
    public function dates()
    {
        $old=$this->config['default_dateformat'];
        $this->db->sql_query("UPDATE ".$this->table_prefix."users SET user_dateformat = 'd.m.Y H:i' WHERE user_dateformat = '".$this->db->sql_escape($old)."'");
    }
}
