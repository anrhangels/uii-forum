"""Exercise actual staff queries against separate SQL fixtures, without portal data."""
from pathlib import Path
import json
import sqlite3
import subprocess

root=Path(__file__).resolve().parents[1]
code=r'''require 'app/ext/uii/portal/service/staff.php';$result=['source'=>\uii\portal\service\staff::source('test_',0,3),'filters'=>[]];foreach(\uii\portal\service\staff::FILTERS as $id=>$name)$result['filters'][$id]=\uii\portal\service\staff::condition($id,970);echo json_encode($result);'''
model=json.loads(subprocess.check_output(['bash','scripts/php-local.sh','-r',code],cwd=root,text=True))
db=sqlite3.connect(':memory:')
db.executescript('''
CREATE TABLE test_users(user_id INTEGER PRIMARY KEY, username TEXT, user_type INT, uii_blocked INT, uii_block_reason TEXT);
CREATE TABLE test_groups(group_id INT, group_name TEXT);
CREATE TABLE test_user_group(user_id INT, group_id INT, user_pending INT);
CREATE TABLE test_sessions(session_user_id INT, session_time INT, session_viewonline INT);
INSERT INTO test_users VALUES(1,'Founder',3,0,''),(2,'Alice',0,0,''),(3,'Bob',0,0,''),(4,'Carol',0,1,'transferred'),(5,'Dan',0,1,'left'),(6,'Notstaff',0,0,''),(7,'Pending',0,0,''),(8,'Bot',2,0,'');
INSERT INTO test_groups VALUES(1,'UII_STAFF'),(2,'UII_DEPUTY'),(3,'UII_CHIEF'),(4,'UII_FACTION'),(5,'UII_FAKE'),(6,'ADMINISTRATORS');
INSERT INTO test_user_group VALUES(2,1,0),(3,1,0),(3,2,0),(4,3,0),(5,1,0),(6,5,0),(6,6,0),(7,1,1),(8,4,0);
INSERT INTO test_sessions VALUES(1,1000,0),(2,960,1),(2,970,1),(2,1000,0),(3,969,1),(4,1000,1),(5,1000,1),(6,1000,1),(8,1000,1);
''')
source=model['source']
expected={'all':[1,2,3,4,5],'online':[2],'active':[1,2,3],'blocked':[4,5],'transferred':[4],'left':[5]}
for name,condition in model['filters'].items():
    query='SELECT u.user_id'+source['from']+' WHERE '+source['where']+' AND '+condition+' ORDER BY u.user_id'
    actual=[row[0] for row in db.execute(query)]
    assert actual==expected[name],(name,actual)
    print('PASS staff filter:',name)
role=db.execute('SELECT '+source['role']+source['from']+' WHERE u.user_id=3').fetchone()[0]
assert role==2
founder=db.execute('SELECT '+source['role']+source['from']+' WHERE u.user_id=1').fetchone()[0]
assert founder==4
matches=db.execute('SELECT u.user_id'+source['from']+' WHERE '+source['where']+" AND u.username LIKE '%aro%'").fetchall()
assert matches==[(4,)]
print('PASS highest real role, founder, pending/bot/fake groups excluded, visible-session cutoff, duplicate sessions and cross-page server search')
