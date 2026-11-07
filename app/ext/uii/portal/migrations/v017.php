<?php
namespace uii\portal\migrations;
class v017 extends \phpbb\db\migration\migration
{
    public static function depends_on() {return ['\\uii\\portal\\migrations\\v016'];}
    public function update_data() {return [['permission.add',['a_uii_updates']],['config.add',['uii_updates_ready',1]],['custom',[[$this,'menu']]]];}
    public function menu()
    {
        $r=$this->db->sql_query('SELECT payload FROM '.$this->table_prefix.'uii_navigation WHERE nav_id = 1');$row=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);$layout=$row ? json_decode($row['payload'],true) : null;
        if (!is_array($layout)) {$layout=\uii\portal\service\navigation::defaults();}
        foreach ($layout as $group) {if (in_array('updates',$group['items'] ?? [],true)) {return;}}
        $index=0;foreach ($layout as $i=>$group) {if (($group['id'] ?? '')==='management') {$index=$i;break;}}$layout[$index]['items'][]='updates';
        $this->db->sql_query('UPDATE '.$this->table_prefix.'uii_navigation SET version=version+1,payload=\''.$this->db->sql_escape(json_encode($layout,JSON_UNESCAPED_UNICODE)).'\' WHERE nav_id=1');
    }
}
