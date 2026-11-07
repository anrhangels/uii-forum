<?php
namespace uii\portal\migrations;
class v016 extends \phpbb\db\migration\migration
{
    public static function depends_on() {return ['\\uii\\portal\\migrations\\v015'];}
    public function update_schema()
    {
        return ['add_tables'=>[
            $this->table_prefix.'uii_bug_reports'=>[
                'COLUMNS'=>['report_id'=>['UINT',null,'auto_increment'],'user_id'=>['UINT',0],'title'=>['VCHAR_UNI:150',''],'category'=>['VCHAR:20','other'],'description'=>['MTEXT_UNI',''],'reproduction_steps'=>['MTEXT_UNI',''],'expected_behavior'=>['MTEXT_UNI',''],'evidence_url'=>['VCHAR_UNI:2048',''],'status'=>['VCHAR:20','new'],'created_at'=>['TIMESTAMP',0],'updated_at'=>['TIMESTAMP',0],'version'=>['UINT',1],'submission_key'=>['VCHAR:64','']],
                'PRIMARY_KEY'=>'report_id','KEYS'=>['submission'=>['UNIQUE','submission_key'],'created'=>['INDEX',['created_at','report_id']],'status_date'=>['INDEX',['status','created_at','report_id']],'author_date'=>['INDEX',['user_id','created_at']]],
            ],
            $this->table_prefix.'uii_bug_submit_state'=>['COLUMNS'=>['user_id'=>['UINT',0],'version'=>['UINT',0]],'PRIMARY_KEY'=>'user_id'],
        ]];
    }
    public function update_data()
    {
        $data=[['permission.add',['u_uii_bug_report']],['permission.add',['a_uii_bug_reports']]];
        foreach (array_keys(\uii\portal\service\roles::LEVELS) as $group) {$data[]=['permission.permission_set',[$group,'u_uii_bug_report','group',true]];}
        $data[]=['config.add',['uii_bug_reports_ready',1]];$data[]=['custom',[[$this,'menu']]];return $data;
    }
    public function menu()
    {
        // Preserve the administrator's layout, adding the two new items exactly once.
        $r=$this->db->sql_query('SELECT payload FROM '.$this->table_prefix.'uii_navigation WHERE nav_id = 1');$row=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);
        $layout=$row ? json_decode($row['payload'],true) : null;
        if (!is_array($layout)) {$layout=\uii\portal\service\navigation::defaults();}
        foreach (['bug_report'=>'department','bug_admin'=>'management'] as $item=>$target) {
            $found=false;foreach ($layout as $group) {if (in_array($item,$group['items'] ?? [],true)) {$found=true;}}
            if ($found) {continue;}$index=0;foreach ($layout as $i=>$group) {if (($group['id'] ?? '')===$target) {$index=$i;break;}}$layout[$index]['items'][]=$item;
        }
        $this->db->sql_query('UPDATE '.$this->table_prefix.'uii_navigation SET version = version + 1, payload = \''.$this->db->sql_escape(json_encode($layout,JSON_UNESCAPED_UNICODE)).'\' WHERE nav_id = 1');
    }
    // Reports are retained when the extension is disabled; there is no destructive down migration.
}
