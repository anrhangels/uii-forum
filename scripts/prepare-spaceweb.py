#!/usr/bin/env python3
"""Build a reviewable SpaceWeb transfer kit. Live data is opt-in and never substituted by old dumps."""
import argparse
from datetime import datetime, timezone
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import tempfile
import zipfile

ROOT=Path(__file__).resolve().parents[1]
GUARDS={'.htaccess','index.htm'}

def domain(value):
    if value is None:return None
    try: value=value.strip().lower().encode('idna').decode('ascii')
    except UnicodeError:raise ValueError('Некорректный домен')
    if not re.fullmatch(r'(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?',value):raise ValueError('Укажите домен без https://, порта и пути')
    return value

def prefix():
    text=(ROOT/'app/config.php').read_text()
    match=re.search(r"\$table_prefix\s*=\s*['\"]([a-zA-Z0-9_]+)['\"]\s*;",text)
    if not match:raise ValueError('Не удалось определить безопасный префикс таблиц')
    return match[1]

def code_files(app):
    for current,dirs,files in os.walk(app,followlinks=False):
        base=Path(current);relative=base.relative_to(app);parts=relative.parts
        dirs[:]=[name for name in dirs if not (base/name).is_symlink()]
        if not parts:
            dirs[:]=[name for name in dirs if name not in {'install','cache','files','store'}]
        if parts==('images','avatars'):dirs[:]=[name for name in dirs if name!='upload']
        if parts==('ext','uii','portal'):dirs[:]=[name for name in dirs if name!='tests']
        if parts and parts[0]=='docs':
            dirs[:]=[];files=[name for name in files if name in {'LICENSE.txt','CREDITS.txt'}]
        for name in sorted(files):
            path=base/name;rel=path.relative_to(app)
            if path.is_symlink() or rel.as_posix()=='config.php':continue
            yield path,rel.as_posix()
    for folder in ['cache','files','store','images/avatars/upload']:
        for name in GUARDS:
            path=app/folder/name
            if path.is_file():yield path,(Path(folder)/name).as_posix()

def add_file(archive,source,name,mode=0o644):
    info=zipfile.ZipInfo(name);info.compress_type=zipfile.ZIP_DEFLATED
    info.external_attr=(stat.S_IFREG|mode)<<16
    with source.open('rb') as src,archive.open(info,'w',force_zip64=True) as dst:shutil.copyfileobj(src,dst,1024*1024)

def add_bytes(archive,contents,name,mode=0o644):
    info=zipfile.ZipInfo(name);info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=(stat.S_IFREG|mode)<<16
    archive.writestr(info,contents)

def walk_uploads(folder):
    if not folder.exists():return
    def fail(error):raise error
    for current,dirs,files in os.walk(folder,followlinks=False,onerror=fail):
        base=Path(current);dirs[:]=[name for name in dirs if not (base/name).is_symlink()]
        for name in files:
            path=base/name
            if not path.is_symlink():yield path

def settings_sql(table_prefix,host,cookie):
    target=host or 'REPLACE_WITH_DOMAIN'
    values={'server_name':target,'server_protocol':'https://','server_port':'443','script_path':'','force_server_vars':'1','cookie_domain':'','cookie_path':'/','cookie_secure':'1','cookie_name':cookie,'board_disable':'1','board_disable_msg':'Проверка портала после переноса','email_enable':'0','allow_cdn':'0','require_activation':'3'}
    lines=['-- Выполнить ПОСЛЕ импорта database.sql.gz в НОВОЙ базе SpaceWeb.', '-- Замените REPLACE_WITH_DOMAIN реальным доменом без https://.', 'SET NAMES utf8mb4;', 'START TRANSACTION;']
    for name,value in values.items():lines.append(f"UPDATE `{table_prefix}config` SET config_value = '{value}' WHERE config_name = '{name}';")
    lines.extend([f"UPDATE `{table_prefix}users` SET uii_blocked = 1, uii_block_reason = 'other', uii_block_note = 'Тестовая учётная запись: доступ закрыт при переносе' WHERE LEFT(username,7) = 'Qatest_' AND user_type <> 3;",f'DELETE FROM `{table_prefix}sessions`;',f'DELETE FROM `{table_prefix}sessions_keys`;','COMMIT;','-- Форум оставлен выключенным. После проверки включите его в ACP.'])
    return '\n'.join(lines)+'\n'

def portable_sql(source,target):
    ddl=False
    for line in source:
        if b'enable the sandbox mode' in line and line.startswith(b'/*M!'):continue
        if line.startswith(b'CREATE TABLE '):ddl=True
        if ddl:
            line=re.sub(rb'(?i)(CHARSET=|CHARACTER SET\s+)utf8mb3\b',rb'\1utf8',line)
            line=re.sub(rb'(?i)(COLLATE[=\s]+)utf8mb3_',rb'\1utf8_',line)
        target.write(line)
        if ddl and line.startswith(b')'):ddl=False

def build(output,host=None,full=False,database=None):
    host=domain(host);output=output.resolve()
    if output.exists():raise ValueError('Каталог результата уже существует: выберите новый путь')
    if full and (database is None or not database.is_file() or database.stat().st_size==0):raise ValueError('Для полного пакета нужен свежий SQL из scripts/prepare-spaceweb.sh')
    output.parent.mkdir(parents=True,exist_ok=True)
    table_prefix=prefix();app=ROOT/'app';photo_count=0;uploaded_count=0
    with tempfile.TemporaryDirectory(prefix='.spaceweb-building-',dir=output.parent) as temporary:
        stage=Path(temporary);stage.chmod(0o700)
        with zipfile.ZipFile(stage/'website.zip','w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
            for source,name in code_files(app):add_file(archive,source,name)
            config=(ROOT/'deployment/spaceweb/config.php.template').read_text().replace('__TABLE_PREFIX__',table_prefix)
            add_bytes(archive,config.encode(),'config.php',0o600)
            add_bytes(archive,b'UII SpaceWeb deployment lock\n','.uii-deploy.lock',0o600)
            add_bytes(archive,b'; CGI/FPM only; verify effective limits in the hosting panel.\nupload_max_filesize=2M\npost_max_size=8M\ndisplay_errors=Off\nlog_errors=On\n','.user.ini')
            if full:
                for folder in ['files','images/avatars/upload']:
                    for source in walk_uploads(app/folder):
                        relative=source.relative_to(app)
                        if relative.parts[:2]==('files','uii_evidence') or source.name in GUARDS:continue
                        add_file(archive,source,relative.as_posix());uploaded_count+=1
        with zipfile.ZipFile(stage/'private-files.zip','w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
            add_bytes(archive,b'Require all denied\n','uii-private/.htaccess',0o640)
            add_bytes(archive,b'Require all denied\n','uii-private/evidence/.htaccess',0o640)
            if full:
                for source in walk_uploads(app/'files/uii_evidence'):
                    if not re.fullmatch(r'[a-f0-9]{32}\.bin',source.name):continue
                    add_file(archive,source,'uii-private/evidence/'+source.name,0o640);photo_count+=1
        if full:
            # MariaDB's client sandbox directive is not understood by some MySQL import clients.
            with database.open('rb') as source,gzip.open(stage/'database.sql.gz','wb',compresslevel=6) as target:
                portable_sql(source,target)
        (stage/'hosting-settings.sql').write_text(settings_sql(table_prefix,host,'uii_sweb_'+os.urandom(6).hex()))
        tools=stage/'private-tools';tools.mkdir()
        for name in ['setup-spaceweb.php','hosting.php']:shutil.copyfile(ROOT/'deployment/spaceweb'/name,tools/name)
        shutil.copyfile(ROOT/'docs/spaceweb.md',stage/'START_HERE.md')
        if not full:(stage/'DATA_REQUIRED.txt').write_text('Это пакет КОДА, без текущей базы и фото. Полный перенос: sudo bash scripts/prepare-spaceweb.sh\nНе используйте старые SQL-снимки вместо свежего экспорта.\n')
        manifest={'provider':'SpaceWeb','created_at':datetime.now(timezone.utc).isoformat(),'code_version':json.loads((app/'ext/uii/portal/composer.json').read_text())['version'],'domain':host,'table_prefix':table_prefix,'contains_database':full,'contains_uploads':full,'complete_for_migration':full,'photo_files':photo_count,'legacy_uploaded_files':uploaded_count,'evidence_path':'../uii-private/evidence','photo_quota_bytes':524288000,'artifacts':{}}
        for path in stage.rglob('*'):
            if path.is_file():
                path.chmod(0o600);digest=hashlib.sha256()
                with path.open('rb') as source:
                    for block in iter(lambda:source.read(1024*1024),b''):digest.update(block)
                manifest['artifacts'][path.relative_to(stage).as_posix()]={'bytes':path.stat().st_size,'sha256':digest.hexdigest()}
        (stage/'deployment.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2));(stage/'deployment.json').chmod(0o600)
        stage.rename(output)
    return output

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--output',type=Path);parser.add_argument('--domain');parser.add_argument('--full',action='store_true');parser.add_argument('--database',type=Path)
    args=parser.parse_args()
    try:
        result=build(args.output or ROOT/'release'/('spaceweb-code-'+datetime.now().strftime('%Y%m%d-%H%M%S')),args.domain,args.full,args.database)
    except (ValueError,OSError) as error:parser.exit(1,str(error)+'\n')
    print(result)
