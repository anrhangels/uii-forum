const {chromium}=require('../runtime/browser/node_modules/playwright');
const fs=require('fs');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const base='http://127.0.0.1:8094',account=JSON.parse(fs.readFileSync('runtime/cases-v2-sandbox/private/account.json')),ids=JSON.parse(fs.readFileSync('runtime/cases-v2-sandbox/private/cases.json'));
  const page=await browser.newPage({viewport:{width:1440,height:1000}});
  await page.goto(base+'/ucp.php?mode=login');await page.locator('#username').fill(account.username);await page.locator('#password').fill(account.password);await page.waitForTimeout(1200);await page.locator('input[name=login]').click();
  const png=require('child_process').execFileSync('bash',['scripts/php-local.sh','-d','extension='+process.cwd()+'/runtime/usr/lib/php/modules/gd.so','-r','$i=imagecreatetruecolor(64,48);imagepng($i);']);
  for (const width of [360,768,1440]) {
   await page.setViewportSize({width,height:960});
   for(const kind of ['complaint','request','inspection']) {
    await page.goto(base+'/app.php/uii/new?kind='+kind);
    if(await page.locator('[data-case-file-remove]:visible').count())throw Error('Empty file remove button visible');
    if(await page.locator('[name=basis],[name=assigned_id],[name=publication_url],[name=answer_url]').count())throw Error('Unexpected form field');
    if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('Overflow '+width+' '+kind);
    await page.screenshot({path:`runtime/test-results/cases-v2-${kind}-${width}.png`,fullPage:true});
   }
  }
  const input=page.locator('[name=photo_start_url]'),preview=page.locator('[data-case-file-field]').first();
  await input.setInputFiles({name:'proof.png',mimeType:'image/png',buffer:png});
  if(!await preview.locator('[data-case-file-preview]').isVisible())throw Error('Preview not visible');
  await preview.locator('[data-case-file-remove]').click();
  if(await input.evaluate(el=>el.files.length)!==0||await preview.locator('[data-case-file-preview]').isVisible())throw Error('Remove failed');
  await input.setInputFiles({name:'large.png',mimeType:'image/png',buffer:Buffer.alloc(5242881)});
  if(!await preview.locator('[data-case-file-error]').isVisible())throw Error('Size not validated');
  await input.setInputFiles({name:'replacement.png',mimeType:'image/png',buffer:png});
  if(!await preview.locator('[data-case-file-preview]').isVisible()||await preview.locator('[data-case-file-error]').isVisible())throw Error('Replace failed');
  const transfer=await page.evaluateHandle(data=>{const dt=new DataTransfer();dt.items.add(new File([Uint8Array.from(atob(data),c=>c.charCodeAt(0))],'drop.png',{type:'image/png'}));return dt;},png.toString('base64'));
  await preview.locator('[data-case-dropzone]').dispatchEvent('drop',{dataTransfer:transfer});await transfer.dispose();
  if(!await preview.locator('[data-case-file-name]').innerText().then(t=>t.includes('drop.png')))throw Error('Drop failed');
  await page.locator('[name=title]').fill('QA v2 browser inspection '+Date.now());await page.locator('[name=description]').fill('Browser real multipart submission');await page.waitForTimeout(1200);await page.getByRole('button',{name:'Зарегистрировать проверку',exact:true}).click();
  await page.waitForURL(/\/cases\/\d+/);
  if(await page.locator('.case-heading').innerText().then(t=>t.includes('Запланирована')))throw Error('Decorative badge');
  if(await page.locator('img[src*="/photos/"]').count())throw Error('Saved image embedded');
  if(!await page.getByRole('link',{name:'Открыть изображение',exact:true}).count())throw Error('Saved link missing');
  for(const kind of ['request','complaint','inspection']){await page.goto(base+'/app.php/uii/cases/'+ids[kind]);if(await page.locator('img[src*="/photos/"]').count())throw Error('Saved image embedded');if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('Card overflow');}
  console.log('PASS 9 responsive forms, file preview/remove/replace/drop, size validation, real registration, saved links, completed cards');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
