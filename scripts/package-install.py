#!/usr/bin/env python3
"""Fresh install kit with verified phpBB installer and Russian UII templates; no live data."""
from pathlib import Path
from datetime import datetime
import hashlib,html,importlib.util,json,shutil,tempfile,zipfile
ROOT=Path(__file__).resolve().parents[1]
VERSION=json.loads((ROOT/'app/ext/uii/portal/composer.json').read_text())['version']
spec=importlib.util.spec_from_file_location('spaceweb',ROOT/'scripts/prepare-spaceweb.py');builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
source=ROOT/'downloads/phpBB-3.3.19.zip'
if hashlib.sha256(source.read_bytes()).hexdigest()!='1530e5cabf7ecc9ecfaed3fa13da79f9e8a514623beafababc8800b5ce966de9':raise SystemExit('Контрольная сумма phpBB не совпадает')
out=ROOT/'release'/('uii-install-'+datetime.now().strftime('%Y%m%d-%H%M%S'));out.mkdir(parents=True,exist_ok=False)
with tempfile.TemporaryDirectory(prefix='uii-install-') as directory:
 stage=Path(directory)
 for path,rel in builder.code_files(ROOT/'app'):
  target=stage/rel;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(path,target)
 (stage/'config.php').write_text('')
 (stage/'.uii-fresh-install').write_text('UII fresh install '+VERSION+'\n')
 (stage/'docs').mkdir(exist_ok=True)
 guide=html.escape((ROOT/'docs/INSTALL_RU.md').read_text())
 (stage/'docs/INSTALL.html').write_text('<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Установка УИИ</title><style>body{font:16px/1.7 system-ui,sans-serif;background:#080b10;color:#e8eef8;margin:24px auto;padding:0 20px;max-width:1000px}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit}</style><h1>Установка УИИ</h1><pre>'+guide+'</pre></html>')
 with zipfile.ZipFile(source) as archive:
  for info in archive.infolist():
   prefix='phpBB3/install/'
   if not info.filename.startswith(prefix) or info.is_dir():continue
   rel=Path(info.filename[len('phpBB3/'):]);assert '..' not in rel.parts
   target=stage/rel;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(archive.read(info))
 app=stage/'install/app.php';text=app.read_text();needle="$language->add_lang(array('common', 'acp/common', 'acp/board', 'install', 'posting'));"
 assert needle in text;text=text.replace(needle,r"""$language->set_user_language('ru', true);
$uii_request = $phpbb_installer_container->get('request');
if ($uii_request->variable('lang', '', false, \phpbb\request\request_interface::COOKIE) === '' && !$uii_request->is_set_post('change_lang')) {
    $uii_request->overwrite('lang', 'ru', \phpbb\request\request_interface::COOKIE);
}
"""+needle);app.write_text(text)
 for path in (ROOT/'deployment/installer').iterdir():shutil.copyfile(path,stage/'adm/style'/path.name)
 with zipfile.ZipFile(out/'website.zip','w',zipfile.ZIP_DEFLATED) as archive:
  for path in sorted(stage.rglob('*')):
   if path.is_file():builder.add_file(archive,path,path.relative_to(stage).as_posix())
shutil.copyfile(ROOT/'scripts/php/finish-fresh-install.php',out/'finish-fresh-install.php')
for name in ['INSTALL_RU.md','ADMIN_GUIDE_RU.md','ACCESS_MATRIX.md','AUDIT_1_0.md','WORK_REPORT_1_0.md','UI_CHANGES_1_0_1.md','STATUS_IMAGES_1_0_4.md','CASES_1_1_0.md','BUG_REPORTS_1_2_0.md','GITHUB_UPDATES_RU.md','spaceweb.md','RUSSIAN_SOURCE.md']:
 shutil.copyfile(ROOT/'docs'/name,out/name)
(out/'manifest.json').write_text(json.dumps({'type':'fresh-install','version':VERSION,'phpbb':'3.3.19','contains_live_data':False,'files':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in out.iterdir() if p.is_file()}},ensure_ascii=False,indent=2))
print(out)
