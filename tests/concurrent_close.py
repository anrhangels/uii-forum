"""Two different authenticated users close one QA case concurrently; no load generation."""
import concurrent.futures,os,re,secrets,time,urllib.error
from http_workflows import Client
admin=Client();admin.login(os.environ['UII_TEST_USER'],os.environ['UII_TEST_PASSWORD'])
def send(client,path,values):
 _,body=client.get(path);data=client.fields(body);data.update(values);time.sleep(2.1);return client.post(path,data)
name='Qatest_Race'+''.join(chr(97+int(c,16)) for c in secrets.token_hex(3))
_,txt=send(admin,'/app.php/uii/employees',dict(manage=1,action='create',user_id=0,nickname=name,role=3,gender='male'))
temporary=re.search(r'Пароль: (.+)',txt).group(1).strip();other=Client();other.login(name,temporary);password=secrets.token_hex(12)
send(other,'/app.php/uii/password',dict(save=1,old_password=temporary,new_password=password,confirm_password=password))
values=dict(save=1,title='QA: одновременное закрытие',subject='Test_Subject',applicant='Test_Applicant',description='Проверка двух пользователей',basis='QA',protocol='QA: полный протокол',document_url='https://example.test/proof',decision='Не установлено',violations='Не установлены',action='save',version=0)
url,_=send(admin,'/app.php/uii/new?kind=complaint',values);case=int(re.search(r'/cases/(\d+)',url).group(1));path=f'/app.php/uii/edit/{case}'
forms=[]
for client in [admin,other]:
 _,body=client.get(path);data=client.fields(body);data.update(values,action='close',version=1);forms.append((client,data))
time.sleep(2.1)
def close(item):
 try:
  url,body=item[0].post(path,item[1]);return 'closed' if f'/uii/cases/{case}' in url else ('conflict' if 'Дело уже изменено' in body else 'unexpected')
 except urllib.error.HTTPError as error:
  if error.code==403 and 'Завершённое дело' in error.read().decode():return 'conflict'
  raise
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:result=list(pool.map(close,forms))
assert sorted(result)==['closed','conflict'],result
# Verify history, version and topic lock using a private CLI; never expose credentials.
import subprocess
check=r'''require 'runtime/board/config.php';$d=new mysqli($dbhost,$dbuser,$dbpasswd,$dbname);$id=(int)$argv[1];$r=$d->query("SELECT c.title,c.version,c.closed_at,t.topic_status,(SELECT COUNT(*) FROM {$table_prefix}uii_history h WHERE h.case_id=c.case_id AND h.action='close') AS closes FROM {$table_prefix}uii_cases c JOIN {$table_prefix}topics t ON t.topic_id=c.topic_id WHERE c.case_id=$id")->fetch_assoc();if(!$r||!str_starts_with($r['title'],'QA:')||$r['version']!=2||!$r['closed_at']||$r['closes']!=1||$r['topic_status']!=1)throw new RuntimeException('Concurrent state invalid');'''
subprocess.run(['bash','scripts/php-local.sh','-r',check,str(case)],check=True)
print('PASS two users close simultaneously: one success, one conflict, one close event, version 2 and locked discussion')
