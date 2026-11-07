"""Реальные четыре роли. Пароли остаются в памяти; тестовые записи помечены Qatest_."""
import os,time,re,subprocess,urllib.error,json
from http_workflows import Client
root=Client();root.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
_,settings_before=root.get('/app.php/uii/settings');initial_timer='publication' if re.search(r'value="publication"\s+selected',settings_before) else 'notification'
def send(c,path,data):
 _,b=c.get(path);d=c.fields(b);d.update(data);time.sleep(2);return c.post(path,d)
def allowed(c,path):
 try:
  u,b=c.get(path)
  assert 'Fatal error' not in b and 'SQL ERROR' not in b
  return True,b
 except urllib.error.HTTPError as e:
  assert e.code==403,(path,e.code);return False,''
def uid(name):return int(subprocess.check_output(['bash','scripts/php-local.sh','scripts/php/qa-fixture.php','user',name]).decode())
clients={};ids={}
suffix=''.join(chr(97+int(c)) for c in str(int(time.time()))[-6:])
for role in [1,2,3,4]:
 name='Qatest_'+chr(64+role)+suffix
 _,txt=send(root,'/app.php/uii/employees',{'manage':1,'action':'create','user_id':0,'nickname':name,'position':'QA - матрица ролей','role':role,'gender':'male' if role%2 else 'female'})
 assert 'Пароль: ' in txt,re.sub('<[^>]+>','',txt)[-900:]
 temp=re.search(r'Пароль: (.+)',txt).group(1).strip();c=Client();c.login(name,temp)
 u,_=c.get('/app.php/uii/cases?mode=logout');assert '/uii/password' in u,'Forced password bypass by fake logout'
 _,b=send(c,'/app.php/uii/password',{'save':1,'old_password':temp,'new_password':'Qa_Matrix!2026_Permanent','confirm_password':'Qa_Matrix!2026_Permanent'})
 clients[role]=c;ids[role]=uid(name)
 for path,minimum in [('/app.php/uii/admin',2),('/app.php/uii/activity',3)]:
  result,_=allowed(c,path);assert result==(role>=minimum),(role,path,result)
 if role<4:assert not allowed(c,'/mcp.php')[0]
 for path in ['/ucp.php?i=pm','/ucp.php?i=ucp_notifications&mode=notification_list','/ucp.php?i=profile&mode=avatar']:
  result,_=allowed(c,path);assert not result,(role,path)
 u,b=c.get('/ucp.php?i=ucp_prefs&mode=personal');assert '/uii/account' in u and 'Личные сообщения' not in b
 _,b=send(c,'/app.php/uii/settings',{'save':1,'timer_start':initial_timer})
 assert ('Настройка сохранена' in b)==(role>=3),(role,'settings rights')
 print('PASS actual role routes, disabled features and settings',role,flush=True)
# Начальник/заместитель не могут повысить сотрудника и управлять руководством.
for role in [2,3]:
 for action,target,newrole in [('update',ids[1],4),('reset',ids[4],1),('update',ids[role],4)]:
  _,b=send(clients[role],'/app.php/uii/employees',{'manage':1,'action':action,'user_id':target,'role':newrole,'position':'QA','gender':'male'})
  assert 'Недопустимая операция' in b or 'собственной учётной записи' in b,(role,action)
 print('PASS cross-role and self promotion prohibited',role,flush=True)
# Роль 4 управляет сотрудником, но системная ACP по-прежнему требует штатного a_.
_,b=send(clients[4],'/app.php/uii/employees',{'manage':1,'action':'update','user_id':ids[1],'role':1,'position':'QA - подтверждено руководством','gender':'male'})
assert 'Изменения сохранены' in b;print('PASS faction leadership manages staff',flush=True)
# Даже ошибочно выданные a_board и m_uii_manage не превращают сотрудника в руководство.
try:
 subprocess.run(['bash','scripts/php-local.sh','scripts/php/qa-permissions.php',str(ids[1]),'grant'],check=True)
 staff=Client();name='Qatest_A'+suffix;staff.login(name,'Qa_Matrix!2026_Permanent')
 assert not allowed(staff,'/app.php/uii/admin')[0]
 assert not allowed(staff,'/app.php/uii/activity')[0]
 _,account=staff.get('/app.php/uii/account');sid=re.search(r'mode=logout[^\"]*?sid=([a-f0-9]+)',account).group(1)
 assert not allowed(staff,'/adm/index.php?sid='+sid)[0]
 fixture=json.loads(subprocess.check_output(['bash','scripts/php-local.sh','scripts/php/qa-fixture.php','foreign-case',str(ids[1])]))
 assert not allowed(staff,'/app.php/uii/cases/'+str(fixture['case_id']))[0]
 assert not allowed(staff,'/viewtopic.php?t='+str(fixture['topic_id']))[0]
 u,b=send(staff,'/app.php/uii/new?kind=complaint',{'save':1,'version':0,'action':'save','title':'QA: forged assignee','applicant':'Test_Applicant','subject':'Test_Subject','description':'QA permissions','basis':'QA','assigned_id':ids[4]})
 assert '/uii/cases/' in u and 'Ответственный</small><strong>'+name+'</strong>' in b
 print('PASS unrelated native admin/moderator flags cannot elevate UII role or expose foreign cases; forged assignment ignored',flush=True)
finally:subprocess.run(['bash','scripts/php-local.sh','scripts/php/qa-permissions.php',str(ids[1]),'clear'],check=True)
