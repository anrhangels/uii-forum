"""Execute the statistics service's actual SQL against isolated relational fixtures."""
import json
from pathlib import Path
import sqlite3
import subprocess

root=Path(__file__).resolve().parents[1]
code=r'''require 'app/ext/uii/portal/service/statistics.php';
$db=new class {public $queries=[];function sql_query($sql){$this->queries[]=$sql;return 1;}function sql_fetchrow($r){return [];}function sql_freeresult($r){}};
\uii\portal\service\statistics::employee($db,'test_',7,1000);echo json_encode($db->queries);'''
queries=json.loads(subprocess.check_output(['bash','scripts/php-local.sh','-r',code],cwd=root,text=True))
db=sqlite3.connect(':memory:')
db.executescript('''CREATE TABLE test_uii_cases (case_id INT PRIMARY KEY, author_id INT, assigned_id INT, closed_at INT, deadline_at INT, answered_at INT, status TEXT);
CREATE TABLE test_uii_history (case_id INT, actor_id INT, action TEXT);
INSERT INTO test_uii_cases VALUES
(1,7,8,200,0,0,'closed'), (2,8,7,200,0,0,'closed'),
(3,7,7,0,1000,0,'waiting'), (4,8,7,0,1001,0,'waiting'),
(5,7,7,0,900,950,'answered_late'), (6,7,7,200,0,0,'cancelled'),
(7,8,9,200,0,0,'closed');
INSERT INTO test_uii_history VALUES
(1,7,'close'), (1,7,'close'), (2,8,'close'), (6,7,'close'), (7,7,'save'), (999,7,'close');''')
opened,active,overdue=db.execute(queries[0]).fetchone()
closed=db.execute(queries[1]).fetchone()[0]
assert (opened,active,overdue,closed)==(4,3,1,1),(opened,active,overdue,closed)
print('PASS statistics: creator, actual closer, distinct closes, assigned work, exact overdue boundary, answered/cancelled/missing cases')
db.execute('DELETE FROM test_uii_cases')
assert db.execute(queries[1]).fetchone()[0]==0
print('PASS empty statistics dataset')
# Validate the actual migration's index columns against these same relational fixtures.
code=r'''require 'app/phpbb/db/migration/migration_interface.php';require 'app/phpbb/db/migration/migration.php';require 'app/ext/uii/portal/migrations/v013.php';
$reflection=new ReflectionClass(\uii\portal\migrations\v013::class);$migration=$reflection->newInstanceWithoutConstructor();$property=$reflection->getProperty('table_prefix');$property->setAccessible(true);$property->setValue($migration,'test_');echo json_encode($migration->update_schema());'''
schema=json.loads(subprocess.check_output(['bash','scripts/php-local.sh','-r',code],cwd=root,text=True))
for table,indexes in schema['add_index'].items():
    for name,columns in indexes.items():
        assert isinstance(columns,list) and all(isinstance(column,str) for column in columns)
        db.execute(f'CREATE INDEX {name} ON {table} ({",".join(columns)})')
print('PASS migration v013 index definitions match actual table columns')
