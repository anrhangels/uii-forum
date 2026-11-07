#!/usr/bin/env python3
"""Disk inventory and isolated CLI measurements; never a production load benchmark."""
from pathlib import Path
import datetime
import json
import subprocess
import sys
import zipfile

root=Path(__file__).resolve().parents[1]
metrics={'measured_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'directories':{},'assets':{},'cli_tests':[]}
for name in ['.','app','app/ext/uii/portal','app/styles/uii_modern','app/language/ru','app/vendor','app/cache','app/files','runtime','runtime/browsers','runtime/browser','runtime/usr','downloads','snapshots','backups','tests','docs']:
    values=[];complete=True
    for flags in [['-sb'],['-s','-B1']]:
        result=subprocess.run(['du',*flags,str(root/name)],capture_output=True,text=True)
        if not result.stdout: raise RuntimeError('No disk inventory for '+name)
        values.append(int(result.stdout.split()[0]));complete=complete and result.returncode==0
    metrics['directories'][name]={'logical_bytes':values[0],'allocated_bytes':values[1],'complete':complete}
for name in ['app/styles/uii_modern/theme/portal.css','app/styles/uii_modern/theme/portal.js','app/styles/uii_modern/theme/navigation.js','app/styles/uii_modern/theme/brand.svg','app/ext/uii/portal/styles/all/template/visuals.html','app/ext/uii/portal/styles/all/theme/avatars/avatar-male.webp','app/ext/uii/portal/styles/all/theme/avatars/avatar-female.webp']:
    metrics['assets'][name]=(root/name).stat().st_size
archive=root/'downloads/phpBB-3.3.19.zip'
with zipfile.ZipFile(archive) as package:
    files=[info for info in package.infolist() if info.filename.startswith('phpBB3/') and not info.is_dir()]
    metrics['official_archive']={'compressed_bytes':archive.stat().st_size,'files':len(files),'uncompressed_bytes':sum(info.file_size for info in files),'installed_baseline_bytes':sum(info.file_size for info in files if not info.filename.startswith('phpBB3/install/') and info.filename!='phpBB3/config.php')}
metrics['local_php_version']=subprocess.check_output(['bash','scripts/php-local.sh','-r','echo PHP_VERSION;'],cwd=root,text=True).strip()
worker='''import subprocess,resource,time,json,sys
start=time.perf_counter()
result=subprocess.run(['bash','scripts/php-local.sh',sys.argv[1]],capture_output=True,text=True,check=True)
usage=resource.getrusage(resource.RUSAGE_CHILDREN)
print(json.dumps({'max_rss_kib':usage.ru_maxrss,'elapsed_seconds':time.perf_counter()-start,'user_seconds':usage.ru_utime,'system_seconds':usage.ru_stime}))
'''
for test in ['workflow','evidence','management']:
    result=json.loads(subprocess.check_output([sys.executable,'-c',worker,f'app/ext/uii/portal/tests/{test}.php'],cwd=root,text=True))
    metrics['cli_tests'].append({'name':test,**result})
metrics['core_integrity']=subprocess.check_output(['python3','tests/core_integrity.py'],cwd=root,text=True).strip()
releases=sorted((root/'runtime').glob('uii-portal-code-*.tar.gz'),key=lambda p:p.stat().st_mtime)
if releases:
    latest=releases[-1]
    with __import__('tarfile').open(latest) as package:
        metrics['latest_code_archive']={'name':latest.name,'compressed_bytes':latest.stat().st_size,'uncompressed_file_bytes':sum(member.size for member in package.getmembers() if member.isfile())}
output=root/'runtime/report-metrics.json';output.write_text(json.dumps(metrics,ensure_ascii=False,indent=2))
print(output)
