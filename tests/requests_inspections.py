import os,time,re,subprocess
from http_workflows import Client
c=Client();c.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
def post(path,data):
 _,b=c.get(path);d=c.fields(b);d.update(data);time.sleep(2)
 u,b=c.post(path,d)
 assert 'Fatal error' not in b and 'SQL ERROR' not in b,re.sub('<[^>]+>','',b)[-1200:]
 return u,b
def case_id(u,b):
 m=re.search(r'/uii/cases/(\d+)',u)
 assert m,(u,re.sub('<[^>]+>','',b)[-1400:]);return int(m.group(1))
req={'save':1,'title':'QA: служебный запрос','subject':'Test_Recipient','description':'Тестовые материалы','basis':'QA-проверка','document_url':'https://example.test/request','notification_url':'https://example.test/notified','action':'notify','version':0}
u,b=post('/app.php/uii/new?kind=request',req);n=case_id(u,b);assert 'Ожидает ответа' in b;print('PASS request and 48h start',n)
subprocess.run(['bash','scripts/php-local.sh','scripts/php/qa-fixture.php','overdue',str(n)],check=True)
_,b=c.get(f'/app.php/uii/cases/{n}');assert 'Срок истёк' in b;print('PASS overdue at server read')
req.update(version=1,action='save',answer_url='https://example.test/late-answer')
u,b=post(f'/app.php/uii/edit/{n}',req);case_id(u,b);assert 'Ответ получен с опозданием' in b;print('PASS late answer')
req.update(version=2,action='close');u,b=post(f'/app.php/uii/edit/{n}',req);assert 'Завершено' in b;print('PASS request completion')
ins={'save':1,'title':'QA: проверка состава','subject':'Test_Employee','description':'Тестовая проверка','basis':'QA','inspection_type':'плановая','action':'start','version':0,'start_url':'https://example.test/start'}
u,b=post('/app.php/uii/new?kind=inspection',ins);n=case_id(u,b);assert 'В работе' in b;print('PASS inspection start',n)
ins.update(version=1,action='close',end_url='https://example.test/end',document_url='https://example.test/document',decision='Нарушение не установлено',violations='Не установлены')
u,b=post(f'/app.php/uii/edit/{n}',ins);assert 'Завершено' in b;print('PASS inspection completion')
_,b=c.get('/app.php/uii/cases?kind=inspection&archive=1&q=QA');assert 'QA:' in b;print('PASS archive search')
# Просроченный запрос без ответа закрывает руководитель с основанием.
req={'save':1,'title':'QA: запрос без ответа','subject':'Test_Recipient','description':'Нет ответа','basis':'QA','document_url':'https://example.test/request','notification_url':'https://example.test/notified','action':'notify','version':0}
u,b=post('/app.php/uii/new?kind=request',req);n=case_id(u,b)
subprocess.run(['bash','scripts/php-local.sh','scripts/php/qa-fixture.php','overdue',str(n)],check=True)
req.update(version=1,action='close',completion_note='QA: материалы проверены руководителем, ответ не поступил')
u,b=post(f'/app.php/uii/edit/{n}',req);assert 'Завершено' in b and 'Ответ не поступил' in b;print('PASS manager closes overdue request without answer and preserves outcome')
