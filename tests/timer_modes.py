import os,time,re
from http_workflows import Client
c=Client();c.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
def send(path,data):
 _,b=c.get(path);d=c.fields(b);d.update(data);time.sleep(2);return c.post(path,d)
try:
 _,b=send('/app.php/uii/settings',{'save':1,'timer_start':'publication'});assert 'Настройка сохранена' in b
 req={'save':1,'title':'QA: режим публикации','subject':'Test_Recipient','description':'Проверка настройки','basis':'QA','action':'notify','version':0,'document_url':'https://example.test/request','notification_url':'https://example.test/notified'}
 _,b=send('/app.php/uii/new?kind=request',req);assert 'доказательство события начала срока' in b;print('PASS publication mode requires publication evidence')
 req['publication_url']='https://example.test/publication';u,b=send('/app.php/uii/new?kind=request',req);m=re.search(r'/uii/cases/(\d+)',u);assert m and 'Ожидает ответа' in b;case=int(m.group(1));print('PASS publication starts deadline')
finally:
 _,b=send('/app.php/uii/settings',{'save':1,'timer_start':'notification'});assert 'Настройка сохранена' in b
_,b=c.get(f'/app.php/uii/cases/{case}');assert 'Ожидает ответа' in b;print('PASS changing policy preserves started request')
