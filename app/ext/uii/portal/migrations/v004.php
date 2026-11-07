<?php
namespace uii\portal\migrations;
class v004 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v003']; }
    public function update_data() { return [['custom',[[$this,'locale']]],['config.update',['default_lang','ru']],['config.update',['board_timezone','Europe/Moscow']]]; }
    public function locale()
    {
        $r=$this->db->sql_query("SELECT lang_id FROM ".$this->table_prefix."lang WHERE lang_iso = 'ru'");
        $exists=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);
        if (!$exists) {
            $this->db->sql_query('INSERT INTO '.$this->table_prefix.'lang '.$this->db->sql_build_array('INSERT',['lang_iso'=>'ru','lang_dir'=>'ru','lang_english_name'=>'Russian - UII Portal','lang_local_name'=>'Русский - УИИ (авторский, частичный)','lang_author'=>'UII Portal']));
        }
        $this->db->sql_query("UPDATE ".$this->table_prefix."users SET user_lang = 'ru' WHERE user_type IN (".USER_NORMAL.','.USER_FOUNDER.')');
    }
}
