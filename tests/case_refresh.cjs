const {chromium}=require('../runtime/browser/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),base='http://127.0.0.1:8088';
  await page.goto(base+'/ucp.php?mode=login');await page.locator('#username').fill(process.env.UII_TEST_USER||'Test_Admin');await page.locator('#password').fill(process.env.UII_TEST_PASSWORD);await page.waitForTimeout(2100);await page.locator('input[name=login]').click();
  async function save(){await page.waitForTimeout(2100);await page.locator('button[name=save]').click();if(!/\/uii\/cases\/\d+/.test(page.url()))throw Error('Case save failed: '+await page.locator('body').innerText());}
  async function create(kind){await page.goto(base+'/app.php/uii/new?kind='+kind);await page.locator('[name=title]').fill('QA: обновление интерфейса '+kind);await page.locator('[name=subject]').fill('Test_Employee');await page.locator('[name=description]').fill('QA: проверка фото и интерфейса');await page.locator('[name=basis]').fill('QA');if(kind==='complaint')await page.locator('[name=applicant]').fill('Test_Applicant');else await page.locator('[name=inspection_type]').selectOption('плановая');await save();}
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aB1sAAAAASUVORK5CYII=','base64');
  async function photo(drop){
   if(drop){const transfer=await page.evaluateHandle(data=>{const transfer=new DataTransfer();transfer.items.add(new File([Uint8Array.from(atob(data),x=>x.charCodeAt(0))],'proof.png',{type:'image/png'}));return transfer;},png.toString('base64'));await page.locator('[data-photo-dropzone]').dispatchEvent('drop',{dataTransfer:transfer});await transfer.dispose();}
   else await page.locator('[data-photo-input]').setInputFiles({name:'proof.png',mimeType:'image/png',buffer:png});
   if(!await page.locator('[data-photo-preview]').isVisible())throw Error('File preview missing');
   await page.waitForTimeout(2100);await page.locator('[data-photo-submit]').click();
  }
  await create('complaint');if(!await page.locator('.case-people').innerText().then(text=>text.includes(process.env.UII_TEST_USER||'Test_Admin')))throw Error('Missing creator');
  for(const theme of ['light','dark']){await page.evaluate(theme=>localStorage.setItem('uii-theme',theme),theme);await page.reload();const button=page.locator('.case-actions .button-primary');await button.hover();const styles=await button.evaluate(node=>({shadow:getComputedStyle(node).textShadow,image:getComputedStyle(node).backgroundImage}));if(styles.shadow!=='none'||styles.image!=='none')throw Error('Inherited hover effect '+JSON.stringify(styles));}
  const token=await page.locator('[data-photo-form]').evaluate(form=>Object.fromEntries(new FormData(form)));
  const complaint=page.url().match(/cases\/(\d+)/)[1];await photo(true);
  if(await page.locator('.photo-grid figure').count()!==1||await page.locator('[data-photo-form]').count())throw Error('Complaint photo cap');
  const duplicate=await page.request.post(base+'/app.php/uii/photos/'+complaint,{multipart:{creation_time:token.creation_time,form_token:token.form_token,version:'2',photo_slot:'protocol',upload_photo:'1',photo:{name:'extra.png',mimeType:'image/png',buffer:png}}});if(!(await duplicate.text()).includes('Лимит фото достигнут: для жалобы - 1, для проверки - 2.'))throw Error('Server did not reject second complaint photo');
  await create('inspection');await photo(false);await page.getByRole('link',{name:'Редактировать дело',exact:true}).click();await page.locator('[name=action]').selectOption('start');await save();await photo(true);
  if(await page.locator('.photo-grid figure').count()!==2||await page.locator('[data-photo-form]').count())throw Error('Inspection photo cap');
  await page.getByRole('link',{name:'Редактировать дело',exact:true}).click();await page.getByText('Результат рассмотрения',{exact:true}).click();await page.locator('[name=decision]').fill('QA: проверка завершена');await page.locator('[name=violations]').fill('Не установлены');await page.locator('[name=action]').selectOption('close');await save();if(!await page.locator('.case-heading').innerText().then(text=>text.includes('Завершено')))throw Error('Inspection completion');
  for(const width of [360,768,1440]){await page.setViewportSize({width,height:960});for(const path of ['/app.php/uii/cases?kind=request','/app.php/uii/account','/app.php/uii/settings']){await page.goto(base+path);const dims=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);if(dims[0]>dims[1])throw Error('Overflow '+width+' '+path);}}
  console.log('PASS hover in both themes, creator, real drop/chooser, preview, server caps 1/2, two-photo completion and responsive panels');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
