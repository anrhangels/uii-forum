<?php
namespace uii\portal\migrations;
class v007 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v006']; }
    public function update_data() { return [['custom',[[$this,'leadership']]]]; }
    public function leadership()
    {
        global $phpbb_root_path,$phpEx;
        require_once $phpbb_root_path.'includes/functions_user.'.$phpEx;
        $r=$this->db->sql_query("SELECT group_id FROM ".$this->table_prefix."groups WHERE group_name = 'UII_FACTION'");
        $group=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);
        $r=$this->db->sql_query('SELECT user_id FROM '.$this->table_prefix.'users WHERE user_type = '.USER_FOUNDER);
        $ids=[];while($row=$this->db->sql_fetchrow($r)){$ids[]=(int)$row['user_id'];}$this->db->sql_freeresult($r);
        if ($group && $ids) { group_user_add((int)$group['group_id'],$ids); }
        $this->db->sql_query("UPDATE ".$this->table_prefix."users SET uii_position = 'Руководство фракции' WHERE user_type = ".USER_FOUNDER." AND uii_position = ''");
    }
}
