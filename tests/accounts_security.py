import os,time,re,subprocess,urllib.error,json
from http_workflows import Client
admin=Client();admin.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
name=os.environ.get('UII_QA_USER','Qatest_Djabaec')
id=int(subprocess.check_output(['bash','scripts/php-local.sh','scripts/php/qa-fixture.php','user',name]).decode())
assert id>0
staff=Client();staff.login(name,'Qa_Strong!2026_Permanent')
def manage(action,role=1):
 _,b=admin.get('/app.php/uii/employees');d=admin.fields(b);d.update(manage=1,action=action,user_id=id,role=role,position="QA — тестовый сотрудник");time.sleep(2);return admin.post('/app.php/uii/employees',d)
_,b=manage('block');assert 'Изменения сохранены' in b
u,b=staff.get('/app.php/uii/tasks');assert 'mode=login' in u or 'заблокирован' in b;print('PASS block revokes existing session')
_,b=manage('restore');assert 'Изменения сохранены' in b
staff.login(name,'Qa_Strong!2026_Permanent');u,b=staff.get('/app.php/uii/tasks');assert 'mode=login' not in u;print('PASS account restoration')
# Прямые ссылки на чужую карточку и связанную форумную тему.
fixture=json.loads(subprocess.check_output(['bash','scripts/php-local.sh','scripts/php/qa-fixture.php','foreign-case',str(id)]))
assert fixture
for path in [f"/app.php/uii/cases/{fixture['case_id']}",f"/viewtopic.php?t={fixture['topic_id']}"]:
 try:
  _,b=staff.get(path);assert 'Нет доступа' in b,(path,b[-500:])
 except urllib.error.HTTPError as e:assert e.code==403
 print('PASS foreign case denied',path)
# Отправка без CSRF-токена не создаёт документ.
_,b=staff.post('/app.php/uii/new?kind=complaint',{'save':1,'title':'QA: invalid csrf'})
assert 'Срок действия формы истёк' in b;print('PASS CSRF enforcement')
_,txt=manage('reset');assert 'Пароль: ' in txt
newpass=re.search(r'Пароль: (.+)',txt).group(1).strip()
staff2=Client();staff2.login(name,newpass);u,b=staff2.get('/app.php/uii/tasks');assert '/uii/password' in u;print('PASS password reset and forced change')
# Возвращаем тестовый аккаунт в известное состояние.
_,b=staff2.get('/app.php/uii/password');d=staff2.fields(b);d.update(save=1,old_password=newpass,new_password='Qa_Strong!2026_Permanent',confirm_password='Qa_Strong!2026_Permanent');time.sleep(2)
u,b=staff2.post('/app.php/uii/password',d);assert '/uii/tasks' in u

for role in [2,3]:
 _,b=manage('update',role);assert 'Изменения сохранены' in b
 leader=Client();leader.login(name,'Qa_Strong!2026_Permanent')
 _,b=leader.get('/app.php/uii/employees');assert all('<option value="4"' not in options for options in re.findall(r'<select name="role">(.*?)</select>',b,re.S))
 d=leader.fields(b);d.update(manage=1,action='create',user_id=0,nickname='Illegal_Leader',role=4);time.sleep(2)
 _,b=leader.post('/app.php/uii/employees',d);assert 'Недопустимая операция' in b
 print('PASS leader cannot grant faction role',role)
_,b=manage('update',1);assert 'Изменения сохранены' in b
