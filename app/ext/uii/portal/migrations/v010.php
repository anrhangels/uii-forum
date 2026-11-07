<?php
namespace uii\portal\migrations;
class v010 extends \phpbb\db\migration\migration
{
    public static function depends_on(){return ['\\uii\\portal\\migrations\\v009'];}
    public function update_schema(){return ['add_columns'=>[$this->table_prefix.'users'=>['uii_gender'=>['VCHAR:12','unspecified']]]];}
    public function update_data()
    {
        return [
            ['config.update',['allow_privmsg',0]],['config.update',['load_notifications',0]],
            ['config.update',['allow_board_notifications',0]],['config.update',['allow_avatar',0]],
            ['config.update',['allow_avatar_upload',0]],['config.update',['allow_avatar_remote',0]],
            ['config.update',['allow_avatar_remote_upload',0]],['config.update',['allow_avatar_local',0]],
            ['config.update',['allow_avatar_gravatar',0]],
            ['custom',[[$this,'disableUnusedModules']]],
        ];
    }
    public function disableUnusedModules()
    {
        // Штатные таблицы и сохранённые данные остаются совместимы с обновлением phpBB.
        $this->db->sql_query("UPDATE ".$this->table_prefix."modules SET module_enabled=0, module_display=0 WHERE module_class='ucp' AND (module_basename IN ('ucp_pm','ucp_notifications','ucp_zebra','ucp_groups') OR module_langname IN ('UCP_PM','UCP_MAIN_NOTIFICATIONS','UCP_NOTIFICATION_OPTIONS','UCP_NOTIFICATION_LIST') OR (module_basename='ucp_profile' AND module_mode IN ('avatar','signature')))");
        global $cache;
        $cache->destroy('_modules_ucp');
    }
}
