import time
import os, urllib.request, urllib.parse, http.cookiejar, re, html
BASE=os.environ.get('UII_TEST_BASE_URL','http://127.0.0.1:8088')
class Client:
 def __init__(self):
  self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(self,path):
  u=path if path.startswith('http') else BASE+path
  r=self.opener.open(u,timeout=20)
  return r.url,r.read().decode('utf-8',errors='replace')
 def post(self,path,data):
  u=path if path.startswith('http') else BASE+path
  r=self.opener.open(u,urllib.parse.urlencode(data).encode(),timeout=20)
  self.last_headers=r.headers
  body=r.read().decode('utf-8',errors='replace')
  assert 'Fatal error' not in body and 'SQL ERROR' not in body, re.sub('<[^>]+>','',body)[:1200]
  return r.url,body
 def fields(self,body):
  values={}
  for tag in re.findall(r'<input\b[^>]*>',body):
   attrs=dict((k,html.unescape(v)) for k,v in re.findall(r'([\w-]+)="([^"]*)"',tag))
   if attrs.get('type')=='hidden' and 'name' in attrs:values[attrs['name']]=attrs.get('value','')
  return values
 def login(self,user,password):
  _,b=self.get('/ucp.php?mode=login');d=self.fields(b)
  d.update(username=user,password=password,login='Войти')
  time.sleep(2)
  _,b=self.post('/ucp.php?mode=login',d)
  assert 'id="login"' not in b and 'mode=login' not in _.lower(), 'Login did not complete'
  return b
if __name__=='__main__':
 c=Client();c.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
 for path in ['/app.php/uii/cases','/app.php/uii/new?kind=complaint','/app.php/uii/employees','/app.php/uii/tasks']:
  u,b=c.get(path)
  assert 'Fatal error' not in b and 'SQL ERROR' not in b and 'mode=login' not in u,(path,b[:900])
  print('PASS authenticated page',path)
