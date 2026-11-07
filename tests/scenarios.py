"""Интеграционные сценарии. Создают явно помеченные QA-аккаунт и дела."""
import os,time,re,sys
from http_workflows import Client,BASE
admin=Client();admin.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
def submit(client,path,values):
 _,body=client.get(path);data=client.fields(body);data.update(values);time.sleep(2)
 return client.post(path,data)
def healthy(body):
 assert 'Fatal error' not in body and 'SQL ERROR' not in body, re.sub('<[^>]+>','',body)[:1200]
# Уникальный пользователь; пароль остаётся только в памяти теста.
nickname='Qatest_'+''.join(chr(97+int(c)) for c in str(int(time.time()))[-7:]).capitalize()
_,txt=submit(admin,'/app.php/uii/employees',{'manage':'1','action':'create','role':'1','nickname':nickname,'position':'QA - тестовый сотрудник','gender':'male','user_id':'0'})
assert admin.last_headers.get('Cache-Control')=='no-store, private'
assert 'attachment' in admin.last_headers.get('Content-Disposition','')
assert 'Пароль: ' in txt,re.sub('<[^>]+>','',txt)[-1600:]
password=re.search(r'Пароль: (.+)',txt).group(1).strip()
print('PASS account creation and TXT')
staff=Client();staff.login(nickname,password)
u,b=staff.get('/app.php/uii/cases');assert '/uii/password' in u,(u,b[-500:]);print('PASS mandatory password change')
u,b=submit(staff,'/app.php/uii/password',{'save':'1','old_password':password,'new_password':'Qa_Strong!2026_Permanent','confirm_password':'Qa_Strong!2026_Permanent'})
assert '/uii/tasks' in u,(u,re.sub('<[^>]+>','',b)[-1000:]);print('PASS password change')
# Попытка обычного сотрудника создать руководящий аккаунт.
_,b=submit(staff,'/app.php/uii/employees',{'manage':'1','action':'create','role':'4','nickname':'Illegal_Admin','user_id':'0'})
assert 'Нет прав' in b;print('PASS account authorization')
# Создаём жалобу и проверяем сохранение и автоматический статус.
u,b=submit(staff,'/app.php/uii/new?kind=complaint',{'save':'1','title':'QA: тестовая жалоба','subject':'Test_Subject','description':'Тестовое описание','basis':'QA-проверка','applicant':'Test_Applicant','protocol':'Предварительный тестовый протокол','action':'save','version':'0'})
assert re.search(r'/uii/cases/\d+',u),(u,re.sub('<[^>]+>','',b)[-1800:]);case=int(re.search(r'/uii/cases/(\d+)',u).group(1));assert 'В работе' in b;print('PASS complaint creation, protocol and status',case)
# Проверка запрета закрытия рядовым сотрудником.
_,b=submit(staff,f'/app.php/uii/edit/{case}',{'save':'1','title':'QA: тестовая жалоба','subject':'Test_Subject','description':'Тестовое описание','basis':'QA-проверка','applicant':'Test_Applicant','protocol':'Протокол','action':'close','version':'1','document_url':'https://example.test/proof','decision':'Нарушение не установлено','violations':'Не установлены'})
assert 'Закрытие жалоб доступно руководителю' in b;print('PASS close authorization')
u,b=submit(admin,f'/app.php/uii/edit/{case}',{'save':'1','title':'QA: тестовая жалоба','subject':'Test_Subject','description':'Тестовое описание','basis':'QA-проверка','applicant':'Test_Applicant','protocol':'Протокол','action':'close','version':'1','document_url':'https://example.test/proof','decision':'Нарушение не установлено','violations':'Не установлены'})
assert '/uii/cases/' in u and 'Завершено' in b,(u,re.sub('<[^>]+>','',b)[-1600:]);print('PASS complaint close and archive')
print('QA nickname:',nickname)
