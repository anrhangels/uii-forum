import os,time,re
from http_workflows import Client
c=Client();c.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
def send(path,values):
 _,b=c.get(path);d=c.fields(b);d.update(values);time.sleep(2);return c.post(path,d)
d={'save':1,'action':'save','version':0,'title':'QA: граничные проверки','subject':'Test_Subject','applicant':'Test_Applicant','description':'Проверка границ','basis':'QA','protocol_person':'Test_Witness','protocol_questions':'Что произошло?','protocol_answers':'Тестовый ответ'}
u,b=send('/app.php/uii/new?kind=complaint',d);m=re.search(r'/uii/cases/(\d+)',u);assert m,re.sub('<[^>]+>','',b)[-1400:];id=int(m.group(1));assert 'В работе' in b and 'Test_Witness' in b;print('PASS structured protocol fields')
d.update(version=1,action='close');_,b=send(f'/app.php/uii/edit/{id}',d);assert 'Для закрытия заполните' in b;print('PASS cannot close incomplete complaint')
d.update(action='save',document_url='javascript:alert(1)');_,b=send(f'/app.php/uii/edit/{id}',d);assert 'Укажите полную ссылку' in b;print('PASS unsafe evidence URL rejected')
d.update(document_url='https://example.test/proof',title='QA: <script>window.QA_XSS=1</script>');u,b=send(f'/app.php/uii/edit/{id}',d);assert '/uii/cases/' in u and '<script>window.QA_XSS=1</script>' not in b;print('PASS stored title safely escaped')
# d.version остаётся 1, текущая версия уже 2.
_,b=send(f'/app.php/uii/edit/{id}',d);assert 'уже изменено другим сотрудником' in b;print('PASS stale version rejected')
_,b=c.get('/app.php/uii/cases?q=%27%20OR%201%3D1%20--');assert 'SQL ERROR' not in b;print('PASS search treats SQL metacharacters as text')
