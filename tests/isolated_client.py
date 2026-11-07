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
