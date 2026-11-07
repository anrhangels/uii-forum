<?php
namespace uii\portal\migrations;
class v002 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v001']; }
    public function update_schema()
    {
        return ['add_columns'=>[$this->table_prefix.'users'=>[
            'uii_position'=>['VCHAR_UNI:100',''],
            'uii_force_password'=>['BOOL',0],
            'uii_blocked'=>['BOOL',0],
        ]]];
    }
    public function update_data()
    {
        return [['custom', [[$this,'groups']]]];
    }
    public function groups()
    {
        global $phpbb_root_path,$phpEx;
        require_once $phpbb_root_path.'includes/acp/auth.'.$phpEx;
        require_once $phpbb_root_path.'includes/functions_user.'.$phpEx;
        foreach (['UII_FACTION','UII_CHIEF','UII_DEPUTY','UII_STAFF'] as $name) {
            $r=$this->db->sql_query("SELECT group_id FROM ".$this->table_prefix."groups WHERE group_name = '".$name."'");
            $existing=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
            if (!$existing) {
                $id=0; $error=group_create($id,GROUP_HIDDEN,$name,'Служебная роль УИИ',[]);
                if ($error) { throw new \RuntimeException('Не удалось создать группу '.$name); }
            }
        }
        $auth=new \auth_admin();
        $r=$this->db->sql_query("SELECT group_id, group_name FROM ".$this->table_prefix."groups WHERE group_name ".$this->db->sql_like_expression('UII'.$this->db->get_any_char()));
        while ($group=$this->db->sql_fetchrow($r)) {
            $permissions=['u_uii_use'=>ACL_YES,'u_chgpasswd'=>ACL_YES];
            if ($group['group_name']!=='UII_STAFF') { $permissions['m_uii_manage']=ACL_YES; }
            $auth->acl_set('group',0,(int)$group['group_id'],$permissions);
        }
        $this->db->sql_freeresult($r);
        $auth->acl_clear_prefetch();
    }
}
