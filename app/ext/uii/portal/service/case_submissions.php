<?php
namespace uii\portal\service;

/** Atomic claim in the same DB transaction as the case, topic and history. */
final class case_submissions
{
    private $db;
    private $table;
    public function __construct($db, string $prefix) { $this->db=$db;$this->table=$prefix.'uii_case_submissions'; }
    public function issue(int $author, string $kind, int $now): string
    {
        $this->db->sql_query('DELETE FROM '.$this->table.' WHERE expires_at < '.$now);
        $key=bin2hex(random_bytes(32));
        $this->db->sql_query('INSERT INTO '.$this->table.' '.$this->db->sql_build_array('INSERT',[
            'request_key'=>$key,'author_id'=>$author,'kind'=>$kind,'claimed'=>0,'case_id'=>0,'expires_at'=>$now+86400,
        ]));
        return $key;
    }
    /** Returns the already-created case ID on replay, otherwise claims the ticket. */
    public function claim(string $key, int $author, string $kind, int $now): int
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new \InvalidArgumentException('Форма создания устарела. Обновите страницу перед отправкой.'); }
        $where="request_key = '".$this->db->sql_escape($key)."' AND author_id = ".$author." AND kind = '".$this->db->sql_escape($kind)."' AND expires_at >= ".$now;
        $this->db->sql_query('UPDATE '.$this->table.' SET claimed = 1 WHERE '.$where.' AND claimed = 0');
        if ($this->db->sql_affectedrows()) { return 0; }
        $r=$this->db->sql_query('SELECT case_id FROM '.$this->table.' WHERE '.$where);
        $row=$this->db->sql_fetchrow($r);$this->db->sql_freeresult($r);
        if ($row && $row['case_id']>0) { return (int)$row['case_id']; }
        throw new \InvalidArgumentException('Срок действия формы истёк. Обновите страницу и выберите изображения повторно.');
    }
    public function complete(string $key, int $id): void
    {
        $this->db->sql_query('UPDATE '.$this->table.' SET case_id = '.$id." WHERE request_key = '".$this->db->sql_escape($key)."' AND claimed = 1");
    }
}
