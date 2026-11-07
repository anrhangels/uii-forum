<?php
namespace uii\portal\migrations;
class v006 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v005']; }
    public function update_data() { return [['config.update',['allow_cdn',0]]]; }
}
