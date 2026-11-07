"""Real native installer page in an isolated directory; never connects to the live database."""
from pathlib import Path
import os,socket,subprocess,tempfile,time,urllib.request,zipfile,json
root=Path(__file__).resolve().parents[1]
kit=sorted((root/'release').glob('uii-install-*'))[-1]
with tempfile.TemporaryDirectory(prefix='uii-installer-test-') as directory:
 stage=Path(directory)
 with zipfile.ZipFile(kit/'website.zip') as archive:
  names=set(archive.namelist());assert archive.read('config.php')==b'' and '.uii-fresh-install' in names
  assert not any((name.endswith('.sql') and not name.startswith('install/schemas/')) or 'uii_evidence/' in name or name.startswith('ext/uii/portal/tests/') for name in names)
  archive.extractall(stage)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 with open(root/'runtime/test-results/installer-server.log','w') as log:
  server=subprocess.Popen(['bash',str(root/'scripts/php-local.sh'),'-S',f'127.0.0.1:{port}','-t',str(stage)],stdout=log,stderr=log)
  try:
   url=f'http://127.0.0.1:{port}/install/app.php'
   for _ in range(50):
    try:
     with urllib.request.urlopen(url,timeout=3) as response:body=response.read().decode()
     break
    except (OSError,urllib.error.URLError):time.sleep(.1)
   else:raise RuntimeError('Isolated installer did not start')
   assert 'Установка рабочего портала' in body and 'lang="ru"' in body and 'uii-installer.css' in body,body[:500]
   js='''const {chromium}=require(process.env.UII_PLAYWRIGHT);(async()=>{const browser=await chromium.launch({headless:true});try{const page=await browser.newPage();for(const path of ['', '/install'])for(const theme of ['light','dark']){await page.goto(process.env.UII_INSTALLER_URL+path);await page.evaluate(t=>localStorage.setItem('uii-theme',t),theme);for(const width of [360,768,1440]){await page.setViewportSize({width,height:960});await page.reload();if(await page.locator('#language').count()&&await page.locator('#language').inputValue()!=='ru')throw Error('Wrong initial selected language');const assets=await page.evaluate(()=>({bg:getComputedStyle(document.documentElement).getPropertyValue('--a-bg'),bad:Array.from(document.images).filter(x=>!x.complete||!x.naturalWidth).map(x=>x.src)}));if(!assets.bg||assets.bad.length)throw Error('Broken installer assets '+JSON.stringify(assets));const size=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);if(size[0]>size[1])throw Error('Installer overflow '+width+' '+size);if(await page.locator('html').getAttribute('data-theme')!==theme)throw Error('Installer theme');if(width===1440)await page.screenshot({path:process.env.UII_SCREENSHOT+'-'+theme+'.png',fullPage:true});}}console.log('PASS actual Russian native installer, both themes, 360/768/1440px');}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});'''
   env=os.environ.copy();env.update(UII_PLAYWRIGHT=str(root/'runtime/browser/node_modules/playwright'),PLAYWRIGHT_BROWSERS_PATH=str(root/'runtime/browsers'),UII_INSTALLER_URL=url,UII_SCREENSHOT=str(root/'runtime/installer-audited'))
   subprocess.run(['node','-e',js],env=env,check=True)
   assert (stage/'config.php').read_bytes()==b'', 'Preview must not install anything'
  finally:server.terminate();server.wait(timeout=5)
print('PASS fresh-install package without live data, native installer startup and isolated preview')
