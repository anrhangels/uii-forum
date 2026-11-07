<?php
namespace uii\portal\migrations;
class v014 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v013']; }
    public function update_schema()
    {
        return [
            'add_columns'=>[$this->table_prefix.'users'=>['uii_block_reason'=>['VCHAR:20',''],'uii_block_note'=>['VCHAR_UNI:255','']]],
            'add_tables'=>[$this->table_prefix.'uii_navigation'=>[
                'COLUMNS'=>['nav_id'=>['UINT',1],'version'=>['UINT',1],'payload'=>['MTEXT_UNI','']],
                'PRIMARY_KEY'=>'nav_id',
            ]],
        ];
    }
    public function update_data()
    {
        return [['custom',[[$this,'initialize']]],['config.add',['uii_navigation_ready',1]],['custom',[[$this,'trimAcp']]]];
    }
    public function initialize()
    {
        $r=$this->db->sql_query('SELECT nav_id FROM '.$this->table_prefix.'uii_navigation WHERE nav_id = 1');
        $exists=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);
        if (!$exists) { $this->db->sql_query('INSERT INTO '.$this->table_prefix.'uii_navigation '.$this->db->sql_build_array('INSERT',['nav_id'=>1,'version'=>1,'payload'=>json_encode(\uii\portal\service\navigation::defaults(),JSON_UNESCAPED_UNICODE)])); }
    }
    public function trimAcp()
    {
        foreach (\uii\portal\service\acp_policy::UNUSED as $module=>$modes) {
            $where="module_class = 'acp' AND module_basename = '".$this->db->sql_escape($module)."'";
            if ($modes!==null) { $where.=' AND '.$this->db->sql_in_set('module_mode',$modes); }
            $this->db->sql_query('UPDATE '.$this->table_prefix.'modules SET module_enabled = 0, module_display = 0 WHERE '.$where);
        }
        global $cache;
        $cache->destroy('_modules_acp');
    }
}
