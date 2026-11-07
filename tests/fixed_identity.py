import os,re,time,subprocess,urllib.error
from http_workflows import Client
root=Client();root.login(os.environ.get('UII_TEST_USER','Test_Admin'),os.environ['UII_TEST_PASSWORD'])
# Имена уникальны, временные пароли не пишутся на диск.
suffix=''.join(chr(97+int(c)) for c in str(int(time.time()))[-6:]);name='Qatest_G'+suffix
_,b=root.get('/app.php/uii/employees');data=root.fields(b);data.update(manage=1,action='create',user_id=0,nickname=name,position='QA - фиксированный аватар',role=1,gender='female');time.sleep(2)
_,txt=root.post('/app.php/uii/employees',data);assert 'Пароль: ' in txt
password=re.search(r'Пароль: (.+)',txt).group(1).strip();staff=Client();staff.login(name,password)
_,b=staff.get('/app.php/uii/password');d=staff.fields(b);d.update(save=1,old_password=password,new_password='Qa_Fixed!2026_Permanent',confirm_password='Qa_Fixed!2026_Permanent');time.sleep(2);staff.post('/app.php/uii/password',d)
_,before=staff.get('/app.php/uii/account');assert 'avatar-female.webp' in before and 'Женский' not in before
# Ни legacy avatar endpoint, ни отправка новых полей в readonly account не меняют назначение.
try:staff.post('/ucp.php?i=profile&mode=avatar',{'submit':1,'avatar_remote_url':'https://example.test/avatar.svg','gender':'male'});raise AssertionError('Avatar endpoint must be forbidden')
except urllib.error.HTTPError as e:assert e.code==403
staff.post('/app.php/uii/account',{'gender':'male','avatar':'https://example.test/avatar.svg'})
_,after=staff.get('/app.php/uii/account');assert 'avatar-female.webp' in after and 'Женский' not in after
_,b=root.get('/app.php/uii/employees');d=root.fields(b);d.update(manage=1,action='create',user_id=0,nickname='Qatest_Invalid',position='QA',role=1,gender='../../etc/passwd');time.sleep(2);_,b=root.post('/app.php/uii/employees',d);assert 'Выберите пол персонажа' in b
print('PASS gender persistence, fixed gender-based avatar, forbidden upload/change and invalid gender rejection')
