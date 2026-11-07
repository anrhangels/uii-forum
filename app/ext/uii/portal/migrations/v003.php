<?php
namespace uii\portal\migrations;
class v003 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v002']; }
    public function update_data() { return [['custom',[[$this,'forums']]]]; }
    public function forums()
    {
        global $phpbb_root_path,$phpEx,$cache;
        require_once $phpbb_root_path.'includes/functions_acp.'.$phpEx;
        require_once $phpbb_root_path.'includes/acp/auth.'.$phpEx;
        require_once $phpbb_root_path.'includes/acp/acp_forums.'.$phpEx;
        require_once $phpbb_root_path.'includes/functions_admin.'.$phpEx;
        $acp=new \acp_forums();
        foreach (['complaint'=>'Документооборот УИИ','request'=>'Служебные запросы УИИ','inspection'=>'Проверки состава УИИ'] as $kind=>$title) {
            $key='uii_forum_'.$kind;
            if (!empty($this->config[$key])) { continue; }
            $r=$this->db->sql_query_limit('SELECT * FROM '.$this->table_prefix.'forums WHERE forum_type = '.FORUM_POST,1);
            $data=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
            if (!$data) { throw new \RuntimeException('Для первоначальной настройки нужен хотя бы один раздел phpBB.'); }
            unset($data['forum_id']);
            foreach (array_keys($data) as $field) {
                if (strpos($field,'forum_last_')===0 || strpos($field,'forum_posts_')===0 || strpos($field,'forum_topics_')===0) { $data[$field]=is_numeric($data[$field]) ? 0 : ''; }
            }
            $data=array_merge($data,['parent_id'=>0,'forum_name'=>$title,'forum_desc'=>'Внутренние RP-документы отдела УИИ','forum_rules'=>'','forum_password'=>'','forum_password_confirm'=>'','forum_password_unset'=>true,'forum_image'=>'','forum_link'=>'','forum_parents'=>'','forum_type'=>FORUM_POST,'forum_status'=>ITEM_UNLOCKED,'enable_indexing'=>0,'enable_icons'=>0,'enable_prune'=>0,'forum_link_track'=>0,'prune_old_polls'=>0,'prune_announce'=>0,'prune_sticky'=>0,'show_active'=>0,'enable_post_review'=>0,'enable_quick_reply'=>0]);
            $errors=$acp->update_forum_data($data);
            if ($errors) { throw new \RuntimeException(implode(', ',$errors)); }
            $this->config->set($key,(int)$data['forum_id']);
            $auth=new \auth_admin();
            $r=$this->db->sql_query("SELECT group_id FROM ".$this->table_prefix."groups WHERE group_name = 'ADMINISTRATORS' OR group_name ".$this->db->sql_like_expression('UII'.$this->db->get_any_char()));
            while ($g=$this->db->sql_fetchrow($r)) {
                $auth->acl_set('group',(int)$data['forum_id'],(int)$g['group_id'],['f_list'=>ACL_YES,'f_read'=>ACL_YES,'f_post'=>ACL_YES,'f_reply'=>ACL_YES,'f_noapprove'=>ACL_YES]);
            }
            $this->db->sql_freeresult($r);
            $auth->acl_clear_prefetch();
        }
        $cache->destroy('sql',FORUMS_TABLE);
    }
}
