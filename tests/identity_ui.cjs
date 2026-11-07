const {chromium}=require('../runtime/browser/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch();const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8088/ucp.php?mode=login');await page.locator('#username').fill(process.env.UII_TEST_USER||'Test_Admin');await page.locator('#password').fill(process.env.UII_TEST_PASSWORD);await page.waitForTimeout(2100);await page.locator('input[name=login]').click();await page.goto('http://127.0.0.1:8088/');
 if(await page.locator('.portal-topbar,#nav-main').count())throw Error('Legacy top block');
 for(const text of ['Личные сообщения','Уведомления'])if(await page.getByRole('link',{name:text,exact:true}).count())throw Error('Disabled feature link '+text);
 const profile=await page.locator('.profile-menu').boundingBox();if(!profile||profile.x<720)throw Error('Profile must be top right');
 await page.screenshot({path:'runtime/identity-workspace.png',fullPage:true});
 await page.locator('#uii-theme').click();if(await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--bg').trim())!=='#000000')throw Error('Updated dark palette');
 await page.screenshot({path:'runtime/identity-workspace-dark.png',fullPage:true});
 await page.goto('http://127.0.0.1:8088/ucp.php?i=ucp_prefs&mode=personal');if(!page.url().includes('/uii/account'))throw Error('Old preferences must redirect');
 if(await page.getByText('Персонаж',{exact:true}).count())throw Error('Gender info exposed in account');if(await page.locator('input[type=file]').count())throw Error('Avatar upload available');if(await page.locator('.profile-statistics').count()!==1)throw Error('Simple account cards');
 const avatar=await page.locator('.account-hero img').getAttribute('src');if(!/\/avatars\/avatar-(neutral\.svg|male\.webp|female\.webp)$/.test(avatar))throw Error('Fixed default avatar');
 await page.goto('http://127.0.0.1:8088/app.php/uii/settings');if(!await page.getByRole('link',{name:'Выход в панель управления форумом',exact:false}).isVisible())throw Error('Admin settings exit');
 await page.goto('http://127.0.0.1:8088/app.php/uii/employees');await page.locator('[data-open-create]').click();if(!await page.locator('#manage select[name=gender]').isVisible())throw Error('Gender at creation');
 await page.screenshot({path:'runtime/identity-employees.png',fullPage:true});
 for(const width of [360,390,768]){
  await page.setViewportSize({width,height:844});
  for(const path of ['/','/app.php/uii/account','/app.php/uii/employees','/app.php/uii/settings']){
   await page.goto('http://127.0.0.1:8088'+path);const [scroll,size]=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);if(scroll>size)throw Error('Overflow '+width+' '+path+' '+scroll);
  }
 }
 await page.setViewportSize({width:390,height:844});await page.goto('http://127.0.0.1:8088/app.php/uii/account');await page.screenshot({path:'runtime/identity-account-mobile.png',fullPage:true});
 await page.goto('http://127.0.0.1:8088/viewtopic.php?t=1');if(await page.locator('.discussion-avatar .fixed-avatar').count()<1)throw Error('Forum post fixed avatars');
 if(errors.length)throw Error(errors.join('\n'));console.log('PASS top-right profile, removed blocks, logo, dark palette, simplified account, gender, fixed post avatars and 360/390/768px layouts');await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
