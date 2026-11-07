"""Isolated full-HTTP case workflows. Requires the dedicated SQLite sandbox, never production."""
import concurrent.futures,html,http.cookiejar,json,os,re,secrets,sqlite3,subprocess,time,urllib.error,urllib.parse,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SANDBOX=ROOT/'runtime/cases-v2-sandbox';BASE='http://127.0.0.1:8094';DB=SANDBOX/'private/database.sqlite3'
assert DB.is_file() and (SANDBOX/'private/account.json').is_file()
class Client:
 def __init__(self):self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def request(self,path,data=None,files=None):
  url=path if path.startswith('http') else BASE+path;headers={}
  if files is not None:
   boundary='uii-'+secrets.token_hex(16);parts=[]
   for key,value in (data or {}).items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
   for key,(name,content,mime) in files.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+content+b'\r\n')
   parts.append(f'--{boundary}--\r\n'.encode());body=b''.join(parts);headers['Content-Type']='multipart/form-data; boundary='+boundary
  else:body=urllib.parse.urlencode(data).encode() if data is not None else None
  try:
   response=self.opener.open(urllib.request.Request(url,data=body,headers=headers),timeout=30)
   content=response.read();code=response.status;address=response.url;self.headers=response.headers
  except urllib.error.HTTPError as e:content=e.read();code=e.code;address=e.url;self.headers=e.headers
  text=content.decode('utf-8',errors='replace')
  assert not any(x in text for x in ['Fatal error','SQL ERROR','Uncaught ']),text[:1500]
  return code,address,text,content
 def get(self,path):return self.request(path)
 def fields(self,body):
  fields={}
  for tag in re.findall(r'<input\b[^>]*>',body):
   attrs={k:html.unescape(v) for k,v in re.findall(r'([\w-]+)="([^"]*)"',tag)}
   if attrs.get('type')=='hidden' and 'name' in attrs:fields[attrs['name']]=attrs.get('value','')
  return fields
 def form(self,path):
  code,url,body,_=self.get(path);assert code==200,(code,body[:200]);return self.fields(body),body
 def send(self,path,values,files=None):
  fields,_=self.form(path);fields.update(values);time.sleep(1.1);return self.request(path,fields,files)
 def login(self,user,password):
  code,url,body,_=self.send('/ucp.php?mode=login',dict(username=user,password=password,login='Войти'))
  assert code==200 and 'id="login"' not in body and 'mode=login' not in url,(url,body[:500])
def query(sql,params=()):
 with sqlite3.connect(DB,timeout=20) as db:db.row_factory=sqlite3.Row;return [dict(r) for r in db.execute(sql,params).fetchall()]
def mutate_case(case,sql,params):
 assert query('SELECT title FROM qa_uii_cases WHERE case_id=?',(case,))[0]['title'].startswith('QA:')
 with sqlite3.connect(DB,timeout=20) as db:db.execute(sql,params)
def identity(user):return query('SELECT user_id FROM qa_users WHERE username=?',(user,))[0]['user_id']
account=json.loads((SANDBOX/'private/account.json').read_text());admin=Client();admin.login(account['username'],account['password']);admin_id=identity(account['username'])
# Generate real decodable images using the native GD used by the test server.
php=['bash','scripts/php-local.sh','-d','extension='+str(ROOT/'runtime/usr/lib/php/modules/gd.so')]
png=subprocess.check_output(php+['-r','$i=imagecreatetruecolor(64,48);imagefill($i,0,0,imagecolorallocate($i,35,80,130));imagepng($i);'],cwd=ROOT)
photo=lambda content=png,name='proof.png',mime='image/png':(name,content,mime)
results=[]
def passed(text):results.append(text);print('PASS',text,flush=True)
def create(kind,client=admin,files=None,**overrides):
 values=dict(save=1,action='register' if kind=='inspection' else 'save',title='QA: v2 '+kind+' '+secrets.token_hex(3),description='Тестовые материалы',subject='Test_Subject',applicant='Test_Applicant',inspection_type='плановая',assigned_id=999999,author_id=999999)
 values.update(overrides);code,url,body,_=client.send('/app.php/uii/new?kind='+kind,values,files)
 match=re.search(r'/uii/cases/(\d+)',url);assert code==200 and match,(code,url,re.sub('<[^>]+>','',body)[-1400:]);return int(match[1]),body,values
# Exact field separation and removal.
for kind,expected in [('request',{'subject','photo_document_url','photo_notification_url'}),('inspection',{'inspection_type','photo_start_url','photo_document_url','photo_end_url'}),('complaint',{'applicant','subject','photo_document_url'})]:
 _,body=admin.form('/app.php/uii/new?kind='+kind);names=set(re.findall(r'<(?:input|textarea|select)[^>]*name="([^"]+)"',body));assert expected<=names,(kind,names)
 assert not names & {'basis','publication_url','protocol','protocol_person','protocol_questions','protocol_answers','assigned_id','answer_url','completion_note','document_url','notification_url','start_url','end_url'}
 if kind=='inspection':assert 'subject' not in names and '<select name="action"' not in body and 'Зарегистрировать проверку' in body
passed('three independent forms; removed fields absent; response and final-stage fields hidden on create')
request,body,rv=create('request',files={'photo_document_url':photo(),'photo_notification_url':photo()})
row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(request,))[0];payload=json.loads(row['payload']);assert row['author_id']==row['assigned_id']==admin_id and len(payload['photos'])==2 and row['answered_at']==0
assert 'Фото ДО' not in body and 'publication_url' not in payload and 'basis' not in payload and 'Ответ получен вовремя' not in body
assert not re.search(r'<img[^>]*uii/photos',body);assert body.count('Открыть изображение')==2
passed('request creation with two images, clickable links only, forged author/assignee ignored')
# Read the actual private file and verify response authorization/cache/type.
link=html.unescape(re.search(r'<a href="([^"]*uii/images[^\"]+)"[^>]*>Открыть изображение',body).group(1));
viewer_code,_,viewer,_=admin.get(urllib.parse.urljoin(BASE+'/app.php/uii/cases/'+str(request),link));assert viewer_code==200 and '<main><img' in viewer;
link=html.unescape(re.search(r'<img src="([^"]+)"',viewer).group(1));code,_,_,content=admin.get(urllib.parse.urljoin(BASE+'/app.php/uii/cases/'+str(request),link));assert code==200 and content==png and 'no-store' in admin.headers['Cache-Control'];assert admin.headers['Content-Type'].startswith('image/png')
guest=Client();code,url,b,_=guest.get(urllib.parse.urljoin(BASE+'/app.php/uii/cases/'+str(request),link));assert code in (403,404) or ('mode=login' in url and content!=b.encode())
passed('private file MIME/no-store, guest denial and link opening')
# Notify, then finalize with an actual answer.
code,_,body,_=admin.send(f'/app.php/uii/edit/{request}',dict(rv,version=1,action='notify',document_url='https://attacker.invalid/not-used',publication_url='https://attacker.invalid/not-used'))
row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(request,))[0];assert row['deadline_at']-row['started_at']==172800 and row['answered_at']==0 and not row['closed_at']
_,form=admin.form(f'/app.php/uii/edit/{request}');assert 'name="answer_url"' in form and 'Если ответ не поступил' in form and 'Доказательство публикации' not in form
code,_,body,_=admin.send(f'/app.php/uii/edit/{request}',dict(rv,version=row['version'],action='save',answer_url='https://example.test/answer'))
assert 'Завершить запрос' in body and query('SELECT answered_at FROM qa_uii_cases WHERE case_id=?',(request,))[0]['answered_at']==0
code,_,body,_=admin.send(f'/app.php/uii/edit/{request}',dict(rv,version=row['version'],action='close',answer_url='https://example.test/answer'))
row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(request,))[0];assert row['closed_at'] and row['answered_at'] and 'Ответ получен вовремя' in body
passed('notification starts 48h; answer only at final stage; timely badge only on completed evidenced request')
# Inspection: no selected employee, one registration action, 3 photos, no planned badge.
inspection,body,iv=create('inspection',files={'photo_start_url':photo(),'photo_document_url':photo(),'photo_end_url':photo()})
row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(inspection,))[0];assert row['status']=='planned' and len(json.loads(row['payload'])['photos'])==3 and 'Запланирована' not in body and 'Проверяемый сотрудник' not in body
code,_,body,_=admin.send(f'/app.php/uii/edit/{inspection}',dict(iv,version=row['version'],action='start'));assert 'errorbox' not in body
row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(inspection,))[0];code,_,body,_=admin.send(f'/app.php/uii/edit/{inspection}',dict(iv,version=row['version'],action='close',decision='Проверка проведена',violations='Не установлены'));assert 'Завершено' in body
passed('inspection registered without employee/basis; three images; no decorative planned badge; start and close')
# Complaint evidence and final DO photo.
complaint,body,cv=create('complaint',files={'photo_document_url':photo()})
_,form=admin.form(f'/app.php/uii/edit/{complaint}');assert 'Фото ДО' in form and 'Протокол опроса' not in form
code,_,body,_=admin.send(f'/app.php/uii/edit/{complaint}',dict(cv,version=1,action='close',decision='Рассмотрено',violations='Не установлены'),{'photo_protocol':photo()});assert 'Завершено' in body and 'Фото ДО' in body and 'Фото протокола жалобы' in body and 'Протокол опроса' not in body
passed('complaint creation evidence and final DO image, no textual interrogation protocol')
# Exact 5 MiB accepted; larger image rejected; no partially saved cases/files.
size5=png+b'\0'*(5242880-len(png));large,body,_=create('request',files={'photo_document_url':photo(size5)})
meta=json.loads(query('SELECT payload FROM qa_uii_cases WHERE case_id=?',(large,))[0]['payload'])['photos'][0];assert meta['original_size']==5242880 and meta['size']<=5242880
before=len(query('SELECT case_id FROM qa_uii_cases'));files_before=len(list((SANDBOX/'site/files/uii_evidence').glob('*.bin')))
code,_,body,_=admin.send('/app.php/uii/new?kind=request',dict(rv,title='QA: oversize'),{'photo_document_url':photo(size5+b'x')});assert '5 МиБ' in body and len(query('SELECT case_id FROM qa_uii_cases'))==before and len(list((SANDBOX/'site/files/uii_evidence').glob('*.bin')))==files_before
for bad in [photo(b'<?php echo 1;?>','image.jpg','image/jpeg'),photo(b'<svg></svg>','image.png'),photo(png,'image.php'),photo(png,'image.jpg','image/jpeg')]:
 code,_,body,_=admin.send('/app.php/uii/new?kind=request',dict(rv,title='QA: invalid image'),{'photo_document_url':bad});assert len(query('SELECT case_id FROM qa_uii_cases'))==before
passed('exact 5 MiB accepted; >5 MiB, executable, SVG, fake and extension/content mismatch rejected')
# Transaction rollback also removes earlier valid files if another file fails.
code,_,body,_=admin.send('/app.php/uii/new?kind=request',dict(rv,title='QA: atomic failed batch'),{'photo_document_url':photo(),'photo_notification_url':photo(b'<svg/>','bad.png')});assert len(query('SELECT case_id FROM qa_uii_cases'))==before and len(list((SANDBOX/'site/files/uii_evidence').glob('*.bin')))==files_before
passed('multi-image failure rolls back case and removes all new files')
# No answer: preserve outcome absence while permitting overdue manager completion.
noanswer,_,nv=create('request',files={'photo_document_url':photo(),'photo_notification_url':photo()});admin.send(f'/app.php/uii/edit/{noanswer}',dict(nv,version=1,action='notify'))
now=int(time.time());mutate_case(noanswer,'UPDATE qa_uii_cases SET started_at=?,deadline_at=? WHERE case_id=?',(now-172900,now-100,noanswer));row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(noanswer,))[0]
code,_,body,_=admin.send(f'/app.php/uii/edit/{noanswer}',dict(nv,version=row['version'],action='close',completion_note='Ответ не поступил в установленный срок'));row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(noanswer,))[0];assert row['closed_at'] and row['answered_at']==0 and 'Ответ получен вовремя' not in body and 'Ответ не поступил' in body
passed('overdue completion without answer retains no-answer outcome and new label')
# Idempotency: two requests, identical ticket, one case/topic/history transaction.
fields,body=admin.form('/app.php/uii/new?kind=inspection');title='QA: duplicate '+secrets.token_hex(4);fields.update(title=title,description='Параллельная регистрация',inspection_type='плановая',action='register');time.sleep(1.1)
second=Client();second.login(account['username'],account['password'])
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:out=list(pool.map(lambda c:c.request('/app.php/uii/new?kind=inspection',fields),[admin,second]))
ids=[int(re.search(r'/uii/cases/(\d+)',result[1]).group(1)) for result in out];assert ids[0]==ids[1] and len(query('SELECT case_id FROM qa_uii_cases WHERE title=?',(title,)))==1
assert len(query('SELECT history_id FROM qa_uii_history WHERE case_id=?',(ids[0],)))==1
passed('simultaneous double submit creates exactly one case/topic/history and reuses result')
# Old data retains its URL fields and historical assignment, but no removed UI fields.
legacy,_,lv=create('request');row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(legacy,))[0]
old={'subject':'Old_Target','description':'Историческое дело','basis':'OLD BASIS','document_url':'https://example.test/old-request.png','notification_url':'https://example.test/old-notify.png','publication_url':'https://example.test/old-publication.png','comment':'Сохранить историю'}
mutate_case(legacy,'UPDATE qa_uii_cases SET payload=?,assigned_id=? WHERE case_id=?',(json.dumps(old,ensure_ascii=False),777,legacy))
code,_,body,_=admin.get(f'/app.php/uii/cases/{legacy}');assert 'old-request.png' in body and 'old-notify.png' in body and 'OLD BASIS' not in body and 'old-publication.png' not in body
code,_,body,_=admin.send(f'/app.php/uii/edit/{legacy}',dict(lv,title=lv['title'],version=row['version'],action='save',assigned_id=1234,description='Обновлено'))
updated=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(legacy,))[0];kept=json.loads(updated['payload']);assert updated['assigned_id']==777 and kept['basis']=='OLD BASIS' and kept['publication_url']==old['publication_url'] and kept['document_url']==old['document_url']
passed('legacy links remain clickable; hidden historical fields and previous assignment preserved on edit')
# Staff authorization: no foreign cards or files, no forged assignment.
suffix=''.join(chr(97+int(c,16)) for c in secrets.token_hex(3));nickname='Qatest_Vtwo'+suffix
code,_,txt,_=admin.send('/app.php/uii/employees',dict(manage=1,action='create',user_id=0,nickname=nickname,role=1,gender='male'));temporary=re.search(r'Пароль: (.+)',txt).group(1).strip();staff=Client();staff.login(nickname,temporary);password=secrets.token_hex(12);staff.send('/app.php/uii/password',dict(save=1,old_password=temporary,new_password=password,confirm_password=password));staff_id=identity(nickname)
code,_,body,_=staff.get(f'/app.php/uii/cases/{request}');assert code==403
code,_,body,_=staff.get(urllib.parse.urljoin(BASE+'/app.php/uii/cases/'+str(request),link));assert code==403
own,_,ov=create('request',client=staff,assigned_id=admin_id,files={'photo_document_url':photo()});assert query('SELECT author_id,assigned_id FROM qa_uii_cases WHERE case_id=?',(own,))[0]=={'author_id':staff_id,'assigned_id':staff_id}
row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(own,))[0];staff.send(f'/app.php/uii/edit/{own}',dict(ov,version=row['version'],action='save',assigned_id=admin_id));assert query('SELECT assigned_id FROM qa_uii_cases WHERE case_id=?',(own,))[0]['assigned_id']==staff_id
passed('staff cannot access foreign case or closed attachment; cannot assign another user on create/edit')
# CSRF and stale updates.
before=len(query('SELECT case_id FROM qa_uii_cases'));code,_,body,_=staff.request('/app.php/uii/new?kind=request',dict(save=1,action='save',title='QA: csrf',description='x',subject='X'));assert 'Срок действия' in body and len(query('SELECT case_id FROM qa_uii_cases'))==before
row=query('SELECT * FROM qa_uii_cases WHERE case_id=?',(own,))[0];code,_,body,_=staff.send(f'/app.php/uii/edit/{own}',dict(ov,version=row['version']-1,action='save'));assert 'Дело уже изменено' in body
# Broken old answer data never produces the timely badge.
mutate_case(legacy,'UPDATE qa_uii_cases SET started_at=0,deadline_at=0,answered_at=100,status=? WHERE case_id=?',('answered_on_time',legacy));code,_,body,_=admin.get(f'/app.php/uii/cases/{legacy}');assert 'Ответ получен вовремя' not in body and 'Ответ поступил вовремя' not in body
passed('CSRF, stale-version protection and no false timely-answer badge for missing/open data')
(SANDBOX/'private/cases.json').write_text(json.dumps({'request':request,'inspection':inspection,'complaint':complaint,'staff':own,'admin_id':admin_id}))
print('TOTAL:',len(results),'workflow groups passed',flush=True)
