const {chromium}=require('../runtime/browser/node_modules/playwright');
const fs=require('node:fs');
(async()=>{
 const browser=await chromium.launch({headless:true});
 const base='http://127.0.0.1:8088';
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/ucp.php?mode=login');
  if(await page.locator('[name=viewonline]').count())throw Error('Hidden session checkbox still present');
  await page.screenshot({path:'runtime/login-audited.png',fullPage:true});
  await page.locator('#username').fill(process.env.UII_TEST_USER);await page.locator('#password').fill(process.env.UII_TEST_PASSWORD);await page.waitForTimeout(2100);await page.locator('[name=login]').click();
  await page.goto(base+'/app.php/uii/admin');
  if(await page.locator('.admin-settings-grid').count())throw Error('Duplicated technical menu still present');if(await page.locator('.uii-brand,.uii-acp-bar img').count())throw Error('Project logo still present');
  let sid='';
  const links=await page.locator('.admin-forum-entry a').evaluateAll(nodes=>nodes.map(n=>({href:n.href,label:n.textContent})));
  if(links.length!==1)throw Error('Expected one forum-panel entry');
  sid=new URL(links[0].href).searchParams.get('sid');
  for(const link of links){
   const target=new URL(link.href);target.searchParams.set('sid',sid);await page.goto(target.href);
   if(await page.locator('input[type=password]').count()){await page.locator('input[type=password]').fill(process.env.UII_TEST_PASSWORD);await page.waitForTimeout(2100);await page.locator('[name=login]').click();await page.waitForTimeout(3500);sid=new URL(page.url()).searchParams.get('sid')||sid;target.searchParams.set('sid',sid);await page.goto(target.href);}
   const body=await page.locator('body').innerText();
   if(/Fatal error|SQL ERROR|Общая ошибка|General Error|MODULE_NOT_ACCESS/.test(body))throw Error('Broken ACP link: '+link.label+' '+body.slice(0,2200));
   if(!await page.locator('.uii-acp-bar').count())throw Error('ACP inaccessible: '+link.label+' '+body.slice(-1000));
  }
  for(const theme of ['light','dark']){
   await page.evaluate(theme=>localStorage.setItem('uii-theme',theme),theme);
   for(const width of [360,768,1440]){
    await page.setViewportSize({width,height:960});
    for(const path of ['/app.php/uii/admin','/app.php/uii/new?kind=inspection','/app.php/uii/settings','/adm/index.php?i=board&mode=settings']){
     await page.goto(base+path+(path.startsWith('/adm/')?'&sid='+sid:''));
     const sizes=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);if(sizes[0]>sizes[1])throw Error('Overflow '+theme+' '+width+' '+path+' '+sizes);
     if(await page.locator('html').getAttribute('data-theme')!==theme)throw Error('Theme mismatch '+path);
    }
   }
  }
  await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/app.php/uii/new?kind=inspection');await page.locator('[name=inspection_type]').selectOption('внеплановая');
  const missing=await page.locator('[aria-describedby]').evaluateAll(nodes=>nodes.flatMap(n=>n.getAttribute('aria-describedby').split(/\s+/).filter(id=>!document.getElementById(id))));if(missing.length)throw Error('Missing field descriptions: '+missing);
  await page.goto(base+'/app.php/uii/admin');await page.screenshot({path:'runtime/admin-audited-dark.png',fullPage:true});
  await page.goto(base+'/adm/index.php?i=board&mode=settings&sid='+sid);await page.screenshot({path:'runtime/acp-audited-dark.png',fullPage:true});
  await page.goto(base+'/adm/index.php?i=php_info&mode=info&sid='+sid);
  const diagnostics=await page.evaluate(()=>{const keys=['Server API','opcache.enable','opcache.memory_consumption','opcache.validate_timestamps','memory_limit','upload_max_filesize','post_max_size'];const out={};for(const row of document.querySelectorAll('tr')){const cells=Array.from(row.querySelectorAll('td'));if(cells.length>1&&keys.includes(cells[0].textContent.trim()))out[cells[0].textContent.trim()]=cells.slice(1).map(c=>c.textContent.trim());}out.version=(document.body.innerText.match(/PHP Version (\d+\.\d+\.\d+)/)||[])[1];return out;});
  if(diagnostics.version)fs.writeFileSync('runtime/test-results/php-web-audit.json',JSON.stringify(diagnostics,null,2));
  if(errors.length)throw Error(errors.join('\n'));
  console.log('PASS login control removed, '+links.length+' forum-panel entry, no duplicate settings or logo, both themes, 360/768/1440px, field guidance, no JS errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
