<?php
namespace uii\portal\migrations;
class v012 extends \phpbb\db\migration\migration
{
    public static function depends_on() { return ['\\uii\\portal\\migrations\\v011']; }
    public function update_data() { return [['custom', [[$this, 'renameComplaints']]]]; }
    public function renameComplaints()
    {
        global $cache;
        $id=(int)($this->config['uii_forum_complaint'] ?? 0);
        if ($id) {
            $this->db->sql_query("UPDATE ".$this->table_prefix."forums SET forum_name = 'Жалобы УИИ' WHERE forum_id = ".$id." AND forum_name = 'Документооборот УИИ'");
            $cache->destroy('sql', $this->table_prefix.'forums');
        }
    }
}
