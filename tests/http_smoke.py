"""Проверка гостевого доступа на настоящем локальном сервере."""
import urllib.request
BASE='http://127.0.0.1:8088'
for path in ['/', '/viewtopic.php?t=1', '/viewforum.php?f=2', '/memberlist.php', '/download/file.php?id=1']:
    try:
        response=urllib.request.urlopen(BASE+path, timeout=10)
        body=response.read().decode('utf-8',errors='replace')
        assert 'mode=login' in response.url or response.status in [403,404], (path,response.url)
        assert 'Welcome to phpBB3' not in body, path
        print('PASS guest restriction',path)
    except urllib.error.HTTPError as e:
        assert e.code in [403,404], (path,e.code)
        print('PASS denied',path,e.code)
