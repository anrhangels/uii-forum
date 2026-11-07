<?php
namespace uii\portal\service;
final class topics
{
    public static function create(int $forumId,int $caseId,string $title,string $caseUrl): int
    {
        global $phpbb_root_path,$phpEx,$auth,$db;
        if (!$auth->acl_get('f_post',$forumId)) { throw new \InvalidArgumentException('Нет права создания темы в служебном разделе.'); }
        require_once $phpbb_root_path.'includes/functions_posting.'.$phpEx;
        $message='Структурированное дело №'.$caseId."\nКарточка: ".$caseUrl."\nОсновные материалы редактируются в карточке дела.";
        $r=$db->sql_query('SELECT forum_name FROM '.FORUMS_TABLE.' WHERE forum_id = '.$forumId);
        $forum=$db->sql_fetchrow($r); $db->sql_freeresult($r);
        if (!$forum) { throw new \InvalidArgumentException('Служебный раздел не настроен.'); }
        $data=['forum_id'=>$forumId,'forum_name'=>$forum['forum_name'],'topic_id'=>0,'icon_id'=>0,'enable_bbcode'=>false,'enable_smilies'=>false,'enable_urls'=>false,'enable_sig'=>false,'message'=>$message,'message_md5'=>md5($message),'bbcode_bitfield'=>'','bbcode_uid'=>'','post_edit_locked'=>0,'topic_title'=>$title,'topic_time_limit'=>0,'topic_status'=>ITEM_UNLOCKED,'notify'=>false,'notify_set'=>false,'enable_indexing'=>false,'attachment_data'=>[]];
        $poll=[];
        submit_post('post','Дело №'.$caseId.' · '.$title,'',POST_NORMAL,$poll,$data);
        return (int)$data['topic_id'];
    }
}
