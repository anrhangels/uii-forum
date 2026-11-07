const {chromium}=require('../runtime/browser/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),base='http://127.0.0.1:8088';
  await page.goto(base+'/ucp.php?mode=login');await page.locator('#username').fill(process.env.UII_TEST_USER||'Test_Admin');await page.locator('#password').fill(process.env.UII_TEST_PASSWORD);await page.waitForTimeout(2100);await page.locator('input[name=login]').click();
  await page.goto(base+'/app.php/uii/employees');await page.locator('[data-open-create]').click();
  if(await page.locator('[name=position]').count())throw Error('Unused position input');
  const suffix=String(Date.now()).slice(-5).split('').map(value=>String.fromCharCode(97+Number(value))).join(''),nickname='Qatest_Manage'+suffix;
  await page.locator('#manage [name=nickname]').fill(nickname);await page.locator('#manage [name=gender]').selectOption('male');await page.waitForTimeout(2100);
  const download=page.waitForEvent('download');await page.locator('#manage button[name=manage]').click();await download;
  await page.goto(base+'/app.php/uii/employees?q='+nickname);await page.locator('.employee-actions summary').click();
  const block=page.locator('.staff-action-card').filter({has:page.locator('[name=block_reason]')});await block.locator('[name=block_reason]').selectOption('transferred');await block.locator('[name=block_note]').fill('QA: тест перевода');await page.waitForTimeout(2100);page.once('dialog',dialog=>dialog.accept());await block.getByRole('button',{name:'Заблокировать',exact:true}).click();
  await page.locator('[name=status]').selectOption('transferred');await page.waitForURL(/status=transferred/);
  if(await page.locator('.employee-row').count()!==1||!await page.locator('.employee-row').innerText().then(text=>text.includes('Переведён из отдела')))throw Error('Transferred filter');
  await page.locator('.employee-actions summary').click();await page.waitForTimeout(2100);page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Восстановить доступ',exact:true}).click();await page.goto(base+'/app.php/uii/employees?status=active&q='+nickname);if(await page.locator('.employee-row').count()!==1)throw Error('Restored staff');
  await page.goto(base+'/app.php/uii/navigation');if(await page.locator('.navigation-group').count()>=8){page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Стандартное меню',exact:true}).click();}const count=await page.locator('.navigation-group').count();await page.getByRole('button',{name:'Добавить группу',exact:true}).click();if(await page.locator('.navigation-group').count()!==count+1)throw Error('New group');
  const group=page.locator('.navigation-group').last();await group.locator('input').fill('QA меню');const target=await group.getAttribute('data-group-id');const row=page.locator('[data-item-id=tasks]');await row.locator('select').selectOption(target);if(!await page.locator('[data-group-id="'+target+'"] [data-item-id=tasks]').count())throw Error('Move navigation item');
  const payload=await page.locator('#navigation-layout').inputValue();if(!JSON.parse(payload).find(group=>group.id===target).items.includes('tasks'))throw Error('Unsaved layout payload');
  // The global menu is deliberately not saved by this visual test.
  for(const width of [360,768,1440]){await page.setViewportSize({width,height:960});for(const path of ['/app.php/uii/employees','/app.php/uii/navigation','/app.php/uii/admin']){await page.goto(base+path);const dims=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);if(dims[0]>dims[1])throw Error('Overflow '+width+' '+path);}}
  console.log('PASS staff creation without position, explicit actions, transferred filter, restoration, navigation groups and responsive management');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
