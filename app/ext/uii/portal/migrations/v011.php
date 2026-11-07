<?php
namespace uii\portal\migrations;
class v011 extends \phpbb\db\migration\migration
{
    public static function depends_on(){return ['\\uii\\portal\\migrations\\v010'];}
    public function update_data(){return [['config.update',['allow_bookmarks',0]],['config.update',['allow_forum_notify',0]],['config.update',['allow_topic_notify',0]],['custom',[[$this,'typography']]]];}
    public function typography()
    {
        foreach(['sitename','site_desc'] as $key){$this->config->set($key,str_replace(['—','–'],'-',$this->config[$key]));}
    }
}
